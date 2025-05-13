<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WorkSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\QrCode>
 */
class QrCodeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = $this->faker->randomElement(['daily', 'static_location', 'single_use']);
        $uniqueCode = Str::uuid()->toString();
        $relatedLocationName = $this->faker->company . ' ' . $this->faker->randomElement(['Office', 'Gate', 'Entrance']);
        $validOnDate = null;
        $expiresAt = null;
        $workScheduleId = null;

        $basePayload = [
            'v' => 1, // Payload version
            'type' => 'attendance_scan', // Default type for attendance
            'location_name' => $relatedLocationName,
            'token' => $uniqueCode, // The unique code itself is part of the payload for easy validation
        ];

        if ($type === 'daily') {
            $validOnDate = Carbon::instance($this->faker->dateTimeBetween('-1 week', '+1 week'))->startOfDay();
            $expiresAt = $validOnDate->copy()->endOfDay();
            $basePayload['date'] = $validOnDate->toDateString();
            if ($this->faker->boolean(50) && WorkSchedule::count() > 0) { // 50% chance to link to a schedule if available
                $workScheduleId = WorkSchedule::inRandomOrder()->first()->id;
                $basePayload['schedule_id'] = $workScheduleId; // Add schedule_id to payload if linked
            }
        } elseif ($type === 'single_use') {
            $expiresAt = Carbon::now()->addHours($this->faker->numberBetween(1, 24));
        }
        // For 'static_location', expires_at might be null or far in the future

        return [
            'unique_code' => $uniqueCode,
            'type' => $type,
            'related_location_name' => $relatedLocationName,
            'work_schedule_id' => $workScheduleId,
            'additional_payload' => $basePayload, // Store the scannable payload here
            'valid_on_date' => $validOnDate,
            'expires_at' => $expiresAt,
            'used_at' => null, // Default to not used
            'used_by_user_id' => null, // Default to not used
            'is_active' => true, // Default to active
        ];
    }

    /**
     * Indicate that the QR code is for daily attendance.
     */
    public function dailyAttendance(): static
    {
        return $this->state(function (array $attributes) {
            $validOnDate = Carbon::today(); // Or faker for variability
            $uniqueCode = $attributes['unique_code'] ?? Str::uuid()->toString();
            $locationName = $attributes['related_location_name'] ?? $this->faker->company . ' Entrance';
            $workScheduleId = $attributes['work_schedule_id'] ?? (WorkSchedule::count() > 0 ? WorkSchedule::inRandomOrder()->first()->id : null);

            $payload = [
                'v' => 1,
                'type' => 'attendance_scan',
                'location_name' => $locationName,
                'date' => $validOnDate->toDateString(),
                'token' => $uniqueCode,
            ];
            if ($workScheduleId) {
                $payload['schedule_id'] = $workScheduleId;
            }

            return [
                'type' => 'daily',
                'unique_code' => $uniqueCode,
                'related_location_name' => $locationName,
                'work_schedule_id' => $workScheduleId,
                'additional_payload' => $payload,
                'valid_on_date' => $validOnDate,
                'expires_at' => $validOnDate->copy()->endOfDay(),
                'is_active' => true,
            ];
        });
    }

    /**
     * Indicate that the QR code is a single-use code.
     */
    public function singleUse(): static
    {
        return $this->state(function (array $attributes) {
            $uniqueCode = $attributes['unique_code'] ?? Str::uuid()->toString();
             $payload = [
                'v' => 1,
                'type' => 'event_check_in', // Example for single use
                'event_id' => $this->faker->numberBetween(100, 200),
                'token' => $uniqueCode,
            ];
            return [
                'type' => 'single_use',
                'unique_code' => $uniqueCode,
                'additional_payload' => $payload,
                'expires_at' => Carbon::now()->addHours($this->faker->numberBetween(1, 8)),
                'is_active' => true,
            ];
        });
    }

    /**
     * Indicate that the QR code has been used.
     */
    public function used(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'used_at' => Carbon::now()->subMinutes($this->faker->numberBetween(5, 60)),
                'used_by_user_id' => User::factory(), // Or an existing user
                'is_active' => false, // Typically, a used single-use QR becomes inactive
            ];
        });
    }

    /**
     * Indicate that the QR code is expired.
     */
    public function expired(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'expires_at' => Carbon::now()->subDay(),
                'is_active' => $this->faker->boolean(20), // Most expired QR should be inactive
            ];
        });
    }

    /**
     * Indicate that the QR code is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }

    /**
     * Configure the factory for a specific work schedule.
     */
    public function forWorkSchedule(WorkSchedule $workSchedule): static
    {
        return $this->state(fn (array $attributes) => ['work_schedule_id' => $workSchedule->id]);
    }
}
