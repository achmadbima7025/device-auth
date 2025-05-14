<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Attendance extends Model
{
    use HasFactory;

    public string $success_message;

    protected $fillable = [
        'user_id',
        'work_date',
        'work_schedule_id',
        'clock_in_at',
        'clock_in_status',
        'clock_in_notes',
        'clock_in_latitude',
        'clock_in_longitude',
        'clock_in_device_id',
        'clock_in_qr_code_id',
        'clock_in_method',
        'clock_out_at',
        'clock_out_status',
        'clock_out_notes',
        'clock_out_latitude',
        'clock_out_longitude',
        'clock_out_device_id',
        'clock_out_qr_code_id',
        'clock_out_method',
        'scheduled_start_time',
        'scheduled_end_time',
        'scheduled_work_duration_minutes',
        'work_duration_minutes',
        'effective_work_minutes',
        'overtime_minutes',
        'lateness_minutes',
        'early_leave_minutes',
        'is_manually_corrected',
        'last_corrected_by',
        'last_correction_at',
        'correction_summary_notes',];

    protected $casts = [
        'work_date' => 'date', 'clock_in_at' => 'datetime', 'clock_out_at' => 'datetime', 'clock_in_latitude' => 'decimal:7', 'clock_in_longitude' => 'decimal:7', 'clock_out_latitude' => 'decimal:7', 'clock_out_longitude' => 'decimal:7', // 'scheduled_start_time' => 'datetime:H:i:s', // Biarkan string atau parse di accessor jika perlu
        // 'scheduled_end_time' => 'datetime:H:i:s',
        'is_manually_corrected' => 'boolean', 'last_correction_at' => 'datetime',
    ];

    /**
     * Mendapatkan pengguna yang memiliki absensi ini.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Mendapatkan jadwal kerja yang berlaku untuk absensi ini.
     */
    public function workSchedule(): BelongsTo
    {
        return $this->belongsTo(WorkSchedule::class);
    }

    /**
     * Mendapatkan perangkat yang digunakan untuk absen masuk.
     */
    public function clockInDevice(): BelongsTo
    {
        return $this->belongsTo(UserDevice::class, 'clock_in_device_id');
    }

    /**
     * Mendapatkan perangkat yang digunakan untuk absen pulang.
     */
    public function clockOutDevice(): BelongsTo
    {
        return $this->belongsTo(UserDevice::class, 'clock_out_device_id');
    }

    /**
     * Mendapatkan QR code yang digunakan untuk absen masuk.
     */
    public function clockInQrCode(): BelongsTo
    {
        return $this->belongsTo(QrCode::class, 'clock_in_qr_code_id');
    }

    /**
     * Mendapatkan QR code yang digunakan untuk absen pulang.
     */
    public function clockOutQrCode(): BelongsTo
    {
        return $this->belongsTo(QrCode::class, 'clock_out_qr_code_id');
    }

    /**
     * Mendapatkan admin yang terakhir melakukan koreksi.
     */
    public function lastCorrector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_corrected_by');
    }

    /**
     * Mendapatkan semua log koreksi untuk record absensi ini.
     */
    public function correctionLogs(): HasMany
    {
        return $this->hasMany(AttendanceCorrectionLog::class);
    }


    /**
     * Accessor untuk mendapatkan durasi kerja dalam format Jam:Menit.
     */
    public function getWorkDurationFormattedAttribute(): ?string
    {
        if (is_null($this->work_duration_minutes) || $this->work_duration_minutes < 0) { // Handle jika negatif (error data)
            return '00 Jam 00 Menit';
        }
        $hours = floor($this->work_duration_minutes / 60);
        $minutes = $this->work_duration_minutes % 60;
        return sprintf('%02d Jam %02d Menit', $hours, $minutes);
    }

    /**
     * Accessor untuk mendapatkan durasi kerja efektif dalam format Jam:Menit.
     */
    public function getEffectiveWorkFormattedAttribute(): ?string
    {
        if (is_null($this->effective_work_minutes) || $this->effective_work_minutes < 0) {
            return '00 Jam 00 Menit';
        }
        $hours = floor($this->effective_work_minutes / 60);
        $minutes = $this->effective_work_minutes % 60;
        return sprintf('%02d Jam %02d Menit', $hours, $minutes);
    }

    /**
     * Scope untuk mendapatkan absensi pada rentang tanggal kerja tertentu.
     */
    public function scopeOnWorkDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('work_date', [$startDate, $endDate]);
    }

    /**
     * Scope untuk mendapatkan absensi yang belum pulang.
     */
    public function scopeNotClockedOut($query)
    {
        return $query->whereNotNull('clock_in_at')->whereNull('clock_out_at');
    }

    /**
     * Scope untuk mendapatkan absensi yang sudah lengkap (masuk dan pulang).
     */
    public function scopeCompleted($query)
    {
        return $query->whereNotNull('clock_in_at')->whereNotNull('clock_out_at');
    }
}
