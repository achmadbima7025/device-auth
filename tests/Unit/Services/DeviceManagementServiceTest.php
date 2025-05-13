<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Models\UserDevice;
use App\Services\DeviceManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Illuminate\Support\Facades\DB; // Untuk memantau transaksi jika perlu (mocking DB::transaction)

class DeviceManagementServiceTest extends TestCase
{
    use RefreshDatabase;

    protected DeviceManagementService $deviceService;
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->deviceService = $this->app->make(DeviceManagementService::class);
        $this->admin = User::factory()->admin()->create(); // Admin untuk aksi
    }

    public function test_approve_device_sets_status_to_approved_and_revokes_other_approved_devices(): void
    {
        $user = User::factory()->create();
        $oldApprovedDevice = UserDevice::factory()->for($user)->approved($this->admin)->create(['device_identifier' => 'old_approved_id']);
        $deviceToApprove = UserDevice::factory()->for($user)->pending()->create(['device_identifier' => 'new_to_approve_id']);

        // Mocking token revocation (karena ini unit test, kita tidak berinteraksi dengan Sanctum secara langsung)
        // Jika kita mock User model:
        // $mockUser = $this->mock(User::class);
        // $mockUser->shouldReceive('tokens->where->delete')->atLeast()->once();
        // $deviceToApprove->user = $mockUser; // Ganti user asli dengan mock
        // $oldApprovedDevice->user = $mockUser;

        $approvedDevice = $this->deviceService->approveDevice($deviceToApprove, $this->admin, 'Test approval');

        $this->assertEquals(UserDevice::STATUS_APPROVED, $approvedDevice->status);
        $this->assertEquals($this->admin->id, $approvedDevice->approved_by);
        $this->assertNotNull($approvedDevice->approved_at);

        $oldApprovedDevice->refresh();
        $this->assertEquals(UserDevice::STATUS_REVOKED, $oldApprovedDevice->status);
        $this->assertStringContainsString('Automatically revoked', $oldApprovedDevice->admin_notes);
    }

    public function test_reject_device_sets_status_to_rejected(): void
    {
        $user = User::factory()->create();
        $deviceToReject = UserDevice::factory()->for($user)->pending()->create();

        $rejectedDevice = $this->deviceService->rejectDevice($deviceToReject, $this->admin, 'Test rejection');

        $this->assertEquals(UserDevice::STATUS_REJECTED, $rejectedDevice->status);
        $this->assertEquals('Test rejection', $rejectedDevice->admin_notes);
        $this->assertEquals($this->admin->id, $rejectedDevice->approved_by); // 'approved_by' juga untuk admin yang reject
    }

    public function test_revoke_device_sets_status_to_revoked(): void
    {
        $user = User::factory()->create();
        $deviceToRevoke = UserDevice::factory()->for($user)->approved($this->admin)->create();

        // Untuk menguji pencabutan token, kita asumsikan ada token yang dibuat dengan nama spesifik
        Sanctum::actingAs($user); // Agar ada currentAccessToken (meski ini lebih ke feature test)
                                  // Sebenarnya, pemanggilan $user->tokens()->where()->delete() tidak memerlukan actingAs
        $tokenName = 'auth_token_user_' . $user->id . '_device_' . $deviceToRevoke->id;
        $user->createToken($tokenName); // Buat token agar ada yang dihapus

        $revokedDevice = $this->deviceService->revokeDevice($deviceToRevoke, $this->admin, 'Test revocation');

        $this->assertEquals(UserDevice::STATUS_REVOKED, $revokedDevice->status);
        $this->assertEquals(0, $user->tokens()->where('name', $tokenName)->count()); // Cek token terhapus
    }

    public function test_register_device_for_user_by_admin_approves_new_and_revokes_old(): void
    {
        $user = User::factory()->create();
        $oldDevice = UserDevice::factory()->for($user)->approved($this->admin)->create(['device_identifier' => 'old_admin_reg_id']);

        // Buat token untuk oldDevice agar bisa diuji pencabutannya
        $oldTokenName = 'auth_token_user_' . $user->id . '_device_' . $oldDevice->id;
        $user->createToken($oldTokenName);


        $newDeviceIdentifier = 'new_admin_reg_id';
        $newDeviceName = 'Admin Registered Device';

        $newlyRegisteredDevice = $this->deviceService->registerDeviceForUserByAdmin(
            $user,
            $newDeviceIdentifier,
            $newDeviceName,
            $this->admin,
            'Admin registration test'
        );

        $this->assertEquals(UserDevice::STATUS_APPROVED, $newlyRegisteredDevice->status);
        $this->assertEquals($newDeviceIdentifier, $newlyRegisteredDevice->device_identifier);
        $this->assertEquals($this->admin->id, $newlyRegisteredDevice->approved_by);

        $oldDevice->refresh();
        $this->assertEquals(UserDevice::STATUS_REVOKED, $oldDevice->status);
        $this->assertStringContainsString('Automatically revoked', $oldDevice->admin_notes);
        $this->assertEquals(0, $user->tokens()->where('name', $oldTokenName)->count());
    }

    public function test_update_device_last_used_updates_timestamp_for_approved_device(): void
    {
        $user = User::factory()->create();
        $device = UserDevice::factory()->for($user)->approved($this->admin)->create(['last_used_at' => now()->subDay()]);

        $this->deviceService->updateDeviceLastUsed($user, $device->device_identifier);
        $device->refresh();

        $this->assertTrue($device->last_used_at->isToday()); // Cek apakah sudah diupdate
    }

    public function test_update_device_last_used_does_not_update_for_pending_device(): void
    {
        $user = User::factory()->create();
        $originalTimestamp = now()->subDay();
        $device = UserDevice::factory()->for($user)->pending()->create(['last_used_at' => $originalTimestamp]);

        $this->deviceService->updateDeviceLastUsed($user, $device->device_identifier);
        $device->refresh();

        // Pastikan timestamp tidak berubah
        $this->assertEquals($originalTimestamp->timestamp, $device->last_used_at->timestamp);
    }
}