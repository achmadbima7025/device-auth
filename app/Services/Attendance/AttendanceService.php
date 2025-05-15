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

class AttendanceService
{
    public const string STATUS_CLOCK_IN_ON_TIME = 'On Time';
    public const string STATUS_CLOCK_IN_LATE = 'Late';
    public const string STATUS_CLOCK_OUT_ON_TIME = 'Finished On Time';
    public const string STATUS_CLOCK_OUT_EARLY = 'Left Early';
    public const string STATUS_CLOCK_OUT_OVERTIME = 'Overtime';

    public function __construct(
        protected readonly QrCodeService $qrCodeService,
        protected readonly WorkScheduleService $workScheduleService
    )
    {
    }

    /**
     * Process a scan for attendance (clock-in or clock-out)
     *
     * @param User $user The user performing the scan
     * @param array $qrPayload The QR code payload data
     * @param string $clientTimestamp The timestamp from the client device
     * @param array|null $locationData Optional GPS location data
     * @param string|null $deviceIdentifier Optional device identifier
     * @return array The attendance record and a message
     * @throws ValidationException If the scan is invalid
     */
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
            $denormalizedScheduleInfo = ['scheduled_start_time' => $activeSchedule->start_time, 'scheduled_end_time' => $activeSchedule->end_time,
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

                $this->calculateWorkDurationsAndStatus($attendance, $activeSchedule);

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

    /**
     * Determine the effective work date for a scan
     *
     * This method handles night shifts that cross midnight by checking if the user
     * has an uncompleted attendance record from the previous day with a schedule
     * that crosses midnight. If so, and the scan time is within the allowed window
     * after the scheduled end time, it returns yesterday's date as the work date.
     *
     * @param Carbon $scanTime The time of the scan
     * @param User $user The user performing the scan
     * @return Carbon The effective work date for the scan
     */
    protected function determineWorkDate(Carbon $scanTime, User $user): Carbon
    {
        $yesterdayWorkDate = $scanTime->copy()->subDay()->startOfDay();
        $yesterdayAttendance = Attendance::where('user_id', $user->id)->where('work_date', $yesterdayWorkDate->toDateString())->notClockedOut()->first();

        if ($yesterdayAttendance?->workSchedule?->crosses_midnight) {
            $schedule = $yesterdayAttendance->workSchedule;
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

    /**
     * Validate the GPS location for attendance
     *
     * @param array $locationData The GPS location data containing latitude and longitude
     * @throws ValidationException If the location is invalid or outside the allowed radius
     */
    protected function validateGpsLocation(array $locationData): void
    {
        // Validate that location data contains required fields
        if (!isset($locationData['latitude']) || !isset($locationData['longitude'])) {
            throw ValidationException::withMessages(['location' => 'Location data must include latitude and longitude.']);
        }

        // Validate latitude and longitude ranges
        $latitude = (float)$locationData['latitude'];
        $longitude = (float)$locationData['longitude'];

        if ($latitude < -90 || $latitude > 90) {
            throw ValidationException::withMessages(['location' => 'Latitude must be between -90 and 90 degrees.']);
        }

        if ($longitude < -180 || $longitude > 180) {
            throw ValidationException::withMessages(['location' => 'Longitude must be between -180 and 180 degrees.']);
        }

        $officeLatitude = AttendanceSetting::getByKey('office_latitude');
        $officeLongitude = AttendanceSetting::getByKey('office_longitude');
        $gpsRadiusMeters = (int)AttendanceSetting::getByKey('gps_radius_meters', 100);

        if ($officeLatitude && $officeLongitude) {
            $distance = $this->calculateDistance($latitude, $longitude, (float)$officeLatitude, (float)$officeLongitude);
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
     * Calculate the distance between two geographic coordinates using the Haversine formula
     *
     * @param float $lat1 Latitude of the first point in decimal degrees
     * @param float $lon1 Longitude of the first point in decimal degrees
     * @param float $lat2 Latitude of the second point in decimal degrees
     * @param float $lon2 Longitude of the second point in decimal degrees
     * @return float Distance between the points in meters
     */
    protected function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000; // Earth radius in meters
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) * sin($dLat / 2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) * sin($dLon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadius * $c;
    }

    /**
     * Validate the device identifier and get the corresponding device ID
     *
     * This method checks if the device is approved for the user and returns its ID.
     * If device enforcement is enabled, it will throw an exception for unapproved
     * or missing devices.
     *
     * @param User $user The user performing the attendance
     * @param string|null $deviceIdentifier The device identifier to validate
     * @return int|null The device ID if valid, null otherwise
     * @throws ValidationException If the device is not approved or missing when required
     */
    protected function validateAndGetDeviceId(User $user, ?string $deviceIdentifier): ?int
    {
        $deviceId = null;
        if ($deviceIdentifier) {
            $device = UserDevice::where('device_identifier', $deviceIdentifier)
                               ->where('user_id', $user->id)
                               ->where('status', UserDevice::STATUS_APPROVED)
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
     * Calculate the initial clock-in metrics for an attendance record
     *
     * This method determines if the user clocked in on time or late based on the
     * scheduled start time and grace period. It updates the attendance record with
     * the appropriate status and lateness minutes.
     *
     * @param Attendance $attendance The attendance record to update
     * @param WorkSchedule|null $schedule The work schedule to use for calculations
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

        $gracePeriodLate = $schedule->grace_period_late_minutes ?? (int)AttendanceSetting::getByKey('late_tolerance_minutes', 0);
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
     * Calculate work durations and determine clock-out status
     *
     * This method calculates the total work duration, effective work minutes (after
     * subtracting break time), overtime minutes, and early leave minutes. It also
     * determines the appropriate clock-out status (on time, early, or overtime).
     *
     * @param Attendance $attendance The attendance record to update
     * @param WorkSchedule|null $schedule The work schedule to use for calculations
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

        // Calculate total work duration
        $attendance->work_duration_minutes = $clockIn->diffInMinutes($clockOut);

        // Subtract break time to get effective work minutes
        $breakMinutesFromSchedule = $schedule?->break_duration_minutes ?? 0;
        $attendance->effective_work_minutes = max(0, $attendance->work_duration_minutes - $breakMinutesFromSchedule);

        // Initialize metrics
        $attendance->early_leave_minutes = 0;
        $attendance->overtime_minutes = 0;
        $attendance->clock_out_status = self::STATUS_CLOCK_OUT_ON_TIME;

        if ($schedule) {
            // Get scheduled end time, adjusting for schedules that cross midnight
            $scheduledEndTime = Carbon::parse($attendance->work_date->toDateString() . ' ' . $schedule->end_time);
            if ($schedule->crosses_midnight && $scheduledEndTime->lt(Carbon::parse($attendance->work_date->toDateString() . ' ' . $schedule->start_time))) {
                $scheduledEndTime->addDay();
            }

            // Apply early leave grace period
            $graceEarly = $schedule->grace_period_early_leave_minutes ?? (int)AttendanceSetting::getByKey('early_leave_tolerance_minutes', 0);
            $effectiveScheduledEnd = $scheduledEndTime->copy()->subMinutes($graceEarly);

            if ($clockOut->lt($effectiveScheduledEnd)) {
                // User left early
                $attendance->early_leave_minutes = $effectiveScheduledEnd->diffInMinutes($clockOut);
                $attendance->clock_out_status = self::STATUS_CLOCK_OUT_EARLY;
            } else {
                // Check for overtime
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

    /**
     * Get paginated attendance history for a user with optional date filtering
     *
     * @param User $user The user to get attendance history for
     * @param int $perPage Number of records per page
     * @param string|null $startDate Optional start date for filtering (Y-m-d format)
     * @param string|null $endDate Optional end date for filtering (Y-m-d format)
     * @return LengthAwarePaginator Paginated attendance records
     */
    public function getAttendanceHistoryForUser(User $user, int $perPage = 15, ?string $startDate = null, ?string $endDate = null): LengthAwarePaginator
    {
        // Start with a base query that includes related models for efficiency
        $query = $user->attendanceRecords()->with([
            'workSchedule:id,name,start_time,end_time',
            'clockInDevice:id,name',
            'clockOutDevice:id,name'
        ]);

        // Apply date range filters if provided
        if ($startDate && $endDate) {
            try {
                $parsedStart = Carbon::parse($startDate)->startOfDay();
                $parsedEnd = Carbon::parse($endDate)->endOfDay();
                $query->whereBetween('work_date', [$parsedStart, $parsedEnd]);
            } catch (Exception $e) {
                Log::error("Invalid date format for attendance history filter: {$e->getMessage()}");
                // Continue without date filtering if format is invalid
            }
        } elseif ($startDate) {
            try {
                $parsedStart = Carbon::parse($startDate)->startOfDay();
                $query->where('work_date', '>=', $parsedStart);
            } catch (Exception $e) {
                Log::error("Invalid start date format: {$e->getMessage()}");
            }
        } elseif ($endDate) {
            try {
                $parsedEnd = Carbon::parse($endDate)->endOfDay();
                $query->where('work_date', '<=', $parsedEnd);
            } catch (Exception $e) {
                Log::error("Invalid end date format: {$e->getMessage()}");
            }
        }

        // Return paginated results ordered by most recent first
        return $query->orderBy('work_date', 'desc')
                    ->orderBy('clock_in_at', 'desc')
                    ->paginate($perPage);
    }

    /**
     * Get attendance statistics for a user or group of users over a period of time
     *
     * This method calculates various attendance metrics such as on-time rate,
     * average work duration, total overtime, etc. for analysis and reporting.
     *
     * @param User|array|null $users A user, array of user IDs, or null for all users
     * @param string|Carbon $startDate Start date for the statistics period
     * @param string|Carbon $endDate End date for the statistics period
     * @return array Statistics data including counts, averages, and percentages
     */
    public function getAttendanceStatistics($users = null, $startDate = null, $endDate = null): array
    {
        // Parse date parameters
        $parsedStartDate = $startDate ? Carbon::parse($startDate)->startOfDay() : Carbon::now()->subMonth()->startOfDay();
        $parsedEndDate = $endDate ? Carbon::parse($endDate)->endOfDay() : Carbon::now()->endOfDay();

        // Build base query
        $query = Attendance::query()
            ->whereBetween('work_date', [$parsedStartDate, $parsedEndDate]);

        // Filter by user(s) if specified
        if ($users instanceof User) {
            $query->where('user_id', $users->id);
        } elseif (is_array($users)) {
            $query->whereIn('user_id', $users);
        }

        // Get all relevant attendance records
        $attendances = $query->get();

        // Initialize statistics
        $stats = [
            'period' => [
                'start_date' => $parsedStartDate->toDateString(),
                'end_date' => $parsedEndDate->toDateString(),
                'days' => $parsedStartDate->diffInDays($parsedEndDate) + 1,
            ],
            'counts' => [
                'total_records' => $attendances->count(),
                'clock_in_only' => $attendances->whereNull('clock_out_at')->count(),
                'complete_records' => $attendances->whereNotNull('clock_out_at')->count(),
                'on_time_arrivals' => $attendances->where('clock_in_status', self::STATUS_CLOCK_IN_ON_TIME)->count(),
                'late_arrivals' => $attendances->where('clock_in_status', self::STATUS_CLOCK_IN_LATE)->count(),
                'early_departures' => $attendances->where('clock_out_status', self::STATUS_CLOCK_OUT_EARLY)->count(),
                'on_time_departures' => $attendances->where('clock_out_status', self::STATUS_CLOCK_OUT_ON_TIME)->count(),
                'overtime_instances' => $attendances->where('clock_out_status', self::STATUS_CLOCK_OUT_OVERTIME)->count(),
            ],
            'totals' => [
                'total_work_minutes' => $attendances->sum('work_duration_minutes'),
                'total_effective_work_minutes' => $attendances->sum('effective_work_minutes'),
                'total_overtime_minutes' => $attendances->sum('overtime_minutes'),
                'total_lateness_minutes' => $attendances->sum('lateness_minutes'),
                'total_early_leave_minutes' => $attendances->sum('early_leave_minutes'),
            ],
            'averages' => [],
            'percentages' => [],
        ];

        // Calculate averages
        $completeCount = $stats['counts']['complete_records'];
        $totalCount = $stats['counts']['total_records'];

        if ($completeCount > 0) {
            $stats['averages']['avg_work_duration_minutes'] = round($stats['totals']['total_work_minutes'] / $completeCount, 2);
            $stats['averages']['avg_effective_work_minutes'] = round($stats['totals']['total_effective_work_minutes'] / $completeCount, 2);

            // Format as hours:minutes for readability
            $stats['averages']['avg_work_duration_formatted'] = sprintf(
                '%02d:%02d',
                floor($stats['averages']['avg_work_duration_minutes'] / 60),
                $stats['averages']['avg_work_duration_minutes'] % 60
            );

            $stats['averages']['avg_effective_work_formatted'] = sprintf(
                '%02d:%02d',
                floor($stats['averages']['avg_effective_work_minutes'] / 60),
                $stats['averages']['avg_effective_work_minutes'] % 60
            );
        }

        if ($totalCount > 0) {
            $stats['averages']['avg_overtime_minutes'] = round($stats['totals']['total_overtime_minutes'] / $totalCount, 2);
            $stats['averages']['avg_lateness_minutes'] = round($stats['totals']['total_lateness_minutes'] / $totalCount, 2);
            $stats['averages']['avg_early_leave_minutes'] = round($stats['totals']['total_early_leave_minutes'] / $totalCount, 2);

            // Calculate percentages
            $stats['percentages']['on_time_arrival_rate'] = round(($stats['counts']['on_time_arrivals'] / $totalCount) * 100, 2);
            $stats['percentages']['late_arrival_rate'] = round(($stats['counts']['late_arrivals'] / $totalCount) * 100, 2);

            if ($completeCount > 0) {
                $stats['percentages']['early_departure_rate'] = round(($stats['counts']['early_departures'] / $completeCount) * 100, 2);
                $stats['percentages']['overtime_rate'] = round(($stats['counts']['overtime_instances'] / $completeCount) * 100, 2);
            }
        }

        return $stats;
    }

    /**
     * Correct an attendance record with administrative changes
     *
     * This method allows an administrator to modify attendance records, including
     * clock-in/out times, work schedule, and notes. All changes are logged in the
     * attendance correction log for audit purposes.
     *
     * @param Attendance $attendance The attendance record to correct
     * @param array $data The correction data (clock_in_at, clock_out_at, work_schedule_id, etc.)
     * @param User $admin The administrator making the correction
     * @return Attendance The updated attendance record
     */
    public function correctAttendance(Attendance $attendance, array $data, User $admin): Attendance
    {
        return DB::transaction(function () use ($attendance, $data, $admin) {
            $originalAttributes = $attendance->getOriginal();
            $changedFieldsForLog = [];

            // Update work_date if provided
            if (isset($data['work_date'])) {
                $newWorkDate = Carbon::parse($data['work_date'])->startOfDay();
                if ($originalAttributes['work_date'] != $newWorkDate->toDateString()) {
                    $changedFieldsForLog['work_date'] = ['old' => $originalAttributes['work_date'], 'new' => $newWorkDate->toDateString()];
                }
                $attendance->work_date = $newWorkDate;
            }
            $baseDateForTime = $attendance->work_date; // Use the updated work date or the existing one

            // Update clock_in_at if provided
            if (isset($data['clock_in_at'])) { // Assuming this is already a Carbon instance from the controller
                $newClockInAt = $data['clock_in_at'];
                $oldClockInAtFormatted = $originalAttributes['clock_in_at'] ? Carbon::parse($originalAttributes['clock_in_at'])->format('Y-m-d H:i:s') : null;
                if ($oldClockInAtFormatted !== $newClockInAt->format('Y-m-d H:i:s')) {
                    $changedFieldsForLog['clock_in_at'] = ['old' => $oldClockInAtFormatted, 'new' => $newClockInAt->format('Y-m-d H:i:s')];
                }
                $attendance->clock_in_at = $newClockInAt;
            }

            // Update clock_out_at if provided
            if (isset($data['clock_out_at'])) { // Assuming this is already a Carbon instance or null from the controller
                $newClockOutAt = $data['clock_out_at'];
                $oldClockOutAtFormatted = $originalAttributes['clock_out_at'] ? Carbon::parse($originalAttributes['clock_out_at'])->format('Y-m-d H:i:s') : null;
                if ($oldClockOutAtFormatted !== ($newClockOutAt ? $newClockOutAt->format('Y-m-d H:i:s') : null)) {
                    $changedFieldsForLog['clock_out_at'] = ['old' => $oldClockOutAtFormatted, 'new' => $newClockOutAt ? $newClockOutAt->format('Y-m-d H:i:s') : null];
                }
                $attendance->clock_out_at = $newClockOutAt;
            }

            // Update work_schedule_id if provided, and update denormalized info
            if (array_key_exists('work_schedule_id', $data)) {
                if ($originalAttributes['work_schedule_id'] != $data['work_schedule_id']) {
                    $changedFieldsForLog['work_schedule_id'] = ['old' => $originalAttributes['work_schedule_id'], 'new' => $data['work_schedule_id']];
                }
                $attendance->work_schedule_id = $data['work_schedule_id'];
            }

            // Get the relevant work schedule (after potential work_schedule_id changes)
            $activeSchedule = $attendance->work_schedule_id ? WorkSchedule::find($attendance->work_schedule_id) : $this->workScheduleService->getUserActiveScheduleForDate($attendance->user, $attendance->work_date);

            // Update denormalized schedule info on the attendance record
            // based on the new active schedule (if changed) or the existing one.
            if ($activeSchedule) {
                $newScheduledStartTime = $activeSchedule->start_time;
                $newScheduledEndTime = $activeSchedule->end_time;
                $newScheduledNetWorkMinutes = $activeSchedule->scheduled_net_work_minutes; // Using accessor

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
                // If there's no active schedule, we might want to remove denormalized info
                // or handle according to business rules. For now, we set to null if there's no schedule.
                $fieldsToNull = ['scheduled_start_time', 'scheduled_end_time', 'scheduled_work_duration_minutes'];
                foreach ($fieldsToNull as $fieldToNull) {
                    if (isset($originalAttributes[$fieldToNull]) && !isset($changedFieldsForLog[$fieldToNull])) {
                        $changedFieldsForLog[$fieldToNull] = ['old' => $originalAttributes[$fieldToNull], 'new' => null];
                    }
                    $attendance->{$fieldToNull} = null;
                }
            }

            // Update other fields directly
            $directUpdateFields = ['clock_in_notes', 'clock_out_notes']; // Status will be handled below
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
                // Otherwise, lateness_minutes will be calculated by calculateInitialClockInMetrics if not overridden
            } elseif ($attendance->clock_in_at) { // If status is not overridden, recalculate
                $this->calculateInitialClockInMetrics($attendance, $activeSchedule);
            } else { // If there's no clock_in_at
                $attendance->clock_in_status = null;
                $attendance->lateness_minutes = 0;
            }

            // Handle clock_out status (if overridden by admin)
            if (isset($data['clock_out_status'])) {
                if ($originalMetrics['clock_out_status'] != $data['clock_out_status'] && !isset($changedFieldsForLog['clock_out_status'])) {
                    $changedFieldsForLog['clock_out_status'] = ['old' => $originalMetrics['clock_out_status'], 'new' => $data['clock_out_status']];
                }
                $attendance->clock_out_status = $data['clock_out_status'];
                // Recalculate duration, overtime, early leave based on new status
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
                        $scheduledNetWorkMinutes = $activeSchedule->scheduled_net_work_minutes; // From accessor
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
            } elseif ($attendance->clock_in_at && $attendance->clock_out_at) { // If status is not overridden, recalculate
                $this->calculateWorkDurationsAndStatus($attendance, $activeSchedule);
            } else { // If there's no clock_in_at or clock_out_at
                $attendance->work_duration_minutes = null;
                $attendance->effective_work_minutes = null;
                $attendance->overtime_minutes = 0;
                $attendance->early_leave_minutes = 0;
                $attendance->clock_out_status = null;
            }

            $recalculatedMetrics = ['lateness_minutes' => $attendance->lateness_minutes, 'clock_in_status' => $attendance->clock_in_status, // Status after recalculation / override
                'work_duration_minutes' => $attendance->work_duration_minutes, 'effective_work_minutes' => $attendance->effective_work_minutes, 'overtime_minutes' => $attendance->overtime_minutes, 'early_leave_minutes' => $attendance->early_leave_minutes, 'clock_out_status' => $attendance->clock_out_status, // Status after recalculation / override
            ];

            foreach ($recalculatedMetrics as $metric => $newValue) {
                $oldValue = $originalMetrics[$metric];
                if ($newValue != $oldValue && !isset($changedFieldsForLog[$metric])) {
                    $changedFieldsForLog[$metric] = ['old' => $oldValue, 'new' => $newValue];
                }
            }

            // Create log entry if there are changes
            if (count($changedFieldsForLog) > 0) {
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
