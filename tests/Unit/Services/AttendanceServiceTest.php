<?php

namespace Tests\Unit\Services;

use App\Models\AttendanceSetting;
use App\Models\QrCode;
use App\Models\User;
use App\Models\UserDevice;
use App\Models\WorkSchedule;
use App\Services\Attendance\AttendanceService;
use App\Services\Attendance\QrCodeService;
use App\Services\Attendance\WorkScheduleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AttendanceServiceTest extends TestCase
{
    use RefreshDatabase;

    protected AttendanceService $attendanceService;
    protected WorkScheduleService $workScheduleService; // Tambahkan
    protected QrCodeService $qrCodeService; // Tambahkan
    protected User $user;
    protected WorkSchedule $schedule;
    protected QrCode $qrCode; // QR Code yang valid untuk hari ini
    protected UserDevice $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workScheduleService = $this->app->make(WorkScheduleService::class);
        $this->qrCodeService = $this->app->make(QrCodeService::class); // Resolve QrCodeService
        $this->attendanceService = $this->app->make(AttendanceService::class);


        $this->user = User::factory()->create();
        $this->device = UserDevice::factory()->for($this->user)->approved()->create(['device_identifier' => 'unit_test_device_123']);

        $this->schedule = WorkSchedule::factory()->create([
            'name' => 'Unit Test Schedule',
            'start_time' => '08:00:00', 'end_time' => '17:00:00', // 9 jam di lokasi
            'work_duration_hours' => 8.00, // 8 jam kerja efektif (netto)
            'break_duration_minutes' => 60, // 1 jam istirahat
            'grace_period_late_minutes' => 15,
            'grace_period_early_leave_minutes' => 10,
            'crosses_midnight' => false, 'is_default' => true, 'is_active' => true,
            'monday' => true, 'tuesday' => true, 'wednesday' => true, 'thursday' => true, 'friday' => true,
        ]);
        // Tidak perlu assign jika sudah default dan getUserActiveScheduleForDate bisa handle default
        // $this->workScheduleService->assignScheduleToUser($this->user, $this->schedule, Carbon::today()->subMonth()->toDateString());

        // Generate QR Code yang valid untuk hari ini menggunakan QrCodeService
        $this->qrCode = $this->qrCodeService->generateDailyAttendanceQr('Unit Test Location', $this->schedule->id);

        Auth::shouldReceive('user')->andReturn($this->user)->byDefault();

        AttendanceSetting::factory()->createMany([
            ['key' => 'enable_gps_validation', 'value' => 'false', 'data_type' => 'boolean', 'group' => 'gps'],
            ['key' => 'late_tolerance_minutes', 'value' => '15', 'data_type' => 'integer', 'group' => 'schedule'], // Fallback jika tidak di schedule
            ['key' => 'early_leave_tolerance_minutes', 'value' => '10', 'data_type' => 'integer', 'group' => 'schedule'], // Fallback
            ['key' => 'min_duration_before_clock_out_minutes', 'value' => '60', 'data_type' => 'integer', 'group' => 'schedule'],
            ['key' => 'min_overtime_threshold_minutes', 'value' => '30', 'data_type' => 'integer', 'group' => 'overtime_rules'],
            ['key' => 'enforce_approved_device_for_attendance', 'value' => 'true', 'data_type' => 'boolean', 'group' => 'device'],
            ['key' => 'night_shift_clock_out_buffer_hours', 'value' => '3', 'data_type' => 'integer', 'group' => 'schedule'],
            ['key' => 'standard_clock_in_time', 'value' => '09:00:00', 'data_type' => 'time', 'group' => 'schedule'], // Fallback
            ['key' => 'standard_clock_out_time', 'value' => '17:00:00', 'data_type' => 'time', 'group' => 'schedule'], // Fallback
        ]);
    }

    private function getValidQrPayloadForToday(): array
    {
        $qr = $this->qrCodeService->getActiveDisplayableQrCode('Unit Test Location', $this->schedule->id);
        if (!$qr) {
            // Coba generate jika tidak ada, karena setup mungkin berjalan sebelum test lain yang meng-invalidate
            $qr = $this->qrCodeService->generateDailyAttendanceQr('Unit Test Location', $this->schedule->id);
            if (!$qr) {
                $this->fail("Failed to get or generate a valid QR code for today in test setup.");
            }
        }
        return $qr->additional_payload;
    }

    public function test_process_scan_clocks_in_user_on_time(): void
    {
        $scanTime = Carbon::today()->setHour(8)->setMinute(10); // 08:10 (On time)
        Carbon::setTestNow($scanTime);

        $attendance = $this->attendanceService->processScan(
            $this->user, $this->getValidQrPayloadForToday(), $scanTime->toIso8601String(),
            null, $this->device->device_identifier
        );

        $this->assertEquals(AttendanceService::STATUS_CLOCK_IN_ON_TIME, $attendance->clock_in_status);
        $this->assertEquals(0, $attendance->lateness_minutes);
        $this->assertEquals($this->schedule->id, $attendance->work_schedule_id);
        Carbon::setTestNow();
    }

    public function test_process_scan_clocks_in_user_late(): void
    {
        $scanTime = Carbon::today()->setHour(8)->setMinute(30); // 08:30 (Late)
        Carbon::setTestNow($scanTime);

        $attendance = $this->attendanceService->processScan(
            $this->user, $this->getValidQrPayloadForToday(), $scanTime->toIso8601String(),
            null, $this->device->device_identifier
        );

        $this->assertEquals(AttendanceService::STATUS_CLOCK_IN_LATE, $attendance->clock_in_status);
        $this->assertEquals(15, $attendance->lateness_minutes); // 08:30 vs (08:00 + 15 grace = 08:15)
        Carbon::setTestNow();
    }

    /**
     * Test clock-out that results in overtime.
     * Schedule: 08:00-17:00 (9h on site), 8 effective work hours (480 mins), 1 hr break.
     * Clock-in: 08:00, Clock-out: 18:00.
     */
    public function test_process_scan_clocks_out_user_resulting_in_recorded_overtime(): void
    {
        // First, clock in at 08:00:00
        $clockInTime = Carbon::today()->setHour(8)->setMinute(0)->setSecond(0);
        Carbon::setTestNow($clockInTime);
        $this->attendanceService->processScan(
            $this->user,
            $this->getValidQrPayloadForToday(),
            $clockInTime->toIso8601String(),
            null,
            $this->device->device_identifier
        );
        Carbon::setTestNow(); // Unfreeze after clock-in

        // Then, clock out at 18:00:00
        $clockOutTime = Carbon::today()->setHour(18)->setMinute(0)->setSecond(0);
        Carbon::setTestNow($clockOutTime);

        $attendance = $this->attendanceService->processScan(
            $this->user,
            $this->getValidQrPayloadForToday(),
            $clockOutTime->toIso8601String(),
            null,
            $this->device->device_identifier
        );
        Carbon::setTestNow(); // Unfreeze after clock-out

        $this->assertNotNull($attendance->clock_out_at);
        $this->assertEquals($clockOutTime->format('Y-m-d H:i:s'), $attendance->clock_out_at->format('Y-m-d H:i:s'));

        // work_duration_minutes (total time on site): 08:00 to 18:00 = 10 hours = 600 minutes.
        $this->assertEquals(600, $attendance->work_duration_minutes);

        // effective_work_minutes (actual work - break): 600 - 60 (break from schedule) = 540 minutes.
        $this->assertEquals(540, $attendance->effective_work_minutes);

        // early_leave_minutes: Scheduled end 17:00, grace 10min -> earliest normal leave 16:50. Clock out 18:00. So, 0 early leave.
        $this->assertEquals(0, $attendance->early_leave_minutes);

        // scheduled_effective_work_minutes: 8 hours * 60 = 480 minutes.
        // overtime_minutes: actual_effective (540) - scheduled_effective (480) = 60 minutes.
        // min_overtime_threshold_minutes is 30. Since 60 >= 30, overtime is 60.
        $this->assertEquals(60, $attendance->overtime_minutes); // <<< PERBAIKAN EKSPEKTASI DI SINI

        // clock_out_status: Should be Overtime.
        $this->assertEquals(AttendanceService::STATUS_CLOCK_OUT_OVERTIME, $attendance->clock_out_status);

        $this->assertStringContainsString("Clocked out successfully", $attendance->success_message);
        $this->assertStringContainsString("Overtime: 60 minutes", $attendance->success_message); // <<< PERBAIKAN EKSPEKTASI DI SINI
    }

    public function test_process_scan_clocks_out_on_time_with_minimal_excess_no_recorded_overtime(): void
    {
        Carbon::setTestNow(Carbon::today()->setHour(8)->setMinute(0));
        $this->attendanceService->processScan($this->user, $this->getValidQrPayloadForToday(), now()->toIso8601String(), null, $this->device->device_identifier);
        Carbon::setTestNow();

        $clockOutTime = Carbon::today()->setHour(17)->setMinute(5); // 17:05
        Carbon::setTestNow($clockOutTime);
        $attendance = $this->attendanceService->processScan($this->user, $this->getValidQrPayloadForToday(), $clockOutTime->toIso8601String(), null, $this->device->device_identifier);
        Carbon::setTestNow();

        $this->assertEquals(545, $attendance->work_duration_minutes); // 9h 5m
        $this->assertEquals(485, $attendance->effective_work_minutes); // 9h 5m - 1h break
        $this->assertEquals(0, $attendance->early_leave_minutes);
        // Raw overtime: 485 - 480 (scheduled effective) = 5 mins. Threshold 30.
        $this->assertEquals(0, $attendance->overtime_minutes);
        $this->assertEquals(AttendanceService::STATUS_CLOCK_OUT_ON_TIME, $attendance->clock_out_status);
    }

    public function test_process_scan_clocks_out_early(): void
    {
        Carbon::setTestNow(Carbon::today()->setHour(8)->setMinute(0));
        $this->attendanceService->processScan($this->user, $this->getValidQrPayloadForToday(), now()->toIso8601String(), null, $this->device->device_identifier);
        Carbon::setTestNow();

        $clockOutTime = Carbon::today()->setHour(16)->setMinute(30); // 16:30
        Carbon::setTestNow($clockOutTime);
        $attendance = $this->attendanceService->processScan($this->user, $this->getValidQrPayloadForToday(), $clockOutTime->toIso8601String(), null, $this->device->device_identifier);
        Carbon::setTestNow();

        $this->assertEquals(AttendanceService::STATUS_CLOCK_OUT_EARLY, $attendance->clock_out_status);
        // Earliest normal leave 16:50 (17:00 - 10 grace). Clock out 16:30. Early by 20 mins.
        $this->assertEquals(20, $attendance->early_leave_minutes);
    }

    public function test_process_scan_with_night_shift_clock_out_on_next_day(): void
    {
        $nightSchedule = WorkSchedule::factory()->create([
            'name' => 'Test Night Shift',
            'start_time' => '22:00:00',
            'end_time' => '06:00:00',
            'crosses_midnight' => true,
            'work_duration_hours' => 7,
            'break_duration_minutes' => 60,
            'grace_period_early_leave_minutes' => 10, // Tambahkan grace period
            'is_default' => false,
        ]);

        $this->workScheduleService->assignScheduleToUser($this->user, $nightSchedule, Carbon::yesterday()->subWeeks(2)->toDateString());
        Auth::shouldReceive('user')->andReturn($this->user);

        // Clock In Yesterday
        $clockInTime = Carbon::yesterday()->setHour(22)->setMinute(5);
        Carbon::setTestNow($clockInTime);
        $qrPayloadYesterday = $this->qrCodeService->generateDailyAttendanceQr('Night Location', $nightSchedule->id, ['date' => Carbon::yesterday()->toDateString()])->additional_payload;
        $this->attendanceService->processScan($this->user, $qrPayloadYesterday, $clockInTime->toIso8601String(), null, $this->device->device_identifier);
        Carbon::setTestNow();

        // Clock Out Today (part of yesterday's work_date)
        $clockOutTime = Carbon::today()->setHour(5)->setMinute(55); // 05:55
        Carbon::setTestNow($clockOutTime);
        $qrPayloadToday = $this->qrCodeService->generateDailyAttendanceQr('Night Location', $nightSchedule->id, ['date' => Carbon::today()->toDateString()])->additional_payload;

        $attendance = $this->attendanceService->processScan($this->user, $qrPayloadToday, $clockOutTime->toIso8601String(), null, $this->device->device_identifier);
        Carbon::setTestNow();

        // Test work_date mengacu ke tanggal kemarin
        $this->assertEquals(Carbon::yesterday()->toDateString(), $attendance->work_date->toDateString());
        $this->assertNotNull($attendance->clock_out_at);
        $this->assertEquals($clockOutTime->format('Y-m-d H:i:s'), $attendance->clock_out_at->format('Y-m-d H:i:s'));

        // Duration: 22:05 (yest) to 05:55 (today) = 7h 50m = 470 mins
        $this->assertEquals(470, $attendance->work_duration_minutes);

        // Effective: 470 - 60 (break) = 410 mins
        $this->assertEquals(410, $attendance->effective_work_minutes);

        // Dengan grace period 10 menit, clock out 05:55 masih dianggap on time
        // karena masih dalam batas grace period (06:00 - 10 menit = 05:50)
        $this->assertEquals(0, $attendance->early_leave_minutes);
        $this->assertEquals(AttendanceService::STATUS_CLOCK_OUT_ON_TIME, $attendance->clock_out_status);

        // Tidak ada overtime karena durasi efektif (410) < durasi yang dijadwalkan (420)
        $this->assertEquals(0, $attendance->overtime_minutes);
    }


    public function test_correct_attendance_logs_changes_and_recalculates_metrics(): void
    {
        $admin = User::factory()->admin()->create();
        Carbon::setTestNow(Carbon::today()->setHour(9)->setMinute(5)); // 09:05 (Late)
        $attendance = $this->attendanceService->processScan($this->user, $this->getValidQrPayloadForToday(), now()->toIso8601String(), null, $this->device->device_identifier);
        $originalClockIn = $attendance->clock_in_at;
        $originalLateness = $attendance->lateness_minutes; // Expected 50 mins (09:05 vs 08:15 limit)

        $this->assertEquals(50, $originalLateness);
        $this->assertEquals(AttendanceService::STATUS_CLOCK_IN_LATE, $attendance->clock_in_status);

        // Admin corrects clock_in_at to 08:00:00
        $correctionData = [
            'clock_in_at' => Carbon::parse($attendance->work_date->toDateString() . ' 08:00:00'),
            'admin_correction_notes' => 'User provided valid reason, arrived on time.',
        ];

        $correctedAttendance = $this->attendanceService->correctAttendance($attendance, $correctionData, $admin);

        $this->assertEquals('08:00:00', $correctedAttendance->clock_in_at->format('H:i:s'));
        $this->assertEquals(AttendanceService::STATUS_CLOCK_IN_ON_TIME, $correctedAttendance->clock_in_status);
        $this->assertEquals(0, $correctedAttendance->lateness_minutes);
        $this->assertTrue($correctedAttendance->is_manually_corrected);

        $this->assertDatabaseHas('attendance_correction_logs', [
            'attendance_id' => $attendance->id,
            'admin_user_id' => $admin->id,
            'changed_column' => 'clock_in_at',
            'old_value' => $originalClockIn->format('Y-m-d H:i:s'),
            'new_value' => $correctedAttendance->clock_in_at->format('Y-m-d H:i:s'),
            'reason' => 'User provided valid reason, arrived on time.',
        ]);
        $this->assertDatabaseHas('attendance_correction_logs', [
            'attendance_id' => $attendance->id,
            'changed_column' => 'lateness_minutes',
            'old_value' => (string)$originalLateness,
            'new_value' => '0',
        ]);
        $this->assertDatabaseHas('attendance_correction_logs', [
            'attendance_id' => $attendance->id,
            'changed_column' => 'clock_in_status',
            'old_value' => 'Late', // Original status
            'new_value' => 'On Time', // New status
        ]);
    }
}
