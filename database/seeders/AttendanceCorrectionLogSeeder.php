<?php

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\AttendanceCorrectionLog;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class AttendanceCorrectionLogSeeder extends Seeder
{
    public function run(): void
    {
        $attendancesToCorrect = Attendance::where('is_manually_corrected', false) // Ambil yang belum pernah dikoreksi
        ->inRandomOrder()
            ->take(5) // Koreksi 5 record absensi secara acak
            ->get();

        $admin = User::where('role', 'admin')->first();
        if (!$admin) {
            $this->command->warn('No admin user found to perform corrections. Skipping AttendanceCorrectionLogSeeder.');
            return;
        }

        if ($attendancesToCorrect->isEmpty()) {
            $this->command->info('No attendance records found to correct for dummy logs.');
            return;
        }

        foreach ($attendancesToCorrect as $attendance) {
            AttendanceCorrectionLog::factory()->create([
                'attendance_id' => $attendance->id,
                'admin_user_id' => $admin->id,
            ]);
            // Tandai absensi sebagai sudah dikoreksi
            $attendance->update([
                'is_manually_corrected' => true,
                'last_corrected_by' => $admin->id,
                'last_correction_at' => now(),
                'correction_summary_notes' => ($attendance->correction_summary_notes ?? '') . "\n[Dummy Correction] Logged by seeder."
            ]);
        }
        $this->command->info('AttendanceCorrectionLogSeeder executed successfully!');
    }
}
