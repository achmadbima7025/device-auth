<?php

namespace App\Services\Attendance;

use App\Models\Attendance;
use App\Models\AttendanceCorrectionLog;
use App\Models\AttendanceSetting;
use App\Models\User;
use App\Models\UserDevice;
use App\Models\WorkSchedule;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AttendanceService
{
    // Menggunakan PHP 8.3 Typed Class Constants
    public const string STATUS_CLOCK_IN_ON_TIME = 'On Time';
    public const string STATUS_CLOCK_IN_LATE = 'Late';
    public const string STATUS_CLOCK_OUT_ON_TIME = 'Finished On Time';
    public const string STATUS_CLOCK_OUT_EARLY = 'Left Early';
    public const string STATUS_CLOCK_OUT_OVERTIME = 'Overtime';

    // Menggunakan PHP 8.1 Constructor Property Promotion dan Readonly Properties
    public function __construct(
        protected readonly QrCodeService $qrCodeService,
        protected readonly WorkScheduleService $workScheduleService
    ) {
    }

    /**
     * Memproses scan QR Code untuk absensi.
     *
     * @param User $user Pengguna yang melakukan scan.
     * @param array $qrPayload Data dari QR Code yang sudah di-decode.
     * @param string $clientTimestamp Timestamp dari klien (ISO 8601).
     * @param array|null $locationData Data lokasi ['latitude' => float, 'longitude' => float].
     * @param string|null $deviceIdentifier Identifier perangkat klien.
     * @return Attendance Record absensi yang diproses.
     * @throws ValidationException Jika ada error validasi atau logika.
     */
    public function processScan(User $user, array $qrPayload, string $clientTimestamp, ?array $locationData, ?string $deviceIdentifier): Attendance
    {
        // 1. Validasi QR Code
        $qrCodeRecord = $this->qrCodeService->validateQrCodePayload($qrPayload);
        if (!$qrCodeRecord) {
            // Cek hari kemarin
            $yesterdayPayload = array_merge($qrPayload, ['date' => Carbon::yesterday()->toDateString()]);
            $qrCodeRecord = $this->qrCodeService->validateQrCodePayload($yesterdayPayload);

            // Cek hari ini (untuk shift malam yang clock out di hari berikutnya)
            if (!$qrCodeRecord) {
                $todayPayload = array_merge($qrPayload, ['date' => Carbon::today()->toDateString()]);
                $qrCodeRecord = $this->qrCodeService->validateQrCodePayload($todayPayload);

                if (!$qrCodeRecord) {
                    throw ValidationException::withMessages(['qr_code' => 'QR Code is invalid or expired.']);
                }
            }
        }

        // 2. Validasi Lokasi GPS (jika fitur aktif dan data lokasi ada)
        if (AttendanceSetting::getByKey('enable_gps_validation', false) && $locationData) {
            $this->validateGpsLocation($locationData);
        }

        // 3. Tentukan Tanggal Kerja Efektif dan Jadwal Kerja
        $serverNow = Carbon::now(); // Timestamp server saat ini
        $workDate = $this->determineWorkDate($serverNow, $user); // Carbon instance (start of day)
        $activeSchedule = $this->workScheduleService->getUserActiveScheduleForDate($user, $workDate);

        $denormalizedScheduleInfo = [];
        if ($activeSchedule) {
            $denormalizedScheduleInfo = [
                'scheduled_start_time' => $activeSchedule->start_time,
                'scheduled_end_time' => $activeSchedule->end_time,
                'scheduled_work_duration_minutes' => (int) ($activeSchedule->work_duration_hours * 60),
            ];
        } else {
            Log::warning("No active or default work schedule found for user {$user->id} on {$workDate->toDateString()}. Attendance might use fallback or be less precise.");
            // Pertimbangkan untuk melempar error jika jadwal adalah mandatory
            throw ValidationException::withMessages(['schedule' => 'No active work schedule found for this date.']);
        }

        // 4. Dapatkan ID Perangkat jika ada dan validasi jika perlu
        $deviceId = $this->validateAndGetDeviceId($user, $deviceIdentifier);

        // 5. Logika Penentuan Masuk atau Pulang dalam Transaksi
        $attendanceRecord = DB::transaction(function () use ($user, $serverNow, $workDate, $activeSchedule, $locationData, $deviceId, $qrCodeRecord, $denormalizedScheduleInfo) {
            $attendance = Attendance::firstOrNew(
                ['user_id' => $user->id, 'work_date' => $workDate->toDateString()]
            );

            $successMessage = '';

            if (is_null($attendance->clock_in_at)) {
                // Ini adalah ABSEN MASUK
                $attendance->fill($denormalizedScheduleInfo);
                $attendance->work_schedule_id = $activeSchedule?->id;
                $attendance->clock_in_at = $serverNow;
                $attendance->clock_in_notes = "Clocked in via QR Code.";
                if ($locationData) {
                    $attendance->clock_in_latitude = $locationData['latitude'];
                    $attendance->clock_in_longitude = $locationData['longitude'];
                }
                $attendance->clock_in_device_id = $deviceId;
                $attendance->clock_in_qr_code_id = $qrCodeRecord->id; // Asumsi $qrCodeRecord adalah instance Model QrCode
                $attendance->clock_in_method = 'qr_scan';

                $this->calculateInitialClockInMetrics($attendance, $activeSchedule);
                $successMessage = "Clocked in successfully at {$serverNow->format('H:i:s')}. Status: {$attendance->clock_in_status}.";
                if ($attendance->lateness_minutes > 0) {
                    $successMessage .= " Late by: {$attendance->lateness_minutes} minutes.";
                }

            } elseif (is_null($attendance->clock_out_at)) {
                // Ini adalah ABSEN PULANG
                $minDurationBeforeClockOut = (int) AttendanceSetting::getByKey('min_duration_before_clock_out_minutes', 60);
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
                $attendance->clock_out_qr_code_id = $qrCodeRecord->id; // Asumsi $qrCodeRecord adalah instance Model QrCode
                $attendance->clock_out_method = 'qr_scan';

                $this->calculateWorkDurationsAndStatus($attendance, $activeSchedule);

                $successMessage = "Clocked out successfully at {$serverNow->format('H:i:s')}. Status: {$attendance->clock_out_status}. Work duration: " . $attendance->work_duration_formatted;
                if ($attendance->overtime_minutes > 0) $successMessage .= " Overtime: {$attendance->overtime_minutes} minutes.";
                if ($attendance->early_leave_minutes > 0) $successMessage .= " Left early by: {$attendance->early_leave_minutes} minutes.";

            } else {
                Log::info("User {$user->id} performed an extra scan on {$attendance->work_date->toDateString()}. Clock-in: {$attendance->clock_in_at}, Clock-out: {$attendance->clock_out_at}.");
                throw ValidationException::withMessages(['attendance' => 'You have already clocked in and out for this work date.']);
            }
            $attendance->save();
//            $attendance->success_message = $successMessage;
            return $attendance;
        });

        return $attendanceRecord;
    }

    /**
     * Validasi lokasi GPS.
     * @throws ValidationException
     */
    protected function validateGpsLocation(array $locationData): void
    {
        $officeLatitude = AttendanceSetting::getByKey('office_latitude');
        $officeLongitude = AttendanceSetting::getByKey('office_longitude');
        $gpsRadiusMeters = (int) AttendanceSetting::getByKey('gps_radius_meters', 100);

        if ($officeLatitude && $officeLongitude) {
            $distance = $this->calculateDistance(
                $locationData['latitude'], $locationData['longitude'],
                (float)$officeLatitude, (float)$officeLongitude
            );
            if ($distance > $gpsRadiusMeters) {
                $userId = Auth::id() ?? 'N/A';
                Log::warning("Attendance rejected for user {$userId}: outside GPS radius. Distance: {$distance}m");
                throw ValidationException::withMessages(['location' => "You are outside the allowed area for attendance (Distance: " . round($distance) . "m)."]);
            }
        } else {
            Log::warning("Office GPS settings are incomplete, GPS validation skipped.");
        }
    }

    /**
     * Validasi dan dapatkan device_id.
     * @throws ValidationException
     */
    protected function validateAndGetDeviceId(User $user, ?string $deviceIdentifier): ?int
    {
        $deviceId = null;
        if ($deviceIdentifier) {
            $device = UserDevice::where('device_identifier', $deviceIdentifier)
                ->where('user_id', $user->id)
                ->where('status', UserDevice::STATUS_APPROVED) // Asumsi STATUS_APPROVED ada di UserDevice model
                ->first();
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
     * Menentukan tanggal kerja efektif (work_date), penting untuk shift malam.
     * Mengembalikan instance Carbon (start of day).
     */
    protected function determineWorkDate(Carbon $scanTime, User $user): Carbon
    {
        $yesterdayWorkDate = $scanTime->copy()->subDay()->startOfDay();
        $yesterdayAttendance = Attendance::where('user_id', $user->id)
            ->where('work_date', $yesterdayWorkDate->toDateString())
            ->notClockedOut() // Scope dari model Attendance
            ->first();

        if ($yesterdayAttendance?->workSchedule?->crosses_midnight) { // Menggunakan nullsafe operator
            $schedule = $yesterdayAttendance->workSchedule;
            $scheduledEndTimeAbsolute = Carbon::parse($yesterdayAttendance->work_date->toDateString() . ' ' . $schedule->end_time)->addDay();
            $shiftEndWindow = $scheduledEndTimeAbsolute->copy()->addHours((int)AttendanceSetting::getByKey('night_shift_clock_out_buffer_hours', 3));

            if ($scanTime->lte($shiftEndWindow)) {
                return $yesterdayWorkDate;
            }
        }
        return $scanTime->copy()->startOfDay();
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
        $scheduledStartTime = Carbon::parse($attendance->work_date->toDateString() . ' ' . $schedule->start_time);
        $gracePeriodLate = $schedule->grace_period_late_minutes ?? (int) AttendanceSetting::getByKey('late_tolerance_minutes', 0);
        $effectiveScheduledStart = $scheduledStartTime->copy()->addMinutes($gracePeriodLate);

        if ($clockInTime->gt($effectiveScheduledStart)) {
            $attendance->clock_in_status = self::STATUS_CLOCK_IN_LATE;
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
        $attendance->clock_out_status = self::STATUS_CLOCK_OUT_ON_TIME; // Default

        if ($schedule) {
            $scheduledStartTime = Carbon::parse($attendance->work_date->toDateString() . ' ' . $schedule->start_time);
            $scheduledEndTime = Carbon::parse($attendance->work_date->toDateString() . ' ' . $schedule->end_time);
            if ($schedule->crosses_midnight && $scheduledEndTime->lt($scheduledStartTime)) {
                $scheduledEndTime->addDay();
            }
            $graceEarly = $schedule->grace_period_early_leave_minutes ?? (int) AttendanceSetting::getByKey('early_leave_tolerance_minutes', 0);
            $effectiveScheduledEnd = $scheduledEndTime->copy()->subMinutes($graceEarly);

            if ($clockOut->lt($effectiveScheduledEnd)) {
                $attendance->early_leave_minutes = $clockOut->diffInMinutes($effectiveScheduledEnd);
                $attendance->clock_out_status = self::STATUS_CLOCK_OUT_EARLY;
            } else {
                // Hitung lembur hanya jika tidak pulang cepat
                $scheduledEffectiveWorkMinutes = (int)($schedule->work_duration_hours * 60); // work_duration_hours adalah netto
                if ($attendance->effective_work_minutes > $scheduledEffectiveWorkMinutes) {
                    $rawOvertime = $attendance->effective_work_minutes - $scheduledEffectiveWorkMinutes;
                    $minOvertimeThreshold = (int) AttendanceSetting::getByKey('min_overtime_threshold_minutes', 0);
                    if ($rawOvertime >= $minOvertimeThreshold) {
                        $attendance->overtime_minutes = $rawOvertime;
                        $attendance->clock_out_status = self::STATUS_CLOCK_OUT_OVERTIME;
                    }
                    // Jika rawOvertime < minOvertimeThreshold, status tetap STATUS_CLOCK_OUT_ON_TIME (dari default di atas)
                }
                // Jika effective_work_minutes <= scheduledEffectiveWorkMinutes dan tidak pulang cepat, status tetap STATUS_CLOCK_OUT_ON_TIME
            }
        }
    }

    protected function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) * sin($dLat / 2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) * sin($dLon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadius * $c;
    }

    /**
     * Mengambil riwayat absensi pengguna dengan paginasi.
     */
    public function getAttendanceHistoryForUser(User $user, int $perPage = 15, ?string $startDate = null, ?string $endDate = null): LengthAwarePaginator
    {
        $query = $user->attendanceRecords()
            ->with(['workSchedule:id,name', 'clockInDevice:id,name', 'clockOutDevice:id,name']);

        if ($startDate && $endDate) {
            try {
                $parsedStart = Carbon::parse($startDate)->startOfDay();
                $parsedEnd = Carbon::parse($endDate)->endOfDay();
                $query->whereBetween('work_date', [$parsedStart, $parsedEnd]);
            } catch (\Exception $e) {
                Log::error("Invalid date format for attendance history filter: {$e->getMessage()}");
            }
        } elseif ($startDate) {
            try {
                $parsedStart = Carbon::parse($startDate)->startOfDay();
                $query->where('work_date', '>=', $parsedStart);
            } catch (\Exception $e) { /* Ignore invalid date */ }
        } elseif ($endDate) {
            try {
                $parsedEnd = Carbon::parse($endDate)->endOfDay();
                $query->where('work_date', '<=', $parsedEnd);
            } catch (\Exception $e) { /* Ignore invalid date */ }
        }

        return $query->paginate($perPage);
    }

    /**
     * Admin melakukan koreksi data absensi.
     */
    public function correctAttendance(Attendance $attendance, array $data, User $admin): Attendance
    {
        return DB::transaction(function () use ($attendance, $data, $admin) {
            $originalAttributes = $attendance->getAttributes();
            $changedFieldsForLog = [];

            $updateData = [];
            if (isset($data['work_date'])) {
                $newWorkDate = Carbon::parse($data['work_date'])->startOfDay();
                if ($attendance->work_date->ne($newWorkDate)) {
                    $changedFieldsForLog['work_date'] = [
                        'old' => $attendance->work_date->toDateString(),
                        'new' => $newWorkDate->toDateString(),
                    ];
                    $updateData['work_date'] = $newWorkDate;
                }
            }
            if (isset($data['clock_in_at'])) {
                $newClockInAt = $data['clock_in_at'] instanceof Carbon ? $data['clock_in_at'] : Carbon::parse($data['clock_in_at']);
                if (!$attendance->clock_in_at || $attendance->clock_in_at->ne($newClockInAt)) {
                    $changedFieldsForLog['clock_in_at'] = [
                        'old' => $attendance->clock_in_at?->format('Y-m-d H:i:s'),
                        'new' => $newClockInAt->format('Y-m-d H:i:s'),
                    ];
                    $updateData['clock_in_at'] = $newClockInAt;
                }
            }
            if (isset($data['clock_out_at'])) {
                $newClockOutAt = $data['clock_out_at'] instanceof Carbon ? $data['clock_out_at'] : ($data['clock_out_at'] ? Carbon::parse($data['clock_out_at']) : null);
                if ((!$attendance->clock_out_at && $newClockOutAt) || ($attendance->clock_out_at && $newClockOutAt && $attendance->clock_out_at->ne($newClockOutAt)) || ($attendance->clock_out_at && !$newClockOutAt)) {
                    $changedFieldsForLog['clock_out_at'] = [
                        'old' => $attendance->clock_out_at?->format('Y-m-d H:i:s'),
                        'new' => $newClockOutAt?->format('Y-m-d H:i:s'),
                    ];
                    $updateData['clock_out_at'] = $newClockOutAt;
                }
            }

            $attendance->fill($updateData);

            $otherFieldsToLog = ['clock_in_status', 'clock_in_notes', 'clock_out_status', 'clock_out_notes', 'work_schedule_id'];
            foreach ($otherFieldsToLog as $key) {
                if (array_key_exists($key, $data) && $originalAttributes[$key] != $data[$key]) {
                    $changedFieldsForLog[$key] = [
                        'old' => $originalAttributes[$key],
                        'new' => $data[$key],
                    ];
                    $attendance->{$key} = $data[$key];
                }
            }

            $activeSchedule = $attendance->work_schedule_id ? WorkSchedule::find($attendance->work_schedule_id) : $this->workScheduleService->getUserActiveScheduleForDate($attendance->user, $attendance->work_date);

            $recalculateMetrics = count(array_intersect_key($changedFieldsForLog, array_flip(['clock_in_at', 'clock_out_at']))) > 0 || isset($data['work_schedule_id']);

            if ($recalculateMetrics || isset($data['clock_in_status'])) {
                if(!isset($data['clock_in_status'])) {
                    $this->calculateInitialClockInMetrics($attendance, $activeSchedule);
                } else {
                    if ($originalAttributes['clock_in_status'] != $data['clock_in_status']) {
                        $changedFieldsForLog['clock_in_status'] = ['old' => $originalAttributes['clock_in_status'], 'new' => $data['clock_in_status']];
                    }
                    $attendance->clock_in_status = $data['clock_in_status'];

                    if ($attendance->clock_in_status == self::STATUS_CLOCK_IN_ON_TIME) {
                        $attendance->lateness_minutes = 0;
                    } else if ($attendance->clock_in_status == self::STATUS_CLOCK_IN_LATE && $attendance->clock_in_at && $activeSchedule) {
                        // Logika hitung lateness yang sudah dilengkapi
                        $clockInTime = $attendance->clock_in_at;
                        $scheduledStartTime = Carbon::parse($attendance->work_date->toDateString() . ' ' . $activeSchedule->start_time);
                        $gracePeriodLate = $activeSchedule->grace_period_late_minutes ?? (int) AttendanceSetting::getByKey('late_tolerance_minutes', 0);
                        $effectiveScheduledStart = $scheduledStartTime->copy()->addMinutes($gracePeriodLate);

                        if ($clockInTime->gt($effectiveScheduledStart)) {
                            $attendance->lateness_minutes = $effectiveScheduledStart->diffInMinutes($clockInTime);
                        } else {
                            // Jika status LATE tapi waktu tidak, lateness tetap dihitung berdasarkan perbedaan aktual dari effective start.
                            // Jika admin ingin lateness > 0, waktu clock_in_at juga harus disesuaikan.
                            $attendance->lateness_minutes = max(0, $effectiveScheduledStart->diffInMinutes($clockInTime));
                        }
                    }
                    // Tidak ada else, jika status lain, lateness_minutes tidak diubah kecuali clock_in_at juga berubah
                }
            }

            if ($recalculateMetrics || isset($data['clock_out_status'])) {
                if(!isset($data['clock_out_status'])) {
                    $this->calculateWorkDurationsAndStatus($attendance, $activeSchedule);
                } else {
                    if ($originalAttributes['clock_out_status'] != $data['clock_out_status']) {
                        $changedFieldsForLog['clock_out_status'] = ['old' => $originalAttributes['clock_out_status'], 'new' => $data['clock_out_status']];
                    }
                    $attendance->clock_out_status = $data['clock_out_status'];
                    if ($attendance->clock_in_at && $attendance->clock_out_at) {
                        $attendance->work_duration_minutes = $attendance->clock_in_at->diffInMinutes($attendance->clock_out_at);
                        $breakMins = $activeSchedule?->break_duration_minutes ?? 0;
                        $attendance->effective_work_minutes = max(0, $attendance->work_duration_minutes - $breakMins);

                        // Sesuaikan overtime/early leave berdasarkan status yang di-override
                        if ($attendance->clock_out_status == self::STATUS_CLOCK_OUT_ON_TIME) {
                            $attendance->overtime_minutes = 0;
                            $attendance->early_leave_minutes = 0;
                        } elseif ($attendance->clock_out_status == self::STATUS_CLOCK_OUT_EARLY) {
                            $attendance->overtime_minutes = 0;
                            // early_leave_minutes mungkin perlu dihitung ulang jika waktu clock_out juga berubah
                            // atau biarkan jika hanya status yang berubah (admin menentukan itu early)
                            if ($activeSchedule && $attendance->clock_out_at) {
                                $scheduledEndTime = Carbon::parse($attendance->work_date->toDateString() . ' ' . $activeSchedule->end_time);
                                if ($activeSchedule->crosses_midnight && $scheduledEndTime->lt(Carbon::parse($attendance->work_date->toDateString() . ' ' . $activeSchedule->start_time))) {
                                    $scheduledEndTime->addDay();
                                }
                                $graceEarly = $activeSchedule->grace_period_early_leave_minutes ?? (int) AttendanceSetting::getByKey('early_leave_tolerance_minutes', 0);
                                $effectiveScheduledEnd = $scheduledEndTime->copy()->subMinutes($graceEarly);
                                if ($attendance->clock_out_at->lt($effectiveScheduledEnd)) {
                                    $attendance->early_leave_minutes = $attendance->clock_out_at->diffInMinutes($effectiveScheduledEnd);
                                } else {
                                    $attendance->early_leave_minutes = 0; // Tidak early jika waktu tidak mendukung
                                }
                            }
                        } elseif ($attendance->clock_out_status == self::STATUS_CLOCK_OUT_OVERTIME) {
                            $attendance->early_leave_minutes = 0;
                            // overtime_minutes mungkin perlu dihitung ulang jika waktu clock_out juga berubah
                            if ($activeSchedule && $attendance->effective_work_minutes) {
                                $scheduledEffectiveWorkMinutes = (int)($activeSchedule->work_duration_hours * 60);
                                if ($attendance->effective_work_minutes > $scheduledEffectiveWorkMinutes) {
                                    $rawOvertime = $attendance->effective_work_minutes - $scheduledEffectiveWorkMinutes;
                                    // Threshold tidak diterapkan jika status di-override ke Overtime, admin yang menentukan
                                    $attendance->overtime_minutes = $rawOvertime;
                                } else {
                                    $attendance->overtime_minutes = 0; // Tidak overtime jika waktu tidak mendukung
                                }
                            }
                        }
                    } else {
                        $attendance->work_duration_minutes = null;
                        $attendance->effective_work_minutes = null;
                        $attendance->overtime_minutes = 0;
                        $attendance->early_leave_minutes = 0;
                    }
                }
            }

            $reasonForCorrection = $data['admin_correction_notes'] ?? 'Correction by administrator.';
            foreach ($changedFieldsForLog as $column => $values) {
                AttendanceCorrectionLog::create([
                    'attendance_id' => $attendance->id,
                    'admin_user_id' => $admin->id,
                    'changed_column' => $column,
                    'old_value' => is_array($values['old']) || is_object($values['old']) ? json_encode($values['old']) : (string)$values['old'],
                    'new_value' => is_array($values['new']) || is_object($values['new']) ? json_encode($values['new']) : (string)$values['new'],
                    'reason' => $reasonForCorrection,
                    'ip_address_of_admin' => request()->ip(),
                ]);
            }

            if (count($changedFieldsForLog) > 0) {
                $attendance->is_manually_corrected = true;
                $attendance->last_corrected_by = $admin->id;
                $attendance->last_correction_at = now();
                $attendance->correction_summary_notes = trim(($attendance->correction_summary_notes ?? '') . "\n[" . now()->toDateTimeString() . "] Admin ({$admin->name}): {$reasonForCorrection}");
            }

            $attendance->save();
            return $attendance;
        });
    }
}
