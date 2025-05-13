<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WorkSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\UserWorkScheduleAssignment>
 */
class UserWorkScheduleAssignmentFactory extends Factory
{
    public function definition(): array
    {
        $startDate = Carbon::instance($this->faker->dateTimeBetween('-1 year', '+1 month'))->startOfMonth();
        return [
            'user_id' => User::factory(),
            'work_schedule_id' => WorkSchedule::factory(),
            'effective_start_date' => $startDate->toDateString(),
            'effective_end_date' => $this->faker->boolean(70) ? $startDate->copy()->addMonths($this->faker->numberBetween(3, 12))->endOfMonth()->toDateString() : null,
            'assigned_by_user_id' => User::factory()->admin(), // Asumsi ada state admin di UserFactory
            'assignment_notes' => $this->faker->optional()->sentence,
        ];
    }
}
