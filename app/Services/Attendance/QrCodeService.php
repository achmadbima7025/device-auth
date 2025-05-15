<?php

namespace App\Services\Attendance;

use Exception;
use App\Models\QrCode;
use Illuminate\Support\Str;
use App\Models\WorkSchedule;
use Illuminate\Support\Carbon;

use Illuminate\Support\Facades\Log;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Label\LabelAlignment;

class QrCodeService
{
    public function validateQrCodePayloadForWorkDate(array $qrPayload, Carbon $effectiveWorkDate): QrCode|false
    {
        if (($qrPayload['type'] ?? null) !== 'attendance_scan') {
            Log::debug('QR Validation Failed: Invalid payload type', $qrPayload);
            return false;
        }

        $token = $qrPayload['token'] ?? null;
        $payloadDateStr = $qrPayload['date'] ?? null;
        $locationName = $qrPayload['location_name'] ?? null;
        $payloadScheduleId = $qrPayload['schedule_id'] ?? null;


        if (!$token || !$payloadDateStr) {
            Log::debug('QR Validation Failed: Missing token or date in payload', $qrPayload);
            return false;
        }

        try {
            $payloadDate = Carbon::parse($payloadDateStr)->startOfDay();
        } catch (Exception $e) {
            Log::debug('QR Validation Failed: Invalid date format in payload', $qrPayload);
            return false;
        }

        if ($payloadDate->ne($effectiveWorkDate->copy()->startOfDay())) {
            Log::debug('QR Validation Failed: Payload date does not match effective work date.', ['payload_date' => $payloadDate->toDateString(), 'effective_work_date' => $effectiveWorkDate->toDateString()]);
            return false;
        }

        $query = QrCode::where('unique_code', $token)->where('type', QrCode::TYPE_DAILY)
                       ->where('related_location_name', $locationName)->where('valid_on_date', $effectiveWorkDate->toDateString())
                       ->where('is_active', true)->where(function ($q) {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });

        if ($payloadScheduleId) {
            $query->where('work_schedule_id', $payloadScheduleId);
        }

        $qrRecord = $query->first();

        if (!$qrRecord) {
            Log::debug('QR Validation Failed: No matching QR record found in DB for token and effective work date.', ['token' => $token, 'effective_work_date' => $effectiveWorkDate->toDateString(), 'location_name' => $locationName, 'payload_schedule_id' => $payloadScheduleId]);
        }

        return $qrRecord ?: false;
    }

    public function getActiveDisplayableQrCode(?string $relatedLocationName = 'Main Office Default', ?int $workScheduleId = null): ?QrCode
    {
        $query = QrCode::where('type', QrCode::TYPE_DAILY)
                       ->where('related_location_name', $relatedLocationName)->where('valid_on_date', today())->where('is_active', true)->where(function ($q) {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });

        if ($workScheduleId) {
            $query->where('work_schedule_id', $workScheduleId);
        } else {
            $query->whereNull('work_schedule_id');
        }
        $qr = $query->first();

        if (!$qr) {
            Log::info("No active QR found for {$relatedLocationName}, schedule_id: {$workScheduleId}. Attempting to generate new one.");
            try {
                $qr = $this->generateDailyAttendanceQr($relatedLocationName, $workScheduleId);
            } catch (Exception $e) {
                Log::error("Failed to auto-generate active QR Code during getActiveDisplayableQrCode: {$e->getMessage()}");
                return null;
            }
        }
        return $qr;
    }

    public function generateDailyAttendanceQr(?string $relatedLocationName = 'Main Office', ?int $workScheduleId = null, array $additionalPayload = []): QrCode
    {
        $today = Carbon::today();

        QrCode::where('type', QrCode::TYPE_DAILY)
              ->where('related_location_name', $relatedLocationName)->where('work_schedule_id', $workScheduleId)->where('valid_on_date', '<', $today)->update(['is_active' => false]);

        $query = QrCode::where('type', QrCode::TYPE_DAILY)
                       ->where('related_location_name', $relatedLocationName)->where('valid_on_date', $today)->where('is_active', true);
        if ($workScheduleId) {
            $query->where('work_schedule_id', $workScheduleId);
        } else {
            $query->whereNull('work_schedule_id');
        }
        $existingQr = $query->first();

        if ($existingQr) {
            return $existingQr;
        }

        $uniqueCode = Str::uuid()->toString();
        $defaultPayload = [
            'v' => 1, 'type' => 'attendance_scan',
            'location_name' => $relatedLocationName, 'date' => $today->toDateString(), 'token' => $uniqueCode,
        ];
        if ($workScheduleId && $schedule = WorkSchedule::find($workScheduleId)) {
            $defaultPayload['schedule_name'] = $schedule->name;
            $defaultPayload['schedule_id'] = $schedule->id;
        }


        $finalPayload = array_merge($defaultPayload, $additionalPayload);

        return QrCode::create([
            'unique_code' => $uniqueCode, 'type' => QrCode::TYPE_DAILY,
            'related_location_name' => $relatedLocationName, 'work_schedule_id' => $workScheduleId, 'additional_payload' => $finalPayload, 'valid_on_date' => $today, 'expires_at' => $today->copy()->endOfDay(), 'is_active' => true,
        ]);
    }

    public function generateQrCodeImageUri(string $data, string $label = ''): string
    {
        $result = new Builder(
            writer: new PngWriter(),
            writerOptions: [],
            validateResult: false,
            data: $data,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: 300,
            margin: 10,
            roundBlockSizeMode: RoundBlockSizeMode::Margin,
            labelText: $label,
            labelAlignment: LabelAlignment::Center,
        );

        return $result->build()->getDataUri();
    }
}
