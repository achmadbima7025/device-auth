<?php

namespace Tests\Unit\Services;

use App\Models\QrCode;
use App\Models\WorkSchedule;
use App\Services\Attendance\QrCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Carbon\Carbon;

class QrCodeServiceTest extends TestCase
{
    use RefreshDatabase;

    protected QrCodeService $qrCodeService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->qrCodeService = $this->app->make(QrCodeService::class);
    }

    public function test_generate_daily_attendance_qr_creates_new_qr_for_today_if_none_exists(): void
    {
        $locationName = 'West Wing';
        $schedule = WorkSchedule::factory()->create();
        $qrCode = $this->qrCodeService->generateDailyAttendanceQr($locationName, $schedule->id);

        $this->assertNotNull($qrCode);
        $this->assertEquals($locationName, $qrCode->related_location_name);
        $this->assertEquals($schedule->id, $qrCode->work_schedule_id);
        $this->assertEquals(Carbon::today()->toDateString(), $qrCode->valid_on_date->toDateString());
        $this->assertTrue($qrCode->is_active);
        $this->assertArrayHasKey('token', $qrCode->additional_payload);
        $this->assertEquals($qrCode->unique_code, $qrCode->additional_payload['token']);
    }

    public function test_generate_daily_attendance_qr_returns_existing_if_already_generated_for_today_location_schedule(): void
    {
        $locationName = 'East Wing';
        $schedule = WorkSchedule::factory()->create();
        $firstQr = $this->qrCodeService->generateDailyAttendanceQr($locationName, $schedule->id);
        $secondQr = $this->qrCodeService->generateDailyAttendanceQr($locationName, $schedule->id);

        $this->assertEquals($firstQr->id, $secondQr->id);
        $this->assertEquals($firstQr->unique_code, $secondQr->unique_code);
    }

    public function test_validate_qr_code_payload_returns_qr_model_for_valid_active_qr(): void
    {
        $schedule = WorkSchedule::factory()->create();
        $qrModel = $this->qrCodeService->generateDailyAttendanceQr('Valid Location', $schedule->id);
        // Payload yang akan dikirim oleh klien (harus cocok dengan additional_payload di DB)
        $clientPayload = $qrModel->additional_payload;

        $result = $this->qrCodeService->validateQrCodePayload($clientPayload);

        $this->assertInstanceOf(QrCode::class, $result);
        $this->assertEquals($qrModel->id, $result->id);
    }

    public function test_validate_qr_code_payload_returns_false_for_mismatched_token_in_payload(): void
    {
        $this->qrCodeService->generateDailyAttendanceQr('Location X'); // Generate satu
        $payloadWithWrongToken = [
            'type' => 'attendance_scan',
            'location_name' => 'Location X',
            'date' => Carbon::today()->toDateString(),
            'token' => 'this_is_a_wrong_token', // Token tidak cocok dengan yang di DB
        ];
        $this->assertFalse($this->qrCodeService->validateQrCodePayload($payloadWithWrongToken));
    }

    public function test_validate_qr_code_payload_returns_false_for_qr_from_different_date(): void
    {
        $qrYesterday = QrCode::factory()->dailyAttendance()->create([
            'valid_on_date' => Carbon::yesterday(),
            'unique_code' => 'token_yesterday',
            'additional_payload' => [
                'type' => 'attendance_scan',
                'location_name' => 'Office',
                'date' => Carbon::yesterday()->toDateString(),
                'token' => 'token_yesterday',
            ]
        ]);
        // Klien mengirim payload yang tokennya dari kemarin, tapi date di payload adalah hari ini
        $payloadFromClient = [
            'type' => 'attendance_scan',
            'location_name' => 'Office',
            'date' => Carbon::today()->toDateString(), // Tanggal di payload tidak cocok dengan tanggal valid QR
            'token' => 'token_yesterday',
        ];
        $this->assertFalse($this->qrCodeService->validateQrCodePayload($payloadFromClient));

        // Klien mengirim payload yang token dan tanggalnya dari kemarin
        $payloadFromClientCorrectDate = $qrYesterday->additional_payload;
        $this->assertFalse($this->qrCodeService->validateQrCodePayload($payloadFromClientCorrectDate), "Should be false as QR is for yesterday, not today's scan context");
    }

    public function test_get_active_displayable_qr_code_generates_if_none_exists(): void
    {
        $location = 'New Location Display';
        $schedule = WorkSchedule::factory()->create();
        $qr = $this->qrCodeService->getActiveDisplayableQrCode($location, $schedule->id);
        $this->assertNotNull($qr);
        $this->assertEquals(today()->toDateString(), $qr->valid_on_date->toDateString());
        $this->assertEquals($location, $qr->related_location_name);
    }
}
