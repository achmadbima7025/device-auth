<?php

namespace Database\Seeders;

use App\Models\WorkSchedule;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class WorkScheduleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        WorkSchedule::factory()->defaultSchedule()->createQuietly(); // Gunakan quietly jika ada unique constraint pada nama dan factory bisa menghasilkan nama yang sama
        WorkSchedule::factory()->nightShift()->createQuietly();
        WorkSchedule::factory()->count(3)->createQuietly();

        $this->command->info('WorkScheduleSeeder executed successfully!');
    }
}
