<?php

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\User;
use App\Models\WorkSchedule;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class AttendanceDummySeeder extends Seeder
{
    public function run(): void
    {
        $users = User::all();
        if ($users->isEmpty()) {
            $this->command->warn('No users found. Creating 5 dummy users for attendance seeding.');
            $users = User::factory(5)->create();
        }

        // Pastikan ada minimal satu jadwal kerja (default)
        if (WorkSchedule::count() == 0) {
            WorkSchedule::factory()->defaultSchedule()->create();
            $this->command->info('Default work schedule created.');
        }


        foreach ($users as $user) {
            // Buat 10-20 record absensi acak untuk setiap user dalam 2 bulan terakhir
            Attendance::factory()->count($this->faker->numberBetween(10,20))->forUser($user)->create();

            // Buat beberapa absensi yang belum clock-out untuk hari ini atau kemarin
            if ($this->faker->boolean(50)) {
                $workDateForNotClockedOut = $this->faker->boolean(70) ? today() : today()->subDay();
                Attendance::factory()
                    ->forUser($user)
                    ->onWorkDate($workDateForNotClockedOut->toDateString())
                    ->notClockedOut()
                    ->create();
            }
        }

        $this->command->info('AttendanceDummySeeder executed successfully!');
    }

    // Tambahkan faker jika belum ada di class ini
    protected $faker;
    public function __construct()
    {
        $this->faker = \Faker\Factory::create();
    }
}
