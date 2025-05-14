<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class WorkSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'start_time', // HH:MM:SS
        'end_time',   // HH:MM:SS
        'crosses_midnight',
        'work_duration_hours',      // PENTING: Ini adalah durasi kerja KOTOR (GROSS) dalam jam, TERMASUK jam istirahat.
        'break_duration_minutes',
        'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday',
        'grace_period_late_minutes',
        'grace_period_early_leave_minutes',
        'is_default',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
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
        ];
    }

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

    // OPSIONAL: Mutator untuk memastikan `work_duration_hours` disimpan dengan benar
    // atau validasi tambahan saat menyimpan.
    // Contoh sederhana untuk validasi (bisa diperluas di observer atau request validation):
     public static function boot(): void
     {
         parent::boot();
         static::saving(function ($model) {
             if ($model->start_time && $model->end_time) {
                 $startTime = Carbon::parse($model->start_time);
                 $endTime = Carbon::parse($model->end_time);
                 if ($model->crosses_midnight && $endTime->lt($startTime)) {
                     $endTime->addDay();
                 }
                 $grossDurationMinutes = $startTime->diffInMinutes($endTime);
                 $expectedNetWorkMinutes = $grossDurationMinutes - $model->break_duration_minutes;

                 // Jika work_duration_hours diisi sebagai durasi kotor, maka hitung net-nya.
                 // Atau, jika diisi sebagai net, pastikan konsisten.
                 // Untuk saat ini, kita asumsikan input 'work_duration_hours' adalah NET.
                 // Anda bisa menambahkan validasi di sini jika 'work_duration_hours' * 60 != $expectedNetWorkMinutes
                 // throw new \Exception("Work duration hours does not match calculated net duration.");
             }
         });
     }

    /**
     * Accessor untuk menghitung durasi kerja kotor terjadwal dalam menit.
     * Ini akan konsisten dengan `work_duration_hours * 60`.
     */
    public function getScheduledGrossDurationMinutesAttribute(): int
    {
        return (int) ($this->work_duration_hours * 60);
    }

    /**
     * Accessor untuk mendapatkan durasi kerja bersih terjadwal dalam menit.
     * Dihitung dari durasi kotor dikurangi istirahat.
     */
    public function getScheduledNetWorkMinutesAttribute(): int
    {
        $grossWorkMinutes = (int) ($this->work_duration_hours * 60);
        return max(0, $grossWorkMinutes - $this->break_duration_minutes);
    }

    /**
     * OPSIONAL: Untuk validasi atau kemudahan, Anda bisa juga memiliki accessor
     * untuk durasi aktual dari start_time ke end_time jika work_duration_hours
     * tidak selalu diisi manual dan ingin dihitung dari start/end.
     */
    public function getCalculatedGrossDurationMinutesFromTimesAttribute(): ?int
    {
        if (!$this->start_time || !$this->end_time) {
            return null;
        }
        try {
            $startTime = Carbon::parse($this->start_time);
            $endTime = Carbon::parse($this->end_time);

            if ($this->crosses_midnight && $endTime->lt($startTime)) {
                $endTime->addDay();
            }
            return $startTime->diffInMinutes($endTime);
        } catch (\Exception $e) {
            return null;
        }
    }
}
