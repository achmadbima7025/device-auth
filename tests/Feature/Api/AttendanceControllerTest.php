<?php

namespace Feature\Api;

use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Models\QrCode;
use App\Models\User;
use App\Models\UserDevice;
use App\Models\WorkSchedule;
use App\Services\Attendance\QrCodeService;
use App\Services\Attendance\WorkScheduleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AttendanceControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected UserDevice $device;
    protected WorkSchedule $schedule;
    protected QrCode $qrCode; // This will be the valid QR for today

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->device = UserDevice::factory()->for($this->user)->approved()->create(['device_identifier' => 'feature_test_device_456']);

        $this->schedule = WorkSchedule::factory()->create([
            'start_time' => '08:00:00', 'end_time' => '17:00:00',
            'work_duration_hours' => 8.00, 'break_duration_minutes' => 60,
            'grace_period_late_minutes' => 15, 'is_default' => true,
        ]);

        // Tidak perlu assign jika default dan service bisa handle
        // app(WorkScheduleService::class)->assignScheduleToUser($this->user, $this->schedule, Carbon::today()->subMonth()->toDateString());

        $qrCodeService = $this->app->make(QrCodeService::class);
        $this->qrCode = $qrCodeService->generateDailyAttendanceQr('Feature Test Office', $this->schedule->id);

        AttendanceSetting::factory()->createMany([
            ['key' => 'enable_gps_validation', 'value' => 'false', 'data_type' => 'boolean'],
            ['key' => 'enforce_approved_device_for_attendance', 'value' => 'true', 'data_type' => 'boolean'],
            ['key' => 'min_overtime_threshold_minutes', 'value' => '30', 'data_type' => 'integer'],
        ]);

        Sanctum::actingAs($this->user, ['*']);
    }

    private function getHeaders(): array
    {
        return [
            'Accept' => 'application/json',
            'X-Device-ID' => $this->device->device_identifier,
        ];
    }

    public function test_user_can_clock_in_successfully_via_api(): void
    {
        Carbon::setTestNow(Carbon::today()->setHour(8)->setMinute(10)); // 08:10, on time
        $payload = [
            'qr_payload' => $this->qrCode->additional_payload, // Use the payload from the generated QR
            'client_timestamp' => now()->toIso8601String(),
        ];

        $response = $this->withHeaders($this->getHeaders())->postJson('/api/attendance/scan', $payload);

        $response->assertStatus(200)
            ->assertJsonPath('data.clock_in_status', 'On Time')
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'Clocked in successfully'));

        $this->assertDatabaseHas('attendances', [
            'user_id' => $this->user->id,
            'work_date' => Carbon::today()->toDateString(),
            'clock_in_status' => 'On Time'
        ]);
        Carbon::setTestNow();
    }

    public function test_user_can_clock_out_successfully_via_api(): void
    {
        // First, clock in
        Carbon::setTestNow(Carbon::today()->setHour(8)->setMinute(0));
        $this->withHeaders($this->getHeaders())->postJson('/api/attendance/scan', [
            'qr_payload' => $this->qrCode->additional_payload,
            'client_timestamp' => now()->toIso8601String(),
        ]);
        Carbon::setTestNow();

        // Then, clock out
        Carbon::setTestNow(Carbon::today()->setHour(17)->setMinute(5)); // 17:05, on time (no overtime recorded)
        $payload = [
            'qr_payload' => $this->qrCode->additional_payload,
            'client_timestamp' => now()->toIso8601String(),
        ];
        $response = $this->withHeaders($this->getHeaders())->postJson('/api/attendance/scan', $payload);

        $response->assertStatus(200)
            ->assertJsonPath('data.clock_out_status', 'Finished On Time')
            ->assertJsonPath('data.overtime_minutes', 0) // Minimal excess, below threshold
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'Clocked out successfully'));

        $this->assertDatabaseHas('attendances', [
            'user_id' => $this->user->id,
            'work_date' => Carbon::today()->toDateString(),
            'clock_out_status' => 'Finished On Time'
        ]);
        Carbon::setTestNow();
    }

    // ... (Feature tests lainnya seperti test_scan_returns_error_for_invalid_qr_payload_type, dll.
    //      perlu disesuaikan dengan FormRequest dan respons dari service) ...

    public function test_scan_api_returns_validation_error_for_missing_qr_payload(): void
    {
        $payload = [ // qr_payload sengaja dihilangkan
            'client_timestamp' => now()->toIso8601String(),
        ];
        $response = $this->withHeaders($this->getHeaders())->postJson('/api/attendance/scan', $payload);
        $response->assertStatus(422) // HTTP Unprocessable Entity
        ->assertJsonValidationErrors(['qr_payload']);
    }

    public function test_scan_api_returns_error_if_qr_service_validation_fails(): void
    {
        // Mock QrCodeService untuk mengembalikan false (QR tidak valid)
        $mockQrService = $this->mock(QrCodeService::class);
        $mockQrService->shouldReceive('validateQrCodePayload')->once()->andReturn(false);
        $this->app->instance(QrCodeService::class, $mockQrService); // Ganti instance di container

        $payload = [
            'qr_payload' => ['type' => 'attendance_scan', 'token' => 'any_token', 'date' => today()->toDateString(), 'location_name' => 'Anywhere'],
            'client_timestamp' => now()->toIso8601String(),
        ];
        $response = $this->withHeaders($this->getHeaders())->postJson('/api/attendance/scan', $payload);
        $response->assertStatus(403) // Sesuai dengan ValidationException dari service
        ->assertJsonPath('message', 'QR Code is invalid or expired.');
    }

    public function test_get_attendance_history_api_returns_paginated_records_via_resource(): void
    {
        Attendance::factory()->count(5)->for($this->user)->forWorkSchedule($this->schedule)->create(['work_date' => Carbon::yesterday()]);
        Attendance::factory()->count(3)->for($this->user)->forWorkSchedule($this->schedule)->create(['work_date' => Carbon::today()]);

        $response = $this->withHeaders($this->getHeaders())->getJson('/api/attendance/history?per_page=4');

        $response->assertStatus(200)
            ->assertJsonCount(4, 'data') // Karena menggunakan AttendanceCollection
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'work_date', 'clock_in_at', 'clock_in_status', 'clock_out_at', 'clock_out_status', 'work_duration_formatted']
                ],
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['current_page', 'last_page', 'per_page', 'total']
            ]);
    }
}
