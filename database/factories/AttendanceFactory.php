<?php

namespace Database\Factories;

use App\Models\AttendanceSetting;
use App\Models\User;
use App\Models\UserDevice;
use App\Models\WorkSchedule;
use App\Services\Attendance\WorkScheduleService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Attendance>
 */
class AttendanceFactory extends Factory
{
    public function definition(): array
    {
        $user = User::factory()->create();
        // Dapatkan jadwal aktif untuk user pada tanggal tertentu
        $workScheduleService = app(WorkScheduleService::class); // Resolve service
        
        $workDate = Carbon::instance($this->faker->dateTimeBetween('-2 months', 'now'));
        $activeSchedule = $workScheduleService->getUserActiveScheduleForDate($user, $workDate);

        if (!$activeSchedule) { // Jika tidak ada jadwal aktif, buat jadwal dummy atau default
            $activeSchedule = WorkSchedule::factory()->defaultSchedule()->create();
            // Tugaskan jadwal ini ke user untuk tanggal ini (opsional, tergantung logika Anda)
            // UserWorkScheduleAssignment::factory()->for($user)->for($activeSchedule)
            //     ->state(['effective_start_date' => $workDate->copy()->subMonth(), 'effective_end_date' => $workDate->copy()->addMonth()])
            //     ->create();
        }

        $clockInDevice = UserDevice::factory()->for($user)->approved()->create();
        $clockOutDevice = UserDevice::factory()->for($user)->approved()->create();
        // $qrCodeIn = QrCode::factory()->state(['type' => 'daily', 'valid_on_date' => $workDate->toDateString()])->create();

        // Tentukan waktu clock-in berdasarkan jadwal
        $scheduledStartTime = Carbon::parse($workDate->toDateString() . ' ' . $activeSchedule->start_time);
        // Variasi clock-in: -15 menit hingga +60 menit dari jadwal
        $clockInAt = $scheduledStartTime->copy()->addMinutes($this->faker->numberBetween(-15, 60));

        $clockInStatus = ($clockInAt->lte($scheduledStartTime->copy()->addMinutes($activeSchedule->grace_period_late_minutes ?? 0)))
            ? 'On Time' : 'Late';
        $latenessMinutes = $clockInAt->gt($scheduledStartTime->copy()->addMinutes($activeSchedule->grace_period_late_minutes ?? 0))
            ? $scheduledStartTime->copy()->addMinutes($activeSchedule->grace_period_late_minutes ?? 0)->diffInMinutes($clockInAt)
            : 0;


        $clockOutAt = null;
        $clockOutStatus = null;
        $workDurationMinutes = null;
        $effectiveWorkMinutes = null;
        $overtimeMinutes = 0;
        $earlyLeaveMinutes = 0;

        // 80% kemungkinan sudah pulang
        if ($this->faker->boolean(80) && $clockInAt->lt(Carbon::parse($workDate->toDateString() . ' ' . $activeSchedule->end_time)->addDayIf($activeSchedule->crosses_midnight))) {
            $scheduledEndTime = Carbon::parse($workDate->toDateString() . ' ' . $activeSchedule->end_time);
            if ($activeSchedule->crosses_midnight && $scheduledEndTime->lt($scheduledStartTime)) {
                $scheduledEndTime->addDay();
            }
            // Variasi clock-out: -60 menit hingga +120 menit dari jadwal akhir
            $clockOutAt = $scheduledEndTime->copy()->addMinutes($this->faker->numberBetween(-60, 120));
            // Pastikan clock_out setelah clock_in
            if ($clockOutAt->lte($clockInAt)) {
                $clockOutAt = $clockInAt->copy()->addHours(1); // Minimal 1 jam kerja
            }


            $clockOutStatus = ($clockOutAt->gte($scheduledEndTime->copy()->subMinutes($activeSchedule->grace_period_early_leave_minutes ?? 0)))
                ? 'Finished On Time' : 'Left Early';
            if ($clockOutAt->lt($scheduledEndTime->copy()->subMinutes($activeSchedule->grace_period_early_leave_minutes ?? 0))) {
                 $earlyLeaveMinutes = $clockOutAt->diffInMinutes($scheduledEndTime->copy()->subMinutes($activeSchedule->grace_period_early_leave_minutes ?? 0));
            }


            $workDurationMinutes = $clockInAt->diffInMinutes($clockOutAt);
            $effectiveWorkMinutes = $workDurationMinutes - ($activeSchedule->break_duration_minutes ?? 0);
            if ($effectiveWorkMinutes < 0) $effectiveWorkMinutes = 0;

            $scheduledWorkMinutes = (int)($activeSchedule->work_duration_hours * 60) - ($activeSchedule->break_duration_minutes ?? 0);
            if ($effectiveWorkMinutes > $scheduledWorkMinutes) {
                $overtime = $effectiveWorkMinutes - $scheduledWorkMinutes;
                $minOvertimeThreshold = (int) AttendanceSetting::getByKey('min_overtime_threshold_minutes', 0);
                $overtimeMinutes = ($overtime >= $minOvertimeThreshold) ? $overtime : 0;
                if ($overtimeMinutes > 0) $clockOutStatus = 'Overtime';
            }
        }


        return [
            'user_id' => $user->id,
            'work_date' => $workDate->toDateString(),
            'work_schedule_id' => $activeSchedule->id,
            'clock_in_at' => $clockInAt,
            'clock_in_status' => $clockInStatus,
            'clock_in_notes' => $this->faker->optional()->sentence,
            'clock_in_latitude' => $this->faker->latitude(-6.200000, -6.300000),
            'clock_in_longitude' => $this->faker->longitude(106.800000, 106.900000),
            'clock_in_device_id' => $clockInDevice->id,
            // 'clock_in_qr_code_id' => $qrCodeIn->id,
            'clock_in_method' => 'qr_scan',

            'clock_out_at' => $clockOutAt,
            'clock_out_status' => $clockOutStatus,
            'clock_out_notes' => $clockOutAt ? $this->faker->optional()->sentence : null,
            'clock_out_latitude' => $clockOutAt ? $this->faker->latitude(-6.200000, -6.300000) : null,
            'clock_out_longitude' => $clockOutAt ? $this->faker->longitude(106.800000, 106.900000) : null,
            'clock_out_device_id' => $clockOutAt ? $clockOutDevice->id : null,
            'clock_out_method' => $clockOutAt ? 'qr_scan' : null,

            'scheduled_start_time' => $activeSchedule->start_time,
            'scheduled_end_time' => $activeSchedule->end_time,
            'scheduled_work_duration_minutes' => (int)($activeSchedule->work_duration_hours * 60),

            'work_duration_minutes' => $workDurationMinutes,
            'effective_work_minutes' => $effectiveWorkMinutes,
            'overtime_minutes' => $overtimeMinutes,
            'lateness_minutes' => $latenessMinutes,
            'early_leave_minutes' => $earlyLeaveMinutes,

            'is_manually_corrected' => false,
        ];
    }

    public function notClockedOut(): static
    {
        return $this->state(fn (array $attributes) => [
            'clock_out_at' => null,
            'clock_out_status' => null,
            // ... kolom clock_out lainnya di-null-kan ...
            'work_duration_minutes' => null,
            'effective_work_minutes' => null,
            'overtime_minutes' => 0,
            'early_leave_minutes' => 0,
        ]);
    }

    public function onWorkDate(string $workDate): static
    {
        return $this->state(fn (array $attributes) => [
            'work_date' => $workDate,
        ]);
    }
}
