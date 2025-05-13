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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class QrCodeService
{
    /**
     * Men-generate QR Code harian baru.
     *
     * @param string|null $relatedLocationName
     * @param int|null $workScheduleId (Opsional, jika QR spesifik untuk jadwal)
     * @param array $additionalPayload
     * @return QrCode
     */
    public function generateDailyAttendanceQr(
        ?string $relatedLocationName = 'Main Office',
        ?int $workScheduleId = null,
        array $additionalPayload = []
    ): QrCode {
        $today = Carbon::today();

        // Invalidate QR codes lama untuk tipe, lokasi, dan jadwal yang sama (jika perlu)
        QrCode::where('type', 'daily')
            ->where('related_location_name', $relatedLocationName)
            ->where('work_schedule_id', $workScheduleId) // Filter berdasarkan jadwal juga
            ->where('valid_on_date', '<', $today)
            ->update(['is_active' => false]);

        // Cek apakah sudah ada QR untuk hari ini, lokasi, dan jadwal ini
        $query = QrCode::where('type', 'daily')
                            ->where('related_location_name', $relatedLocationName)
                            ->where('valid_on_date', $today)
                            ->where('is_active', true);
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
            'v' => 1, // Versi payload
            'type' => 'attendance_scan',
            'location_name' => $relatedLocationName,
            'date' => $today->toDateString(),
            'token' => $uniqueCode,
        ];
        if ($workScheduleId && $schedule = WorkSchedule::find($workScheduleId)) {
            $defaultPayload['schedule_name'] = $schedule->name; // Tambahkan nama jadwal ke payload
        }


        $finalPayload = array_merge($defaultPayload, $additionalPayload);

        return QrCode::create([
            'unique_code' => $uniqueCode,
            'type' => 'daily',
            'related_location_name' => $relatedLocationName,
            'work_schedule_id' => $workScheduleId,
            'additional_payload' => $finalPayload,
            'valid_on_date' => $today,
            'expires_at' => $today->copy()->endOfDay(),
            'is_active' => true,
        ]);
    }

    /**
     * Memvalidasi payload QR Code.
     *
     * @param array $qrPayload Payload yang di-decode dari QR Code.
     * @return bool|QrCode Mengembalikan model QrCode jika valid, false jika tidak.
     */
    public function validateQrCodePayload(array $qrPayload)
    {
        if (($qrPayload['type'] ?? null) !== 'attendance_scan') {
            Log::debug('QR Validation Failed: Invalid type', $qrPayload);
            return false;
        }

        $token = $qrPayload['token'] ?? null;
        $date = $qrPayload['date'] ?? null; // Tanggal dari payload QR
        $locationName = $qrPayload['location_name'] ?? null;
        // $scheduleNameFromPayload = $qrPayload['schedule_name'] ?? null; // Jika ada

        if (!$token || !$date) {
            Log::debug('QR Validation Failed: Missing token or date', $qrPayload);
            return false;
        }

        try {
            $qrDate = Carbon::parse($date)->startOfDay(); // Normalisasi ke awal hari
        } catch (\Exception $e) {
            Log::debug('QR Validation Failed: Invalid date format', $qrPayload);
            return false; // Format tanggal tidak valid
        }

        // Cek ke database, cari QR yang cocok dengan token, tipe, lokasi, dan tanggal berlaku
        // serta masih aktif dan belum kadaluarsa.
        $qrRecord = QrCode::where('unique_code', $token)
                            ->where('type', 'daily') // Asumsi QR harian untuk absensi
                            ->where('related_location_name', $locationName)
                            ->where('valid_on_date', $qrDate->toDateString())
                            ->where('is_active', true)
                            ->where(function ($q) {
                                $q->whereNull('expires_at')
                                  ->orWhere('expires_at', '>', now());
                            })
                            ->first();
        
        if (!$qrRecord) {
            Log::debug('QR Validation Failed: No matching QR record found in DB', $qrPayload);
        }

        return $qrRecord ?: false; // Kembalikan model QrCode jika ditemukan, atau false
    }

    /**
     * Mendapatkan QR Code yang aktif untuk ditampilkan (misalnya, QR harian).
     */
    public function getActiveDisplayableQrCode(?string $relatedLocationName = 'Main Office', ?int $workScheduleId = null): ?QrCode
    {
        $query = QrCode::where('type', 'daily')
            ->where('related_location_name', $relatedLocationName)
            ->where('valid_on_date', today())
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            });
        
        if ($workScheduleId) {
            $query->where('work_schedule_id', $workScheduleId);
        } else {
            $query->whereNull('work_schedule_id');
        }
        $qr = $query->first();

        // Jika tidak ada, coba generate yang baru untuk hari ini
        if (!$qr) {
            Log::info("No active QR found for {$relatedLocationName}, schedule_id: {$workScheduleId}. Attempting to generate new one.");
            try {
                 $qr = $this->generateDailyAttendanceQr($relatedLocationName, $workScheduleId);
            } catch (\Exception $e) {
                 Log::error("Gagal auto-generate QR Code aktif saat getActiveDisplayableQrCode: {$e->getMessage()}");
                 return null;
            }
        }
        return $qr;
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
            ->labelFont() // Ukuran font label disesuaikan
            ->labelAlignment(LabelAlignment::Center)
            ->validateResult(false)
            ->build();

        return $result->getDataUri();
    }
}