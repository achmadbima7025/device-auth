<?php

namespace App\Services\Attendance;

use App\Models\User;
use App\Models\UserWorkScheduleAssignment;
use App\Models\WorkSchedule;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class WorkScheduleService
{
    /**
     * Mendapatkan jadwal kerja yang aktif untuk pengguna pada tanggal tertentu.
     *
     * @param User $user
     * @param string|Carbon|null $date Tanggal untuk mencari jadwal, default hari ini.
     * @return WorkSchedule|null Jadwal kerja yang aktif, atau jadwal default, atau null.
     */
    public function getUserActiveScheduleForDate(User $user, Carbon|string|null $date = null): ?WorkSchedule
    {
        $targetDate = $date ? Carbon::parse($date)->toDateString() : Carbon::today()->toDateString();

        $assignment = UserWorkScheduleAssignment::where('user_id', $user->id)->where('effective_start_date', '<=', $targetDate)->where(function ($query) use ($targetDate) {
                $query->whereNull('effective_end_date')->orWhere('effective_end_date', '>=', $targetDate);
            })->orderBy('effective_start_date', 'desc') // Ambil yang paling relevan jika ada overlap (seharusnya dicegah)
            ->first();

        if ($assignment) {
            return $assignment->workSchedule()->where('is_active', true)->first();
        }

        // Jika tidak ada penugasan spesifik, cari jadwal default yang aktif
        return WorkSchedule::where('is_default', true)->where('is_active', true)->first();
    }

    /**
     * Menugaskan jadwal kerja ke pengguna.
     * Akan mengakhiri jadwal sebelumnya jika ada.
     *
     * @param User $user
     * @param WorkSchedule $workSchedule
     * @param string $effectiveStartDate (Y-m-d)
     * @param string|null $effectiveEndDate (Y-m-d)
     * @param User|null $assignedBy Admin/Manajer yang menugaskan
     * @param string|null $notes Catatan penugasan
     * @return UserWorkScheduleAssignment
     */
    public function assignScheduleToUser(User $user, WorkSchedule $workSchedule, string $effectiveStartDate, ?string $effectiveEndDate = null, ?User $assignedBy = null, ?string $notes = null): UserWorkScheduleAssignment
    {
        $parsedStartDate = Carbon::parse($effectiveStartDate);

        // Akhiri penugasan aktif sebelumnya untuk pengguna ini jika ada dan tumpang tindih
        UserWorkScheduleAssignment::where('user_id', $user->id)->where(function ($query) use ($parsedStartDate) {
                $query->whereNull('effective_end_date') // Yang masih aktif tanpa batas akhir
                ->orWhere('effective_end_date', '>=', $parsedStartDate->toDateString()); // Yang berakhir pada atau setelah jadwal baru dimulai
            })->where('effective_start_date', '<', $parsedStartDate->toDateString()) // Hanya yang dimulai sebelum jadwal baru
            ->update(['effective_end_date' => $parsedStartDate->copy()->subDay()->toDateString()]);

        return UserWorkScheduleAssignment::create(['user_id' => $user->id, 'work_schedule_id' => $workSchedule->id, 'effective_start_date' => $parsedStartDate->toDateString(), 'effective_end_date' => $effectiveEndDate ? Carbon::parse($effectiveEndDate)->toDateString() : null, 'assigned_by_user_id' => $assignedBy?->id, 'assignment_notes' => $notes,]);
    }

    /**
     * Mendapatkan semua jadwal kerja yang aktif.
     */
    public function getActiveWorkSchedules(): Collection
    {
        return WorkSchedule::where('is_active', true)->orderBy('name')->get();
    }

    /**
     * Membuat jadwal kerja baru.
     */
    public function createWorkSchedule(array $data): WorkSchedule
    {
        // Pastikan hanya ada satu default jika is_default = true
        if (isset($data['is_default']) && $data['is_default']) {
            WorkSchedule::where('is_default', true)->update(['is_default' => false]);
        }
        return WorkSchedule::create($data);
    }

    /**
     * Memperbarui jadwal kerja.
     */
    public function updateWorkSchedule(WorkSchedule $workSchedule, array $data): WorkSchedule
    {
        // Pastikan hanya ada satu default jika is_default = true
        if (isset($data['is_default']) && $data['is_default'] && $workSchedule->is_default == false) {
            WorkSchedule::where('is_default', true)->where('id', '!=', $workSchedule->id)->update(['is_default' => false]);
        }
        $workSchedule->update($data);
        return $workSchedule;
    }
}
