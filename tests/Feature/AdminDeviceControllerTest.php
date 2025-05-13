<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminDeviceControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected UserDevice $adminDevice; // Perangkat yang digunakan admin untuk mengakses API

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        // Admin juga memerlukan perangkat yang disetujui untuk mengakses endpoint admin
        $this->adminDevice = UserDevice::factory()->for($this->admin)->approved($this->admin)->create(); 
        Sanctum::actingAs($this->admin, ['*'], 'web'); // Atau buat token
    }

    private function adminHeaders(): array
    {
        // Jika tidak menggunakan Sanctum::actingAs()
        // $token = $this->admin->createToken('admin_token_for_device_'.$this->adminDevice->id)->plainTextToken;
        return [
            'X-Device-ID' => $this->adminDevice->device_identifier,
            // 'Authorization' => 'Bearer ' . $token,
        ];
    }

    public function test_admin_can_list_all_devices(): void
    {
        $user1 = User::factory()->create();
        UserDevice::factory()->for($user1)->approved($this->admin)->create();
        UserDevice::factory()->for(User::factory()->create())->pending()->create();

        $response = $this->withHeaders($this->adminHeaders())->getJson('/api/admin/devices');

        $response->assertStatus(200)
                 ->assertJsonStructure(['data' => [['id', 'user_id', 'device_identifier', 'status']]])
                 // Termasuk perangkat admin sendiri + 2 perangkat lain
                 ->assertJsonCount(3, 'data');
    }

    public function test_admin_can_approve_a_pending_device(): void
    {
        $user = User::factory()->create();
        $pendingDevice = UserDevice::factory()->for($user)->pending()->create();

        $response = $this->withHeaders($this->adminHeaders())->postJson("/api/admin/devices/{$pendingDevice->id}/approve", [
            'notes' => 'Approved by admin test'
        ]);

        $response->assertStatus(200)
                 ->assertJsonPath('device.status', UserDevice::STATUS_APPROVED)
                 ->assertJsonPath('device.id', $pendingDevice->id);
        $this->assertDatabaseHas('user_devices', [
            'id' => $pendingDevice->id,
            'status' => UserDevice::STATUS_APPROVED,
            'approved_by' => $this->admin->id,
        ]);
    }

    public function test_admin_approving_device_revokes_users_other_approved_device(): void
    {
        $user = User::factory()->create();
        $oldApprovedDevice = UserDevice::factory()->for($user)->approved($this->admin)->create();
        $newDeviceToApprove = UserDevice::factory()->for($user)->pending()->create();
         // Buat token untuk oldApprovedDevice agar bisa diuji pencabutannya
        $oldTokenName = 'auth_token_user_' . $user->id . '_device_' . $oldApprovedDevice->id;
        $user->createToken($oldTokenName);


        $response = $this->withHeaders($this->adminHeaders())->postJson("/api/admin/devices/{$newDeviceToApprove->id}/approve");

        $response->assertStatus(200)
                 ->assertJsonPath('device.status', UserDevice::STATUS_APPROVED);

        $this->assertDatabaseHas('user_devices', ['id' => $newDeviceToApprove->id, 'status' => UserDevice::STATUS_APPROVED]);
        $this->assertDatabaseHas('user_devices', ['id' => $oldApprovedDevice->id, 'status' => UserDevice::STATUS_REVOKED]);
        $this->assertEquals(0, $user->tokens()->where('name', $oldTokenName)->count());
    }

    public function test_admin_can_reject_a_pending_device(): void
    {
        $user = User::factory()->create();
        $pendingDevice = UserDevice::factory()->for($user)->pending()->create();

        $response = $this->withHeaders($this->adminHeaders())->postJson("/api/admin/devices/{$pendingDevice->id}/reject", [
            'notes' => 'Rejected: Test reason'
        ]);

        $response->assertStatus(200)
                 ->assertJsonPath('device.status', UserDevice::STATUS_REJECTED)
                 ->assertJsonPath('device.admin_notes', 'Rejected: Test reason');
    }

    public function test_admin_can_revoke_an_approved_device(): void
    {
        $user = User::factory()->create();
        $approvedDevice = UserDevice::factory()->for($user)->approved($this->admin)->create();
        $tokenName = 'auth_token_user_' . $user->id . '_device_' . $approvedDevice->id;
        $user->createToken($tokenName); // Buat token agar bisa diuji pencabutannya

        $response = $this->withHeaders($this->adminHeaders())->postJson("/api/admin/devices/{$approvedDevice->id}/revoke", [
            'notes' => 'Revoked by admin test'
        ]);

        $response->assertStatus(200)
                 ->assertJsonPath('device.status', UserDevice::STATUS_REVOKED);
        $this->assertEquals(0, $user->tokens()->where('name', $tokenName)->count());
    }

    public function test_admin_can_register_and_approve_device_for_user_and_revokes_old(): void
    {
        $user = User::factory()->create();
        $oldDevice = UserDevice::factory()->for($user)->approved($this->admin)->create(['device_identifier' => 'old_id_for_reg']);
        $oldTokenName = 'auth_token_user_' . $user->id . '_device_' . $oldDevice->id;
        $user->createToken($oldTokenName);

        $payload = [
            'user_id' => $user->id,
            'device_identifier' => 'newly_registered_by_admin',
            'device_name' => 'Admin Registered Laptop',
            'notes' => 'Manual registration by admin'
        ];

        $response = $this->withHeaders($this->adminHeaders())->postJson("/api/admin/devices/register-for-user", $payload);

        $response->assertStatus(201)
                 ->assertJsonPath('device.device_identifier', 'newly_registered_by_admin')
                 ->assertJsonPath('device.status', UserDevice::STATUS_APPROVED);

        $this->assertDatabaseHas('user_devices', ['id' => $oldDevice->id, 'status' => UserDevice::STATUS_REVOKED]);
        $this->assertEquals(0, $user->tokens()->where('name', $oldTokenName)->count());
    }
}