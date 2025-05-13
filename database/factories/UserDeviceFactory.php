<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Database\Eloquent\Factories\Factory;
use Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\UserDevice>
 */
class UserDeviceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(), // Akan membuat user baru jika tidak di-override
            'device_identifier' => Str::random(32) . '_fingerprint_' . Str::uuid(),
            'name' => fake()->word . ' ' . fake()->randomElement(['Device', 'Phone', 'Laptop']),
            'status' => UserDevice::STATUS_PENDING,
            'last_login_ip' => fake()->ipv4(),
            'last_used_at' => null,
            'approved_by' => null,
            'approved_at' => null,
            'admin_notes' => null,
        ];
    }

    public function approved(User $admin = null): static
    {
        $admin ??= User::factory()->admin()->create();
        return $this->state(fn (array $attributes) => [
            'status' => UserDevice::STATUS_APPROVED,
            'approved_by' => $admin->id,
            'approved_at' => now(),
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => UserDevice::STATUS_PENDING,
            'approved_by' => null,
            'approved_at' => null,
        ]);
    }

    public function rejected(User $admin = null): static
    {
        $admin ??= User::factory()->admin()->create();
        return $this->state(fn (array $attributes) => [
            'status' => UserDevice::STATUS_REJECTED,
            'approved_by' => $admin->id, // Admin yang menolak
            'approved_at' => null, // Tidak ada tanggal persetujuan
            'admin_notes' => 'Device rejected during testing.',
        ]);
    }

    public function revoked(User $admin = null): static
    {
        $admin ??= User::factory()->admin()->create();
        return $this->state(fn (array $attributes) => [
            'status' => UserDevice::STATUS_REVOKED,
            'approved_by' => $admin->id, // Admin yang mencabut, bisa juga berbeda
            'admin_notes' => 'Device revoked during testing.',
        ]);
    }
}
