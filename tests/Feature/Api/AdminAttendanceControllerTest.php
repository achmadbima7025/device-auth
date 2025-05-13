<?php

namespace Feature\Api;

use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Models\User;
use App\Models\UserDevice;
use App\Models\WorkSchedule;
use App\Services\Attendance\AttendanceService;
use App\Services\Attendance\WorkScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminAttendanceControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected UserDevice $adminDevice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        $this->adminDevice = UserDevice::factory()->for($this->admin)->approved()->create();
        Sanctum::actingAs($this->admin);

        AttendanceSetting::factory()->createMany([
            ['key' => 'enable_gps_validation', 'value' => 'false', 'data_type' => 'boolean'],
            ['key' => 'late_tolerance_minutes', 'value' => '15', 'data_type' => 'integer'],
            ['key' => 'min_overtime_threshold_minutes', 'value' => '30', 'data_type' => 'integer'],
        ]);
    }

    private function getAdminHeaders(): array
    {
        return [
            'Accept' => 'application/json',
            'X-Device-ID' => $this->adminDevice->device_identifier,
        ];
    }

    public function test_admin_can_view_attendance_reports_paginated(): void
    {
        $user1 = User::factory()->create();
        $schedule = WorkSchedule::factory()->create();
        // Pastikan user memiliki assignment agar factory Attendance bisa mengambil schedule
        app(WorkScheduleService::class)->assignScheduleToUser($user1, $schedule, today()->subMonth());

        Attendance::factory()->count(20)->for($user1)->forWorkSchedule($schedule)->create();

        $response = $this->withHeaders($this->getAdminHeaders())->getJson('/api/admin/attendance/reports?per_page=10');

        $response->assertStatus(200)
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.total', 20)
            ->assertJsonPath('meta.per_page', 10);
    }

    public function test_admin_can_make_attendance_correction_via_api(): void
    {
        $user = User::factory()->create();
        $schedule = WorkSchedule::factory()->create(['start_time' => '09:00:00', 'grace_period_late_minutes' => 0]);
        app(WorkScheduleService::class)->assignScheduleToUser($user, $schedule, today()->subMonth());

        $attendance = Attendance::factory()->for($user)->forWorkSchedule($schedule)->create([
            'work_date' => Carbon::today(),
            'clock_in_at' => Carbon::today()->setHour(9)->setMinute(30), // Awalnya telat
            'clock_in_status' => AttendanceService::STATUS_CLOCK_IN_LATE,
            'lateness_minutes' => 30,
        ]);

        $correctionPayload = [
            'clock_in_at_time' => '09:00:00', // Dikoreksi jadi tepat waktu
            'clock_in_status' => AttendanceService::STATUS_CLOCK_IN_ON_TIME, // Status dikoreksi
            'admin_correction_notes' => 'User confirmed arrival at 09:00, system glitch.',
        ];

        $response = $this->withHeaders($this->getAdminHeaders())
            ->postJson("/api/admin/attendance/corrections/{$attendance->id}", $correctionPayload);

        $response->assertStatus(200)
            ->assertJsonPath('data.clock_in_at', Carbon::today()->toDateString() . ' 09:00:00')
            ->assertJsonPath('data.clock_in_status', AttendanceService::STATUS_CLOCK_IN_ON_TIME)
            ->assertJsonPath('data.lateness_minutes', 0) // Harusnya dihitung ulang jadi 0
            ->assertJsonPath('data.is_manually_corrected', true);

        $this->assertDatabaseHas('attendance_correction_logs', [
            'attendance_id' => $attendance->id,
            'admin_user_id' => $this->admin->id,
            'changed_column' => 'clock_in_at', // Salah satu yang berubah
            'reason' => 'User confirmed arrival at 09:00, system glitch.',
        ]);
    }

    public function test_admin_can_get_all_attendance_settings(): void
    {
        AttendanceSetting::factory()->specificSetting('test_key_1', 'value1', 'string', 'Desc 1', 'group1')->create();
        AttendanceSetting::factory()->specificSetting('test_key_2', 'true', 'boolean', 'Desc 2', 'group1')->create();
        AttendanceSetting::factory()->specificSetting('test_key_3', '120', 'integer', 'Desc 3', 'group2')->create();

        $response = $this->withHeaders($this->getAdminHeaders())->getJson('/api/admin/attendance/settings');
        $response->assertStatus(200)
            ->assertJsonPath('group1.test_key_1', 'value1')
            ->assertJsonPath('group1.test_key_2', true) // Accessor akan meng-cast ke boolean
            ->assertJsonPath('group2.test_key_3', 120); // Accessor akan meng-cast ke integer
    }

    public function test_admin_can_update_attendance_settings_via_api(): void
    {
        AttendanceSetting::factory()->specificSetting('late_tolerance_minutes', '15', 'integer', group: 'schedule')->create();
        AttendanceSetting::factory()->specificSetting('enable_gps_validation', 'false', 'boolean', group: 'gps')->create();

        $payload = [
            'settings' => [ // Data dikirim dalam nested 'settings' key
                'late_tolerance_minutes' => '25', // Diubah dari 15
                'enable_gps_validation' => 'true'  // Diubah dari false
            ]
        ];
        $response = $this->withHeaders($this->getAdminHeaders())->postJson('/api/admin/attendance/settings', $payload);

        $response->assertStatus(200)
            ->assertJson(['message' => 'Attendance settings updated successfully.']);

        $this->assertEquals('25', AttendanceSetting::getByKey('late_tolerance_minutes'));
        $this->assertTrue(AttendanceSetting::getByKey('enable_gps_validation'));
    }

    // --- Test untuk WorkSchedule Management ---
    public function test_admin_can_create_work_schedule_via_api(): void
    {
        $scheduleData = WorkSchedule::factory()->make()->toArray(); // Buat data tanpa menyimpan
        // Pastikan boolean dikirim sebagai boolean atau string 'true'/'false' yang dikenali FormRequest
        foreach(['crosses_midnight', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday', 'is_default', 'is_active'] as $boolKey) {
            if(isset($scheduleData[$boolKey])) {
                $scheduleData[$boolKey] = (bool) $scheduleData[$boolKey];
            }
        }


        $response = $this->withHeaders($this->getAdminHeaders())->postJson('/api/admin/work-schedules', $scheduleData);
        $response->assertStatus(201)
            ->assertJsonPath('data.name', $scheduleData['name']);
        $this->assertDatabaseHas('work_schedules', ['name' => $scheduleData['name']]);
    }

    public function test_admin_can_assign_schedule_to_user_via_api(): void
    {
        $userToAssign = User::factory()->create();
        $scheduleToAssign = WorkSchedule::factory()->create();
        $payload = [
            'user_id' => $userToAssign->id,
            'work_schedule_id' => $scheduleToAssign->id,
            'effective_start_date' => Carbon::today()->toDateString(),
            'assignment_notes' => 'Test assignment via API'
        ];

        $response = $this->withHeaders($this->getAdminHeaders())->postJson('/api/admin/work-schedules/assign-to-user', $payload);
        $response->assertStatus(201)
            ->assertJsonPath('data.user_id', $userToAssign->id)
            ->assertJsonPath('data.work_schedule_id', $scheduleToAssign->id);
        $this->assertDatabaseHas('user_work_schedule_assignments', [
            'user_id' => $userToAssign->id,
            'work_schedule_id' => $scheduleToAssign->id,
        ]);
    }
}
