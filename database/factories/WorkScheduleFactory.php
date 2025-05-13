<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\WorkSchedule>
 */
class WorkScheduleFactory extends Factory
{
    // protected $model = WorkSchedule::class;

    public function definition(): array
    {
        $startTime = Carbon::createFromTime($this->faker->numberBetween(7, 10), $this->faker->numberBetween(0, 59), 0);
        $workDurationHours = $this->faker->randomElement([8, 8.5, 9]);
        $endTime = $startTime->copy()->addHours((int)floor($workDurationHours))->addMinutes(($workDurationHours - floor($workDurationHours)) * 60);
        $crossesMidnight = $endTime->dayOfWeek !== $startTime->dayOfWeek; // Cek sederhana

        return [
            'name' => $this->faker->unique()->words(3, true) . ' Shift',
            'start_time' => $startTime->format('H:i:s'),
            'end_time' => $endTime->format('H:i:s'),
            'crosses_midnight' => $crossesMidnight,
            'work_duration_hours' => $workDurationHours,
            'break_duration_minutes' => $this->faker->randomElement([0, 30, 60]),
            'monday' => $this->faker->boolean(80),
            'tuesday' => $this->faker->boolean(80),
            'wednesday' => $this->faker->boolean(80),
            'thursday' => $this->faker->boolean(80),
            'friday' => $this->faker->boolean(80),
            'saturday' => $this->faker->boolean(20),
            'sunday' => $this->faker->boolean(10),
            'grace_period_late_minutes' => $this->faker->randomElement([0, 5, 10, 15]),
            'grace_period_early_leave_minutes' => $this->faker->randomElement([0, 5, 10, 15]),
            'is_default' => false,
            'is_active' => true,
        ];
    }

    public function defaultSchedule(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Default Office Hours',
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'crosses_midnight' => false,
            'work_duration_hours' => 8.00,
            'break_duration_minutes' => 60,
            'monday' => true, 'tuesday' => true, 'wednesday' => true, 'thursday' => true, 'friday' => true,
            'saturday' => false, 'sunday' => false,
            'is_default' => true,
        ]);
    }

    public function nightShift(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Standard Night Shift',
            'start_time' => '22:00:00',
            'end_time' => '06:00:00',
            'crosses_midnight' => true,
            'work_duration_hours' => 7.00, // Misal 1 jam istirahat
            'break_duration_minutes' => 60,
        ]);
    }
}
