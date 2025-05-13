<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::updateOrCreate(
            [
                // Kriteria untuk mencari pengguna yang sudah ada (misalnya email)
                'email' => 'admin@admin.com',
            ],
            [
                // Data untuk dibuat atau diupdate
                'name' => 'Administrator',
                'password' => Hash::make('admin'), // Ganti dengan password yang kuat!
                'role' => 'admin', // Tetapkan peran sebagai admin
                'email_verified_at' => now(), // Opsional: langsung verifikasi email
            ]
        );

        // Anda bisa menambahkan lebih banyak admin jika diperlukan
        // User::updateOrCreate(
        //     ['email' => 'superadmin@example.com'],
        //     [
        //         'name' => 'Super Admin',
        //         'password' => Hash::make('supersecret'),
        //         'role' => 'admin',
        //         'email_verified_at' => now(),
        //     ]
        // );

        $this->command->info('Admin user(s) seeded successfully!');
    }
}
