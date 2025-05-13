<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Models\UserDevice;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthServiceTest extends TestCase
{
    use RefreshDatabase;

    protected AuthService $authService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->authService = $this->app->make(AuthService::class);
    }

    public function test_login_user_successfully_with_approved_device(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password123')]);
        $adminApprover = User::factory()->admin()->create();
        UserDevice::factory()->for($user)->approved($adminApprover)->create(['device_identifier' => 'approved_device_id']);

        $credentials = ['email' => $user->email, 'password' => 'password123'];
        $result = $this->authService->loginUser($credentials, 'approved_device_id', 'Test Device', '127.0.0.1');

        $this->assertArrayHasKey('access_token', $result);
        $this->assertEquals($user->id, $result['user']['id']);
        $this->assertEquals('approved_device_id', $result['device']['device_identifier']);
        $this->assertDatabaseHas('user_devices', [
            'device_identifier' => 'approved_device_id',
            'last_login_ip' => '127.0.0.1',
        ]);
    }

    public function test_login_user_fails_with_wrong_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password123')]);
        $this->expectException(ValidationException::class);

        $credentials = ['email' => $user->email, 'password' => 'wrongpassword'];
        $this->authService->loginUser($credentials, 'any_device_id', 'Test Device', '127.0.0.1');
    }

    public function test_login_user_creates_pending_device_if_new_and_throws_validation_exception(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password123')]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Device registration request received. Please wait for admin approval.');

        try {
            $credentials = ['email' => $user->email, 'password' => 'password123'];
            $this->authService->loginUser($credentials, 'new_device_id', 'New Test Device', '127.0.0.1');
        } catch (ValidationException $e) {
            $this->assertDatabaseHas('user_devices', [
                'user_id' => $user->id,
                'device_identifier' => 'new_device_id',
                'name' => 'New Test Device',
                'status' => UserDevice::STATUS_PENDING,
            ]);
            $this->assertEquals(403, $e->status);
            throw $e;
        }
    }

    public function test_login_user_fails_if_device_is_pending(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password123')]);
        UserDevice::factory()->for($user)->pending()->create(['device_identifier' => 'pending_device_id']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Your device is still pending admin approval.');

        try {
            $credentials = ['email' => $user->email, 'password' => 'password123'];
            $this->authService->loginUser($credentials, 'pending_device_id', 'Test Device', '127.0.0.1');
        } catch (ValidationException $e) {
            $this->assertEquals(403, $e->status);
            throw $e;
        }
    }

    public function test_login_user_fails_if_device_is_rejected(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password123')]);
        UserDevice::factory()->for($user)->rejected()->create(['device_identifier' => 'rejected_device_id']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/Your device registration has been rejected./');


        try {
            $credentials = ['email' => $user->email, 'password' => 'password123'];
            $this->authService->loginUser($credentials, 'rejected_device_id', 'Test Device', '127.0.0.1');
        } catch (ValidationException $e) {
            $this->assertEquals(403, $e->status);
            throw $e;
        }
    }

    // logoutUser lebih baik diuji dalam feature test karena melibatkan currentAccessToken()
}