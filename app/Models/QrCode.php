<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QrCode extends Model
{
    use HasFactory;

    public const string TYPE_DAILY = 'daily';
    public const string TYPE_STATIC_LOCATION = 'static_location';
    public const string TYPE_SINGLE_USE = 'single_use';
    public const string TYPE_SHIFT_SPECIFIC = 'shift_specific';

    protected $fillable = [
        'is_active',
        'used_by_user_id',
        'used_at',
        'expires_at',
        'valid_on_date',
        'additional_payload',
        'work_schedule_id',
        'related_location_name',
        'type',
        'unique_code',
    ];

    protected $casts = [
        'additional_payload' => 'array',
        'valid_on_date' => 'date',
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    /**
     * Pengguna yang menggunakan QR code (jika sekali pakai).
     */
    public function userWhoUsed(): BelongsTo
    {
        return $this->belongsTo(User::class, 'used_by_user_id');
    }

    /**
     * Jadwal kerja terkait QR code ini (jika ada).
     */
    public function workSchedule(): BelongsTo
    {
        return $this->belongsTo(WorkSchedule::class);
    }

    /**
     * Scope untuk mendapatkan QR Code yang aktif.
     */
    /**
     * Scope untuk mendapatkan QR Code yang aktif.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    /**
     * Scope untuk mendapatkan QR Code harian yang valid untuk hari ini.
     */
    public function scopeValidToday($query)
    {
        return $query->where('type', self::TYPE_DAILY) // Menggunakan konstanta
            ->where('valid_on_date', today());
    }
}
