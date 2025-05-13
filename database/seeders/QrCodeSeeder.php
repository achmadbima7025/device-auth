<?php

namespace Database\Seeders;

use App\Models\QrCode;
use App\Models\WorkSchedule;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class QrCodeSeeder extends Seeder
{
    public function run(): void
    {
        // Hapus QR code lama jika perlu (hati-hati)
        // QrCode::truncate();

        // Buat QR code harian untuk hari ini (default location & schedule)
        QrCode::factory()->dailyAttendance()->state([
            'valid_on_date' => Carbon::today(),
            'related_location_name' => 'Main Office - Today',
        ])->createQuietly();

        // Buat QR code harian untuk besok
        QrCode::factory()->dailyAttendance()->state([
            'valid_on_date' => Carbon::tomorrow(),
            'related_location_name' => 'Main Office - Tomorrow',
        ])->createQuietly();


        // Buat QR code harian untuk jadwal tertentu jika ada
        $schedules = WorkSchedule::where('is_active', true)->get();
        if ($schedules->isNotEmpty()) {
            foreach($schedules->take(2) as $schedule) { // Ambil 2 jadwal pertama
                QrCode::factory()->dailyAttendance()
                    ->forWorkSchedule($schedule)
                    ->state([
                        'valid_on_date' => Carbon::today(),
                        'related_location_name' => 'Entrance - ' . $schedule->name,
                    ])
                    ->createQuietly();
            }
        } else {
            $this->command->warn('No active work schedules found to create specific QR codes.');
        }

        // Buat beberapa QR code sekali pakai
        QrCode::factory()->singleUse()->count(5)->createQuietly();

        // Buat QR code yang sudah digunakan
        QrCode::factory()->singleUse()->used()->count(2)->createQuietly();

        // Buat QR code yang sudah kadaluarsa
        QrCode::factory()->dailyAttendance()->expired()->createQuietly();

        $this->command->info('QrCodeSeeder executed successfully!');
    }
}
