<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\UserWorkScheduleAssignment;
use App\Models\WorkSchedule;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class UserWorkScheduleAssignmentSeeder extends Seeder
{
    public function run(): void
    {
        $users = User::all();
        if ($users->isEmpty()) {
            $users = User::factory(10)->create(); // Buat 10 user jika belum ada
        }

        $schedules = WorkSchedule::where('is_active', true)->get();
        if ($schedules->isEmpty()) {
            // Pastikan minimal ada 1 jadwal (default) jika tidak ada jadwal aktif
            $schedules->push(WorkSchedule::factory()->defaultSchedule()->create());
        }

        $admin = User::where('role', 'admin')->first() ?? User::factory()->admin()->create();

        foreach ($users as $user) {
            // Setiap user mendapat 1-2 assignment
            for ($i = 0; $i < $this->faker->numberBetween(1, 2); $i++) {
                $schedule = $schedules->random();
                $isCurrent = $this->faker->boolean(70); // 70% assignment aktif saat ini

                if ($isCurrent) {
                    UserWorkScheduleAssignment::factory()
                        ->forUser($user)
                        ->forWorkSchedule($schedule)
                        ->assignedBy($admin)
                        ->activeNow() // Mencakup hari ini
                        ->create();
                } else {
                    // Bisa jadi assignment lampau atau masa depan
                    if ($this->faker->boolean()) {
                        UserWorkScheduleAssignment::factory()
                            ->forUser($user)
                            ->forWorkSchedule($schedule)
                            ->assignedBy($admin)
                            ->pastAssignment()
                            ->create();
                    } else {
                        UserWorkScheduleAssignment::factory()
                            ->forUser($user)
                            ->forWorkSchedule($schedule)
                            ->assignedBy($admin)
                            ->futureAssignment()
                            ->create();
                    }
                }
            }
        }
        $this->command->info('UserWorkScheduleAssignmentSeeder executed successfully!');
    }

    // Tambahkan faker jika belum ada di class ini
    protected $faker;
    public function __construct()
    {
        $this->faker = \Faker\Factory::create();
    }
}
