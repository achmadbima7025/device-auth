<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'start_time',
        'end_time',
        'crosses_midnight',
        'work_duration_hours',
        'break_duration_minutes',
        'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday',
        'grace_period_late_minutes',
        'grace_period_early_leave_minutes',
        'is_default',
        'is_active',
    ];

    protected $casts = [
        'crosses_midnight' => 'boolean',
        'work_duration_hours' => 'decimal:2',
        'monday' => 'boolean',
        'tuesday' => 'boolean',
        'wednesday' => 'boolean',
        'thursday' => 'boolean',
        'friday' => 'boolean',
        'saturday' => 'boolean',
        'sunday' => 'boolean',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        // 'start_time' => 'datetime:H:i:s', // Atau biarkan sebagai string jika hanya perlu format waktu
        // 'end_time' => 'datetime:H:i:s',
    ];

    /**
     * Mendapatkan semua penugasan jadwal kerja yang menggunakan jadwal ini.
     */
    public function userAssignments(): HasMany
    {
        return $this->hasMany(UserWorkScheduleAssignment::class);
    }

    /**
     * Mendapatkan semua record absensi yang menggunakan jadwal ini.
     */
    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }
}
