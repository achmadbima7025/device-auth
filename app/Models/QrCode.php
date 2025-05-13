<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QrCode extends Model
{
    use HasFactory;

    protected $fillable = [
        'unique_code',
        'type',
        'related_location_name',
        'work_schedule_id', // Kolom baru ditambahkan
        'additional_payload',
        'valid_on_date',
        'expires_at',
        'used_at',
        'used_by_user_id',
        'is_active',
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
        return $query->where('type', 'daily')
                     ->where('valid_on_date', today());
    }
}
