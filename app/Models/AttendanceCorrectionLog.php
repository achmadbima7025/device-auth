<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceCorrectionLog extends Model
{
    use HasFactory;
    protected $fillable = [
        'attendance_id',
        'admin_user_id',
        'changed_column',
        'old_value',
        'new_value',
        'reason',
        'ip_address_of_admin',
        'corrected_at', // corrected_at akan diisi otomatis jika menggunakan useCurrent() di migrasi
    ];

    protected $casts = [
        'corrected_at' => 'datetime',
        // 'old_value' dan 'new_value' bisa di-cast ke JSON jika sering menyimpan array/object
        // 'old_value' => 'json',
        // 'new_value' => 'json',
    ];

    /**
     * Mendapatkan record absensi yang dikoreksi.
     */
    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    /**
     * Mendapatkan admin yang melakukan koreksi.
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }
}
