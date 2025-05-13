<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_with_valid_credentials_and_approved_device(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password123')]);
        $admin = User::factory()->admin()->create();
        UserDevice::factory()->for($user)->approved($admin)->create(['device_identifier' => 'dev_approved']);
        
        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password123',
            'device_identifier' => 'dev_approved',
            'device_name' => 'Test Device'
        ]);
        $response->assertStatus(200)
                 ->assertJsonStructure(['access_token', 'user', 'device'])
                 ->assertJsonPath('user.email', $user->email)
                 ->assertJsonPath('device.status', UserDevice::STATUS_APPROVED);
    }

    public function test_user_login_fails_with_invalid_credentials(): void
    {
        $user = User::factory()->create();
        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'wrongpassword',
            'device_identifier' => 'any_id',
        ]);
        $response->assertStatus(422); // ValidationException dari service akan menjadi 422
    }

    public function test_user_login_registers_new_device_as_pending_and_returns_403(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password123')]);
        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password123',
            'device_identifier' => 'new_device_pending',
            'device_name' => 'New Device'
        ]);

        $response->assertStatus(403) // ValidationException (device pending) dari service
                 ->assertJsonFragment(['message' => 'Device registration request received. Please wait for admin approval.']);
        $this->assertDatabaseHas('user_devices', [
            'user_id' => $user->id,
            'device_identifier' => 'new_device_pending',
            'status' => UserDevice::STATUS_PENDING
        ]);
    }

    public function test_user_login_fails_if_device_is_pending(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password123')]);
        UserDevice::factory()->for($user)->pending()->create(['device_identifier' => 'existing_pending_device']);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password123',
            'device_identifier' => 'existing_pending_device',
        ]);
        $response->assertStatus(403)
                 ->assertJsonFragment(['message' => 'Your device is still pending admin approval.']);
    }

    public function test_authenticated_user_can_get_their_details(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $device = UserDevice::factory()->for($user)->approved($admin)->create();

        Sanctum::actingAs($user, ['*'], 'web'); // Atau buat token dan kirim via header

        $response = $this->withHeaders([
            'X-Device-ID' => $device->device_identifier,
            // Jika tidak menggunakan Sanctum::actingAs, Anda perlu token:
            // 'Authorization' => 'Bearer ' . $user->createToken('test_token_name_user_'.$user->id.'_device_'.$device->id)->plainTextToken,
        ])->getJson('/api/user');


        $response->assertStatus(200)
                 ->assertJsonPath('user.email', $user->email)
                 ->assertJsonPath('current_device_info.id', $device->id);
    }

    public function test_authenticated_user_can_logout(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $device = UserDevice::factory()->for($user)->approved($admin)->create();
        $token = $user->createToken('auth_token_user_' . $user->id . '_device_' . $device->id)->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'X-Device-ID' => $device->device_identifier,
        ])->postJson('/api/logout');

        $response->assertStatus(200)
                 ->assertJson(['message' => 'Logged out successfully']);
        $this->assertDatabaseMissing('personal_access_tokens', ['token' => hash('sha256', explode('|', $token, 2)[1])]);
    }

    public function test_authenticated_user_can_list_their_devices(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $device1 = UserDevice::factory()->for($user)->approved($admin)->create();
        $device2 = UserDevice::factory()->for($user)->pending()->create();

        Sanctum::actingAs($user);

        $response = $this->withHeaders([
            'X-Device-ID' => $device1->device_identifier, // Perangkat yang digunakan untuk request
        ])->getJson('/api/my-devices');

        $response->assertStatus(200)
            ->assertJsonCount(2)
            ->assertJsonFragment(['device_identifier' => $device1->device_identifier])
            ->assertJsonFragment(['device_identifier' => $device2->device_identifier]);
    }
}