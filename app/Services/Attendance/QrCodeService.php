<?php

namespace App\Services\Attendance;

use App\Models\QrCode;
use App\Models\WorkSchedule;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Label\LabelAlignment;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class QrCodeService
{
    /**
     * Memvalidasi payload QR Code untuk tanggal kerja tertentu.
     *
     * @param array $qrPayload Payload yang di-decode dari QR Code.
     * @param Carbon $effectiveWorkDate Tanggal kerja efektif untuk validasi.
     * @return QrCode|false Mengembalikan model QrCode jika valid, false jika tidak.
     */
    public function validateQrCodePayloadForWorkDate(array $qrPayload, Carbon $effectiveWorkDate): QrCode|false
    {
        if (($qrPayload['type'] ?? null) !== 'attendance_scan') {
            Log::debug('QR Validation Failed: Invalid payload type', $qrPayload);
            return false;
        }

        $token = $qrPayload['token'] ?? null;
        $payloadDateStr = $qrPayload['date'] ?? null; // Tanggal dari payload QR
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

        // QR Code harian harus cocok tanggalnya dengan tanggal kerja efektif
        if ($payloadDate->ne($effectiveWorkDate->copy()->startOfDay())) {
            Log::debug('QR Validation Failed: Payload date does not match effective work date.', ['payload_date' => $payloadDate->toDateString(), 'effective_work_date' => $effectiveWorkDate->toDateString()]);
            return false;
        }

        $query = QrCode::where('unique_code', $token)->where('type', QrCode::TYPE_DAILY) // Hanya validasi QR harian dengan cara ini
            ->where('related_location_name', $locationName)->where('valid_on_date', $effectiveWorkDate->toDateString()) // Cocokkan dengan tanggal kerja efektif
            ->where('is_active', true)->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });

        // Jika ada schedule_id di payload, QR record juga harus memiliki schedule_id yang sama
        if ($payloadScheduleId) {
            $query->where('work_schedule_id', $payloadScheduleId);
        } else {
            // Jika tidak ada schedule_id di payload, QR bisa saja general (work_schedule_id = null)
            // atau bisa juga untuk schedule tertentu jika sistem mengizinkan.
            // Untuk keamanan, jika QR payload tidak spesifik schedule, mungkin hanya match dengan QR general.
            // Ini tergantung business logic. Untuk saat ini, kita anggap bisa general.
            // $query->whereNull('work_schedule_id'); // Bisa lebih ketat
        }

        $qrRecord = $query->first();

        if (!$qrRecord) {
            Log::debug('QR Validation Failed: No matching QR record found in DB for token and effective work date.', ['token' => $token, 'effective_work_date' => $effectiveWorkDate->toDateString(), 'location_name' => $locationName, 'payload_schedule_id' => $payloadScheduleId]);
        }

        return $qrRecord ?: false;
    }

    /**
     * Mendapatkan QR Code yang aktif untuk ditampilkan (misalnya, QR harian).
     */
    public function getActiveDisplayableQrCode(?string $relatedLocationName = 'Main Office Default', ?int $workScheduleId = null): ?QrCode
    {
        $query = QrCode::where('type', QrCode::TYPE_DAILY) // Menggunakan konstanta
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
                Log::error("Gagal auto-generate QR Code aktif saat getActiveDisplayableQrCode: {$e->getMessage()}");
                return null;
            }
        }
        return $qr;
    }

    /**
     * Men-generate QR Code harian baru.
     *
     * @param string|null $relatedLocationName
     * @param int|null $workScheduleId (Opsional, jika QR spesifik untuk jadwal)
     * @param array $additionalPayload
     * @return QrCode
     */
    public function generateDailyAttendanceQr(?string $relatedLocationName = 'Main Office', ?int $workScheduleId = null, array $additionalPayload = []): QrCode
    {
        $today = Carbon::today();

        QrCode::where('type', QrCode::TYPE_DAILY) // Menggunakan konstanta
        ->where('related_location_name', $relatedLocationName)->where('work_schedule_id', $workScheduleId)->where('valid_on_date', '<', $today)->update(['is_active' => false]);

        $query = QrCode::where('type', QrCode::TYPE_DAILY) // Menggunakan konstanta
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
        $defaultPayload = ['v' => 1, 'type' => 'attendance_scan', // Ini adalah tipe payload, bukan tipe QrCode model
            'location_name' => $relatedLocationName, 'date' => $today->toDateString(), 'token' => $uniqueCode,];
        if ($workScheduleId && $schedule = WorkSchedule::find($workScheduleId)) {
            $defaultPayload['schedule_name'] = $schedule->name;
            $defaultPayload['schedule_id'] = $schedule->id; // Tambahkan ID jadwal ke payload juga
        }


        $finalPayload = array_merge($defaultPayload, $additionalPayload);

        return QrCode::create(['unique_code' => $uniqueCode, 'type' => QrCode::TYPE_DAILY, // Menggunakan konstanta
            'related_location_name' => $relatedLocationName, 'work_schedule_id' => $workScheduleId, 'additional_payload' => $finalPayload, 'valid_on_date' => $today, 'expires_at' => $today->copy()->endOfDay(), 'is_active' => true,]);
    }

    /**
     * Menghasilkan gambar QR Code dari data (misalnya, payload JSON).
     */
    public function generateQrCodeImageUri(string $data, string $label = ''): string
    {
        $result = Builder::create()
                         ->writer(new PngWriter())
                         ->writerOptions([])
                         ->data($data)
                         ->encoding(new Encoding('UTF-8'))
                         ->errorCorrectionLevel(ErrorCorrectionLevel::High)
                         ->size(300)
                         ->margin(10)
                         ->roundBlockSizeMode(RoundBlockSizeMode::Margin)
                         ->labelText($label)
                         ->labelAlignment(LabelAlignment::Center)
                         ->validateResult(false)
                         ->build();

        return $result->getDataUri();
    }
}
