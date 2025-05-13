<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            AdminUserSeeder::class, // Asumsi sudah ada untuk user admin
            WorkScheduleSeeder::class,
            UserWorkScheduleAssignmentSeeder::class, // Jalankan setelah UserSeeder dan WorkScheduleSeeder
            AttendanceSettingSeeder::class,
            QrCodeSeeder::class, // Jalankan setelah WorkScheduleSeeder jika ada ketergantungan
            AttendanceDummySeeder::class, // Jalankan setelah UserSeeder, WorkScheduleSeeder, QrCodeSeeder
            AttendanceCorrectionLogSeeder::class, // Jalankan setelah AttendanceDummySeeder
        ]);
    }
}
