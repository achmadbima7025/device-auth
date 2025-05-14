<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = ['name', 'email', 'password', 'role',];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = ['password', 'remember_token',];

    public function devices(): HasMany
    {
        return $this->hasMany(UserDevice::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Mendapatkan semua record absensi milik pengguna ini.
     */
    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(Attendance::class)->orderBy('work_date', 'desc')->orderBy('clock_in_at', 'desc');
    }

    /**
     * Mendapatkan jadwal kerja yang aktif saat ini untuk pengguna.
     * Ini adalah contoh logika, mungkin perlu penyesuaian.
     */
    public function currentWorkSchedule(): ?WorkSchedule
    {
        $today = now()->toDateString();
        $assignment = $this->workScheduleAssignments()->where('effective_start_date', '<=', $today)->where(function ($query) use ($today) {
                $query->whereNull('effective_end_date')->orWhere('effective_end_date', '>=', $today);
            })->orderBy('effective_start_date', 'desc') // Ambil yang paling baru jika ada overlap (seharusnya tidak)
            ->first();

        return $assignment ? $assignment->workSchedule : WorkSchedule::where('is_default', true)->where('is_active', true)->first();
    }

    /**
     * Mendapatkan semua penugasan jadwal kerja untuk pengguna ini.
     */
    public function workScheduleAssignments(): HasMany
    {
        return $this->hasMany(UserWorkScheduleAssignment::class)->orderBy('effective_start_date', 'desc');
    }

    /**
     * Mendapatkan log koreksi absensi yang dilakukan oleh admin ini.
     */
    public function attendanceCorrectionsMade(): HasMany
    {
        return $this->hasMany(AttendanceCorrectionLog::class, 'admin_user_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed',];
    }
}
