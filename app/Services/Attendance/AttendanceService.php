<?php

namespace App\Services\Attendance;

use App\Models\Attendance;
use App\Models\AttendanceCorrectionLog;
use App\Models\AttendanceSetting;
use App\Models\User;
use App\Models\UserDevice;
use App\Models\WorkSchedule;
use Exception;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

// Alias untuk QrCode Model

class AttendanceService
{
    public const string STATUS_CLOCK_IN_ON_TIME = 'On Time';
    public const string STATUS_CLOCK_IN_LATE = 'Late';
    public const string STATUS_CLOCK_OUT_ON_TIME = 'Finished On Time';
    public const string STATUS_CLOCK_OUT_EARLY = 'Left Early';
    public const string STATUS_CLOCK_OUT_OVERTIME = 'Overtime';

    public function __construct(protected readonly QrCodeService $qrCodeService, protected readonly WorkScheduleService $workScheduleService)
    {
    }

    public function processScan(User $user, array $qrPayload, string $clientTimestamp, ?array $locationData, ?string $deviceIdentifier): array
    {
        $serverNow = Carbon::parse($clientTimestamp)->setTimezone(config('app.timezone', 'UTC'));
        $workDate = $this->determineWorkDate($serverNow, $user);

        $qrCodeRecord = $this->qrCodeService->validateQrCodePayloadForWorkDate($qrPayload, $workDate);
        if (!$qrCodeRecord) {
            Log::warning("QR Code validation failed for user {$user->id} on effective work date {$workDate->toDateString()}.", ['qr_payload' => $qrPayload]);
            throw ValidationException::withMessages(['qr_code' => 'QR Code is invalid or not applicable for the current/determined work date.']);
        }

        if (AttendanceSetting::getByKey('enable_gps_validation', false) && $locationData) {
            $this->validateGpsLocation($locationData);
        }

        $activeSchedule = $this->workScheduleService->getUserActiveScheduleForDate($user, $workDate);

        $denormalizedScheduleInfo = [];
        if ($activeSchedule) {
            $denormalizedScheduleInfo = ['scheduled_start_time' => $activeSchedule->start_time, 'scheduled_end_time' => $activeSchedule->end_time, // Menyimpan durasi kerja BERSIH yang dijadwalkan
                'scheduled_work_duration_minutes' => $activeSchedule->scheduled_net_work_minutes,];
        } else {
            Log::warning("No active or default work schedule found for user {$user->id} on {$workDate->toDateString()}.");
            throw ValidationException::withMessages(['schedule' => 'No active work schedule found for this date.']);
        }

        $deviceId = $this->validateAndGetDeviceId($user, $deviceIdentifier);

        $attendanceResult = DB::transaction(function () use ($user, $serverNow, $workDate, $activeSchedule, $locationData, $deviceId, $qrCodeRecord, $denormalizedScheduleInfo) {
            $attendance = Attendance::firstOrNew(['user_id' => $user->id, 'work_date' => $workDate->toDateString()]);
            $message = '';

            if (is_null($attendance->clock_in_at)) {
                $attendance->fill($denormalizedScheduleInfo);
                $attendance->work_schedule_id = $activeSchedule?->id;
                $attendance->clock_in_at = $serverNow;
                $attendance->clock_in_notes = "Clocked in via QR Code.";
                if ($locationData) {
                    $attendance->clock_in_latitude = $locationData['latitude'];
                    $attendance->clock_in_longitude = $locationData['longitude'];
                }
                $attendance->clock_in_device_id = $deviceId;
                $attendance->clock_in_qr_code_id = $qrCodeRecord->id;
                $attendance->clock_in_method = 'qr_scan';

                $this->calculateInitialClockInMetrics($attendance, $activeSchedule);
                $message = "Clocked in successfully at {$serverNow->format('H:i:s')}. Status: {$attendance->clock_in_status}.";
                if ($attendance->lateness_minutes > 0) {
                    $message .= " Late by: {$attendance->lateness_minutes} minutes.";
                }
            } elseif (is_null($attendance->clock_out_at)) {
                // ... (logika clock out tetap sama, namun pastikan calculateWorkDurationsAndStatus menggunakan scheduled_net_work_minutes dari $activeSchedule)
                $minDurationBeforeClockOut = (int)AttendanceSetting::getByKey('min_duration_before_clock_out_minutes', 60);
                if ($attendance->clock_in_at->diffInMinutes($serverNow) < $minDurationBeforeClockOut) {
                    throw ValidationException::withMessages(['clock_out_scan' => "You cannot clock out yet. Minimum work duration: {$minDurationBeforeClockOut} minutes."]);
                }

                $attendance->clock_out_at = $serverNow;
                $attendance->clock_out_notes = "Clocked out via QR Code.";
                if ($locationData) {
                    $attendance->clock_out_latitude = $locationData['latitude'];
                    $attendance->clock_out_longitude = $locationData['longitude'];
                }
                $attendance->clock_out_device_id = $deviceId;
                $attendance->clock_out_qr_code_id = $qrCodeRecord->id;
                $attendance->clock_out_method = 'qr_scan';

                $this->calculateWorkDurationsAndStatus($attendance, $activeSchedule); // Ini akan menggunakan accessor

                $message = "Clocked out successfully at {$serverNow->format('H:i:s')}. Status: {$attendance->clock_out_status}. Work duration: " . $attendance->work_duration_formatted;
                if ($attendance->overtime_minutes > 0) $message .= " Overtime: {$attendance->overtime_minutes} minutes.";
                if ($attendance->early_leave_minutes > 0) $message .= " Left early by: {$attendance->early_leave_minutes} minutes.";

            } else {
                Log::info("User {$user->id} performed an extra scan on {$attendance->work_date->toDateString()}. Clock-in: {$attendance->clock_in_at}, Clock-out: {$attendance->clock_out_at}.");
                throw ValidationException::withMessages(['attendance' => 'You have already clocked in and out for this work date.']);
            }
            $attendance->save();
            return ['attendance' => $attendance, 'message' => $message];
        });
        return $attendanceResult;
    }

    protected function determineWorkDate(Carbon $scanTime, User $user): Carbon
    {
        $yesterdayWorkDate = $scanTime->copy()->subDay()->startOfDay();
        $yesterdayAttendance = Attendance::where('user_id', $user->id)->where('work_date', $yesterdayWorkDate->toDateString())->notClockedOut()->first();

        if ($yesterdayAttendance?->workSchedule?->crosses_midnight) {
            $schedule = $yesterdayAttendance->workSchedule;
            // Perlu parse tanggal kerja + jam akhir, karena jam akhir bisa setelah tengah malam
            $scheduledEndTimeAbsolute = Carbon::parse($yesterdayAttendance->work_date->toDateString() . ' ' . $schedule->end_time);
            if ($schedule->crosses_midnight && $scheduledEndTimeAbsolute->lt(Carbon::parse($yesterdayAttendance->work_date->toDateString() . ' ' . $schedule->start_time))) {
                $scheduledEndTimeAbsolute->addDay();
            }

            $shiftEndWindow = $scheduledEndTimeAbsolute->copy()->addHours((int)AttendanceSetting::getByKey('night_shift_clock_out_buffer_hours', 3));

            if ($scanTime->lte($shiftEndWindow)) {
                return $yesterdayWorkDate;
            }
        }
        return $scanTime->copy()->startOfDay();
    }

    protected function validateGpsLocation(array $locationData): void
    {
        $officeLatitude = AttendanceSetting::getByKey('office_latitude');
        $officeLongitude = AttendanceSetting::getByKey('office_longitude');
        $gpsRadiusMeters = (int)AttendanceSetting::getByKey('gps_radius_meters', 100);

        if ($officeLatitude && $officeLongitude) {
            $distance = $this->calculateDistance($locationData['latitude'], $locationData['longitude'], (float)$officeLatitude, (float)$officeLongitude);
            if ($distance > $gpsRadiusMeters) {
                $userId = Auth::id() ?? 'N/A';
                Log::warning("Attendance rejected for user {$userId}: outside GPS radius. Distance: {$distance}m");
                throw ValidationException::withMessages(['location' => "You are outside the allowed area for attendance (Distance: " . round($distance) . "m)."]);
            }
        } else {
            Log::warning("Office GPS settings are incomplete, GPS validation skipped.");
        }
    }

    protected function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000; // meter
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) * sin($dLat / 2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) * sin($dLon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadius * $c;
    }

    protected function validateAndGetDeviceId(User $user, ?string $deviceIdentifier): ?int
    {
        $deviceId = null;
        if ($deviceIdentifier) {
            $device = UserDevice::where('device_identifier', $deviceIdentifier)->where('user_id', $user->id)->where('status', UserDevice::STATUS_APPROVED)->first();
            if ($device) {
                $deviceId = $device->id;
            } elseif (AttendanceSetting::getByKey('enforce_approved_device_for_attendance', false)) {
                throw ValidationException::withMessages(['device' => 'Attendance from this device is not permitted.']);
            } else {
                Log::warning("Attendance from non-approved/unknown device for user {$user->id}, device_identifier: {$deviceIdentifier}");
            }
        } elseif (AttendanceSetting::getByKey('enforce_approved_device_for_attendance', false)) {
            throw ValidationException::withMessages(['device' => 'Device identifier is required for attendance.']);
        }
        return $deviceId;
    }

    /**
     * Menghitung metrik awal saat clock-in.
     */
    protected function calculateInitialClockInMetrics(Attendance $attendance, ?WorkSchedule $schedule): void
    {
        if (!$schedule || !$attendance->clock_in_at) {
            $attendance->clock_in_status = self::STATUS_CLOCK_IN_ON_TIME;
            $attendance->lateness_minutes = 0;
            return;
        }

        $clockInTime = $attendance->clock_in_at;
        // Waktu mulai terjadwal pada tanggal kerja absensi
        $scheduledStartTime = Carbon::parse($attendance->work_date->toDateString() . ' ' . $schedule->start_time);

        $gracePeriodLate = $schedule->grace_period_late_minutes ?? (int)AttendanceSetting::getByKey('late_tolerance_minutes', 0);
        $effectiveScheduledStart = $scheduledStartTime->copy()->addMinutes($gracePeriodLate);

        if ($clockInTime->gt($effectiveScheduledStart)) {
            $attendance->clock_in_status = self::STATUS_CLOCK_IN_LATE;
            // Hitung keterlambatan dari effectiveScheduledStart, bukan scheduledStartTime mentah
            $attendance->lateness_minutes = $effectiveScheduledStart->diffInMinutes($clockInTime);
        } else {
            $attendance->clock_in_status = self::STATUS_CLOCK_IN_ON_TIME;
            $attendance->lateness_minutes = 0;
        }
    }

    /**
     * Menghitung semua durasi kerja, status pulang, lembur, dan pulang cepat.
     */
    protected function calculateWorkDurationsAndStatus(Attendance $attendance, ?WorkSchedule $schedule): void
    {
        if (!$attendance->clock_in_at || !$attendance->clock_out_at) {
            // ... (handling jika tidak ada clock in/out tetap sama)
            $attendance->work_duration_minutes = null;
            $attendance->effective_work_minutes = null;
            $attendance->overtime_minutes = 0;
            $attendance->early_leave_minutes = 0;
            $attendance->clock_out_status = null;
            return;
        }

        $clockIn = $attendance->clock_in_at;
        $clockOut = $attendance->clock_out_at;

        $attendance->work_duration_minutes = $clockIn->diffInMinutes($clockOut);
        $breakMinutesFromSchedule = $schedule?->break_duration_minutes ?? 0;
        $attendance->effective_work_minutes = max(0, $attendance->work_duration_minutes - $breakMinutesFromSchedule);

        $attendance->early_leave_minutes = 0;
        $attendance->overtime_minutes = 0;
        $attendance->clock_out_status = self::STATUS_CLOCK_OUT_ON_TIME;

        if ($schedule) {
            $scheduledEndTime = Carbon::parse($attendance->work_date->toDateString() . ' ' . $schedule->end_time);
            if ($schedule->crosses_midnight && $scheduledEndTime->lt(Carbon::parse($attendance->work_date->toDateString() . ' ' . $schedule->start_time))) {
                $scheduledEndTime->addDay();
            }

            $graceEarly = $schedule->grace_period_early_leave_minutes ?? (int)AttendanceSetting::getByKey('early_leave_tolerance_minutes', 0);
            $effectiveScheduledEnd = $scheduledEndTime->copy()->subMinutes($graceEarly);

            if ($clockOut->lt($effectiveScheduledEnd)) {
                $attendance->early_leave_minutes = $effectiveScheduledEnd->diffInMinutes($clockOut);
                $attendance->clock_out_status = self::STATUS_CLOCK_OUT_EARLY;
            } else {
                // Gunakan accessor untuk durasi kerja bersih yang dijadwalkan
                $scheduledNetWorkMinutes = $schedule->scheduled_net_work_minutes;

                if ($attendance->effective_work_minutes > $scheduledNetWorkMinutes) {
                    $rawOvertime = $attendance->effective_work_minutes - $scheduledNetWorkMinutes;
                    $minOvertimeThreshold = (int)AttendanceSetting::getByKey('min_overtime_threshold_minutes', 0);
                    if ($rawOvertime >= $minOvertimeThreshold) {
                        $attendance->overtime_minutes = $rawOvertime;
                        $attendance->clock_out_status = self::STATUS_CLOCK_OUT_OVERTIME;
                    }
                }
            }
        }
    }


    public function getAttendanceHistoryForUser(User $user, int $perPage = 15, ?string $startDate = null, ?string $endDate = null): LengthAwarePaginator
    {
        $query = $user->attendanceRecords()->with(['workSchedule:id,name,start_time,end_time', 'clockInDevice:id,name', 'clockOutDevice:id,name']); // Tambah field schedule

        if ($startDate && $endDate) {
            try {
                $parsedStart = Carbon::parse($startDate)->startOfDay();
                $parsedEnd = Carbon::parse($endDate)->endOfDay();
                $query->whereBetween('work_date', [$parsedStart, $parsedEnd]);
            } catch (Exception $e) {
                Log::error("Invalid date format for attendance history filter: {$e->getMessage()}");
                // Mungkin tidak melempar error, tapi biarkan query tanpa filter tanggal jika format salah
            }
        } elseif ($startDate) {
            try {
                $parsedStart = Carbon::parse($startDate)->startOfDay();
                $query->where('work_date', '>=', $parsedStart);
            } catch (Exception $e) { /* abaikan tanggal tidak valid */
            }
        } elseif ($endDate) {
            try {
                $parsedEnd = Carbon::parse($endDate)->endOfDay();
                $query->where('work_date', '<=', $parsedEnd);
            } catch (Exception $e) { /* abaikan tanggal tidak valid */
            }
        }

        return $query->orderBy('work_date', 'desc')->orderBy('clock_in_at', 'desc')->paginate($perPage);
    }

    public function correctAttendance(Attendance $attendance, array $data, User $admin): Attendance
    {
        return DB::transaction(function () use ($attendance, $data, $admin) {
            $originalAttributes = $attendance->getOriginal();
            $changedFieldsForLog = [];

            // Update work_date jika ada
            if (isset($data['work_date'])) {
                $newWorkDate = Carbon::parse($data['work_date'])->startOfDay();
                if ($originalAttributes['work_date'] != $newWorkDate->toDateString()) {
                    $changedFieldsForLog['work_date'] = ['old' => $originalAttributes['work_date'], 'new' => $newWorkDate->toDateString()];
                }
                $attendance->work_date = $newWorkDate;
            }
            $baseDateForTime = $attendance->work_date; // Gunakan tanggal kerja yang sudah diupdate atau yang lama

            // Update clock_in_at jika ada
            if (isset($data['clock_in_at'])) { // Asumsi ini sudah Carbon instance dari controller
                $newClockInAt = $data['clock_in_at'];
                $oldClockInAtFormatted = $originalAttributes['clock_in_at'] ? Carbon::parse($originalAttributes['clock_in_at'])->format('Y-m-d H:i:s') : null;
                if ($oldClockInAtFormatted !== $newClockInAt->format('Y-m-d H:i:s')) {
                    $changedFieldsForLog['clock_in_at'] = ['old' => $oldClockInAtFormatted, 'new' => $newClockInAt->format('Y-m-d H:i:s')];
                }
                $attendance->clock_in_at = $newClockInAt;
            }

            // Update clock_out_at jika ada
            if (isset($data['clock_out_at'])) { // Asumsi ini sudah Carbon instance atau null dari controller
                $newClockOutAt = $data['clock_out_at'];
                $oldClockOutAtFormatted = $originalAttributes['clock_out_at'] ? Carbon::parse($originalAttributes['clock_out_at'])->format('Y-m-d H:i:s') : null;
                if ($oldClockOutAtFormatted !== ($newClockOutAt ? $newClockOutAt->format('Y-m-d H:i:s') : null)) {
                    $changedFieldsForLog['clock_out_at'] = ['old' => $oldClockOutAtFormatted, 'new' => $newClockOutAt ? $newClockOutAt->format('Y-m-d H:i:s') : null];
                }
                $attendance->clock_out_at = $newClockOutAt;
            }

            // Update work_schedule_id jika ada, dan perbarui denormalized info
            if (array_key_exists('work_schedule_id', $data)) {
                if ($originalAttributes['work_schedule_id'] != $data['work_schedule_id']) {
                    $changedFieldsForLog['work_schedule_id'] = ['old' => $originalAttributes['work_schedule_id'], 'new' => $data['work_schedule_id']];
                }
                $attendance->work_schedule_id = $data['work_schedule_id'];
            }

            // Dapatkan jadwal kerja yang relevan (setelah potensi perubahan work_schedule_id)
            $activeSchedule = $attendance->work_schedule_id ? WorkSchedule::find($attendance->work_schedule_id) : $this->workScheduleService->getUserActiveScheduleForDate($attendance->user, $attendance->work_date);

            // Perbarui info jadwal yang didenormalisasi pada record absensi
            // berdasarkan jadwal aktif yang baru (jika berubah) atau yang lama.
            if ($activeSchedule) {
                $newScheduledStartTime = $activeSchedule->start_time;
                $newScheduledEndTime = $activeSchedule->end_time;
                $newScheduledNetWorkMinutes = $activeSchedule->scheduled_net_work_minutes; // Menggunakan accessor

                if (($originalAttributes['scheduled_start_time'] ?? null) != $newScheduledStartTime) {
                    $changedFieldsForLog['scheduled_start_time'] = ['old' => $originalAttributes['scheduled_start_time'] ?? null, 'new' => $newScheduledStartTime];
                }
                if (($originalAttributes['scheduled_end_time'] ?? null) != $newScheduledEndTime) {
                    $changedFieldsForLog['scheduled_end_time'] = ['old' => $originalAttributes['scheduled_end_time'] ?? null, 'new' => $newScheduledEndTime];
                }
                if (($originalAttributes['scheduled_work_duration_minutes'] ?? null) != $newScheduledNetWorkMinutes) {
                    $changedFieldsForLog['scheduled_work_duration_minutes'] = ['old' => $originalAttributes['scheduled_work_duration_minutes'] ?? null, 'new' => $newScheduledNetWorkMinutes];
                }
                $attendance->scheduled_start_time = $newScheduledStartTime;
                $attendance->scheduled_end_time = $newScheduledEndTime;
                $attendance->scheduled_work_duration_minutes = $newScheduledNetWorkMinutes;
            } else {
                // Jika tidak ada jadwal aktif, mungkin hapus info denormalisasi
                // atau tangani sesuai aturan bisnis. Untuk saat ini, kita null-kan jika tidak ada jadwal.
                $fieldsToNull = ['scheduled_start_time', 'scheduled_end_time', 'scheduled_work_duration_minutes'];
                foreach ($fieldsToNull as $fieldToNull) {
                    if (isset($originalAttributes[$fieldToNull]) && !isset($changedFieldsForLog[$fieldToNull])) {
                        $changedFieldsForLog[$fieldToNull] = ['old' => $originalAttributes[$fieldToNull], 'new' => null];
                    }
                    $attendance->{$fieldToNull} = null;
                }
            }

            // Update field lain secara langsung
            $directUpdateFields = ['clock_in_notes', 'clock_out_notes']; // Status akan di-handle di bawah
            foreach ($directUpdateFields as $field) {
                if (array_key_exists($field, $data)) {
                    if (($originalAttributes[$field] ?? null) != $data[$field] && !isset($changedFieldsForLog[$field])) {
                        $changedFieldsForLog[$field] = ['old' => $originalAttributes[$field] ?? null, 'new' => $data[$field]];
                    }
                    $attendance->{$field} = $data[$field];
                }
            }

            // Simpan metrik asli sebelum dihitung ulang
            $originalMetrics = ['lateness_minutes' => $originalAttributes['lateness_minutes'] ?? 0, 'clock_in_status' => $originalAttributes['clock_in_status'] ?? null, 'work_duration_minutes' => $originalAttributes['work_duration_minutes'] ?? null, 'effective_work_minutes' => $originalAttributes['effective_work_minutes'] ?? null, 'overtime_minutes' => $originalAttributes['overtime_minutes'] ?? 0, 'early_leave_minutes' => $originalAttributes['early_leave_minutes'] ?? 0, 'clock_out_status' => $originalAttributes['clock_out_status'] ?? null,];

            // Handle status clock_in (jika di-override oleh admin)
            if (isset($data['clock_in_status'])) {
                if ($originalMetrics['clock_in_status'] != $data['clock_in_status'] && !isset($changedFieldsForLog['clock_in_status'])) {
                    $changedFieldsForLog['clock_in_status'] = ['old' => $originalMetrics['clock_in_status'], 'new' => $data['clock_in_status']];
                }
                $attendance->clock_in_status = $data['clock_in_status'];
                if ($attendance->clock_in_status == self::STATUS_CLOCK_IN_ON_TIME) {
                    $attendance->lateness_minutes = 0;
                } elseif ($attendance->clock_in_status == self::STATUS_CLOCK_IN_LATE && $attendance->clock_in_at && $activeSchedule) {
                    $clockInTime = $attendance->clock_in_at;
                    $scheduledStartTime = Carbon::parse($attendance->work_date->toDateString() . ' ' . $activeSchedule->start_time);
                    $gracePeriodLate = $activeSchedule->grace_period_late_minutes ?? (int)AttendanceSetting::getByKey('late_tolerance_minutes', 0);
                    $effectiveScheduledStart = $scheduledStartTime->copy()->addMinutes($gracePeriodLate);
                    $attendance->lateness_minutes = $clockInTime->gt($effectiveScheduledStart) ? $effectiveScheduledStart->diffInMinutes($clockInTime) : 0;
                }
                // Jika tidak, lateness_minutes akan dihitung oleh calculateInitialClockInMetrics jika tidak di-override
            } elseif ($attendance->clock_in_at) { // Jika status tidak di-override, hitung ulang
                $this->calculateInitialClockInMetrics($attendance, $activeSchedule);
            } else { // Jika tidak ada clock_in_at
                $attendance->clock_in_status = null;
                $attendance->lateness_minutes = 0;
            }

            // Handle status clock_out (jika di-override oleh admin)
            if (isset($data['clock_out_status'])) {
                if ($originalMetrics['clock_out_status'] != $data['clock_out_status'] && !isset($changedFieldsForLog['clock_out_status'])) {
                    $changedFieldsForLog['clock_out_status'] = ['old' => $originalMetrics['clock_out_status'], 'new' => $data['clock_out_status']];
                }
                $attendance->clock_out_status = $data['clock_out_status'];
                // Hitung ulang durasi, lembur, early leave berdasarkan status baru
                if ($attendance->clock_in_at && $attendance->clock_out_at) {
                    $attendance->work_duration_minutes = $attendance->clock_in_at->diffInMinutes($attendance->clock_out_at);
                    $breakMins = $activeSchedule?->break_duration_minutes ?? 0;
                    $attendance->effective_work_minutes = max(0, $attendance->work_duration_minutes - $breakMins);

                    $attendance->overtime_minutes = 0;
                    $attendance->early_leave_minutes = 0;

                    if ($data['clock_out_status'] == self::STATUS_CLOCK_OUT_EARLY && $activeSchedule) {
                        $scheduledEndTime = Carbon::parse($attendance->work_date->toDateString() . ' ' . $activeSchedule->end_time);
                        if ($activeSchedule->crosses_midnight && $scheduledEndTime->lt(Carbon::parse($attendance->work_date->toDateString() . ' ' . $activeSchedule->start_time))) {
                            $scheduledEndTime->addDay();
                        }
                        $graceEarly = $activeSchedule->grace_period_early_leave_minutes ?? (int)AttendanceSetting::getByKey('early_leave_tolerance_minutes', 0);
                        $effectiveScheduledEnd = $scheduledEndTime->copy()->subMinutes($graceEarly);
                        if ($attendance->clock_out_at->lt($effectiveScheduledEnd)) {
                            $attendance->early_leave_minutes = $effectiveScheduledEnd->diffInMinutes($attendance->clock_out_at);
                        }
                    } elseif ($data['clock_out_status'] == self::STATUS_CLOCK_OUT_OVERTIME && $activeSchedule && $attendance->effective_work_minutes) {
                        $scheduledNetWorkMinutes = $activeSchedule->scheduled_net_work_minutes; // Dari accessor
                        if ($attendance->effective_work_minutes > $scheduledNetWorkMinutes) {
                            $rawOvertime = $attendance->effective_work_minutes - $scheduledNetWorkMinutes;
                            $minOvertimeThreshold = (int)AttendanceSetting::getByKey('min_overtime_threshold_minutes', 0);
                            if ($rawOvertime >= $minOvertimeThreshold) {
                                $attendance->overtime_minutes = $rawOvertime;
                            }
                        }
                    }
                } else {
                    $attendance->work_duration_minutes = null;
                    $attendance->effective_work_minutes = null;
                    $attendance->overtime_minutes = 0;
                    $attendance->early_leave_minutes = 0;
                }
            } elseif ($attendance->clock_in_at && $attendance->clock_out_at) { // Jika status tidak di-override, hitung ulang
                $this->calculateWorkDurationsAndStatus($attendance, $activeSchedule);
            } else { // Jika tidak ada clock_in_at atau clock_out_at
                $attendance->work_duration_minutes = null;
                $attendance->effective_work_minutes = null;
                $attendance->overtime_minutes = 0;
                $attendance->early_leave_minutes = 0;
                $attendance->clock_out_status = null;
            }


            // Log perubahan metrik yang dihitung ulang
            $recalculatedMetrics = ['lateness_minutes' => $attendance->lateness_minutes, 'clock_in_status' => $attendance->clock_in_status, // Status setelah dihitung ulang / di-override
                'work_duration_minutes' => $attendance->work_duration_minutes, 'effective_work_minutes' => $attendance->effective_work_minutes, 'overtime_minutes' => $attendance->overtime_minutes, 'early_leave_minutes' => $attendance->early_leave_minutes, 'clock_out_status' => $attendance->clock_out_status, // Status setelah dihitung ulang / di-override
            ];

            foreach ($recalculatedMetrics as $metric => $newValue) {
                $oldValue = $originalMetrics[$metric];
                if ($newValue != $oldValue && !isset($changedFieldsForLog[$metric])) { // Hanya log jika belum di-log dari input langsung
                    $changedFieldsForLog[$metric] = ['old' => $oldValue, 'new' => $newValue];
                }
            }

            // Buat entri log jika ada perubahan
            if (count($changedFieldsForLog) > 0) {
                // ... (logika pembuatan AttendanceCorrectionLog tetap sama) ...
                $reasonForCorrection = $data['admin_correction_notes'] ?? 'Correction by administrator.';
                foreach ($changedFieldsForLog as $column => $values) {
                    AttendanceCorrectionLog::create(['attendance_id' => $attendance->id, 'admin_user_id' => $admin->id, 'changed_column' => $column, 'old_value' => is_array($values['old']) || is_object($values['old']) ? json_encode($values['old']) : (string)($values['old'] ?? ''), 'new_value' => is_array($values['new']) || is_object($values['new']) ? json_encode($values['new']) : (string)($values['new'] ?? ''), 'reason' => $reasonForCorrection, 'ip_address_of_admin' => request()?->ip(),]);
                }

                $attendance->is_manually_corrected = true;
                $attendance->last_corrected_by = $admin->id;
                $attendance->last_correction_at = now();
                $newCorrectionNote = "[" . now()->toDateTimeString() . "] Admin ({$admin->name} - ID:{$admin->id}): {$reasonForCorrection}";
                $currentSummary = $originalAttributes['correction_summary_notes'] ?? '';
                $attendance->correction_summary_notes = trim($currentSummary . ($currentSummary ? "\n" : '') . $newCorrectionNote);
            }

            $attendance->save();
            return $attendance;
        });
    }
}
