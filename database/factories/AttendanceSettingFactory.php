<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AttendanceSetting>
 */
class AttendanceSettingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Define an array of possible setting structures
        $possibleSettings = [
            [
                'key' => 'standard_clock_in_time',
                'value' => '08:00:00',
                'data_type' => 'time',
                'description' => 'Standard employee clock-in time (HH:MM:SS format).',
                'group' => 'schedule',
            ],
            [
                'key' => 'standard_clock_out_time',
                'value' => '17:00:00',
                'data_type' => 'time',
                'description' => 'Standard employee clock-out time (HH:MM:SS format).',
                'group' => 'schedule',
            ],
            [
                'key' => 'late_tolerance_minutes',
                'value' => (string) $this->faker->randomElement([0, 5, 10, 15, 30]),
                'data_type' => 'integer',
                'description' => 'Late tolerance in minutes before being considered late.',
                'group' => 'schedule',
            ],
            [
                'key' => 'early_leave_tolerance_minutes',
                'value' => (string) $this->faker->randomElement([0, 5, 10, 15]),
                'data_type' => 'integer',
                'description' => 'Early leave tolerance in minutes before end_time without being flagged as early_leave.',
                'group' => 'schedule',
            ],
            [
                'key' => 'min_duration_before_clock_out_minutes',
                'value' => (string) $this->faker->randomElement([30, 60, 120, 240]), // 0.5h, 1h, 2h, 4h
                'data_type' => 'integer',
                'description' => 'Minimum work duration (minutes) after clock-in before clock-out is allowed.',
                'group' => 'schedule',
            ],
            [
                'key' => 'min_overtime_threshold_minutes',
                'value' => (string) $this->faker->randomElement([0, 15, 30, 60]),
                'data_type' => 'integer',
                'description' => 'Minimum overtime in minutes to be considered as overtime.',
                'group' => 'overtime_rules',
            ],
            [
                'key' => 'enable_gps_validation',
                'value' => $this->faker->randomElement(['true', 'false']),
                'data_type' => 'boolean',
                'description' => 'Enable or disable GPS location validation during attendance.',
                'group' => 'gps',
            ],
            [
                'key' => 'office_latitude',
                'value' => (string) $this->faker->latitude(-6.1, -6.3), // Example for Jakarta area
                'data_type' => 'string', // Stored as string, cast to float in service
                'description' => 'Main office Latitude coordinate.',
                'group' => 'gps',
            ],
            [
                'key' => 'office_longitude',
                'value' => (string) $this->faker->longitude(106.7, 106.9), // Example for Jakarta area
                'data_type' => 'string', // Stored as string, cast to float in service
                'description' => 'Main office Longitude coordinate.',
                'group' => 'gps',
            ],
            [
                'key' => 'gps_radius_meters',
                'value' => (string) $this->faker->randomElement([50, 100, 150, 200]),
                'data_type' => 'integer',
                'description' => 'Tolerance radius (in meters) from office location for GPS attendance.',
                'group' => 'gps',
            ],
            [
                'key' => 'enforce_approved_device_for_attendance',
                'value' => $this->faker->randomElement(['true', 'false']),
                'data_type' => 'boolean',
                'description' => 'If true, attendance scan is only allowed from approved devices.',
                'group' => 'device',
            ],
            [
                'key' => 'default_work_schedule_id',
                'value' => null, // Or link to a default WorkSchedule if one is created by default
                'data_type' => 'integer', // Assuming it stores the ID
                'description' => 'ID of the default work schedule to use if no specific assignment is found for a user.',
                'group' => 'schedule',
            ],
             [
                'key' => 'allow_manual_correction_reason',
                'value' => 'true',
                'data_type' => 'boolean',
                'description' => 'Require admin to provide a reason when manually correcting attendance.',
                'group' => 'admin_audit',
            ],
        ];

        // Pick one setting structure randomly
        $settingTemplate = $this->faker->randomElement($possibleSettings);

        // To ensure unique keys if generating many random settings (not typical for this table)
        // For a seeder, you'd typically define specific keys.
        // This factory is more for generating *a* setting, rather than *all* settings.
        // If you want to ensure unique keys for random generation in tests:
        // 'key' => Str::slug($this->faker->unique()->words(3, true), '_'),

        return [
            'key'         => $settingTemplate['key'], // For specific keys, override this when using the factory
            'value'       => $settingTemplate['value'],
            'data_type'   => $settingTemplate['data_type'],
            'description' => $settingTemplate['description'],
            'group'       => $settingTemplate['group'],
        ];
    }

    /**
     * Configure the factory for a specific setting key and value.
     * This is more useful for testing or seeding specific settings.
     *
     * @param string $key
     * @param mixed $value
     * @param string $dataType
     * @param string|null $description
     * @param string|null $group
     * @return static
     */
    public function specificSetting(string $key, $value, string $dataType, ?string $description = null, ?string $group = null): static
    {
        return $this->state(fn (array $attributes) => [
            'key'         => $key,
            'value'       => (string) $value, // Ensure value is string for DB, model accessor will cast
            'data_type'   => $dataType,
            'description' => $description ?? $this->faker->sentence,
            'group'       => $group ?? $this->faker->randomElement(['general', 'schedule', 'gps', 'notifications']),
        ]);
    }

    /**
     * State for a boolean type setting.
     */
    public function booleanType(bool $value = true): static
    {
        return $this->state(fn (array $attributes) => [
            'value'     => $value ? 'true' : 'false',
            'data_type' => 'boolean',
        ]);
    }

    /**
     * State for an integer type setting.
     */
    public function integerType(int $value = 0): static
    {
        return $this->state(fn (array $attributes) => [
            'value'     => (string) $value,
            'data_type' => 'integer',
        ]);
    }

    /**
     * State for a time type setting.
     */
    public function timeType(string $value = '00:00:00'): static // HH:MM:SS
    {
        return $this->state(fn (array $attributes) => [
            'value'     => $value,
            'data_type' => 'time',
        ]);
    }

    /**
     * State for a JSON type setting.
     */
    public function jsonType(array $value = []): static
    {
        return $this->state(fn (array $attributes) => [
            'value'     => json_encode($value),
            'data_type' => 'json',
        ]);
    }
}
