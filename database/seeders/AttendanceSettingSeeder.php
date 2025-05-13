<?php

namespace Database\Seeders;

use App\Models\AttendanceSetting;
use App\Models\WorkSchedule;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class AttendanceSettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $settings = [
            ['standard_clock_in_time', '08:00:00', 'time', 'Standard employee clock-in time (HH:MM:SS format).', 'schedule'],
            ['standard_clock_out_time', '17:00:00', 'time', 'Standard employee clock-out time (HH:MM:SS format).', 'schedule'],
            ['late_tolerance_minutes', '15', 'integer', 'Late tolerance in minutes before being considered late.', 'schedule'],
            ['early_leave_tolerance_minutes', '10', 'integer', 'Early leave tolerance in minutes.', 'schedule'],
            ['min_duration_before_clock_out_minutes', '60', 'integer', 'Minimum work duration (minutes) after clock-in before clock-out is allowed.', 'schedule'],
            ['min_overtime_threshold_minutes', '30', 'integer', 'Minimum overtime in minutes to be considered as overtime.', 'overtime_rules'],
            ['night_shift_clock_out_buffer_hours', '3', 'integer', 'Buffer hours after night shift end time for clock-out to still be considered for previous work_date.', 'schedule'],
            ['enable_gps_validation', 'false', 'boolean', 'Enable or disable GPS location validation during attendance.', 'gps'],
            ['office_latitude', '-6.2087634', 'string', 'Main office Latitude coordinate.', 'gps'],
            ['office_longitude', '106.845599', 'string', 'Main office Longitude coordinate.', 'gps'],
            ['gps_radius_meters', '100', 'integer', 'Tolerance radius (in meters) from office location for GPS attendance.', 'gps'],
            ['enforce_approved_device_for_attendance', 'false', 'boolean', 'If true, attendance scan is only allowed from approved devices.', 'device'],
            ['default_work_schedule_id', WorkSchedule::where('is_default', true)->first()?->id, 'integer', 'ID of the default work schedule if no assignment found.', 'schedule'],
            ['allow_manual_correction_reason', 'true', 'boolean', 'Require admin to provide a reason when manually correcting attendance.', 'admin_audit'],
        ];

        foreach ($settings as [$key, $value, $dataType, $description, $group]) {
            // Pastikan value untuk default_work_schedule_id adalah string atau null
            $actualValue = is_null($value) ? null : (string)$value;
            AttendanceSetting::factory()->specificSetting($key, $actualValue, $dataType, $description, $group)->create();
        }

        $this->command->info('AttendanceSettingSeeder (using factory) executed successfully!');

    }
}
