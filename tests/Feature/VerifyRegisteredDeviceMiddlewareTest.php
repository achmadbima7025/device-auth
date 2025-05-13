<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VerifyRegisteredDeviceMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Definisikan rute sementara untuk menguji middleware
        app('router')->get('/test-middleware-route', function () {
            return response()->json(['message' => 'allowed']);
        })->middleware(['auth:sanctum', 'verified.device']);
    }

    public function test_middleware_allows_request_with_valid_token_and_approved_device_header(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->admin()->create(); // Admin yang menyetujui
        $device = UserDevice::factory()->for($user)->approved($admin)->create();
        Sanctum::actingAs($user);

        $response = $this->withHeaders([
            'X-Device-ID' => $device->device_identifier,
        ])->getJson('/test-middleware-route');

        $response->assertStatus(200)->assertJson(['message' => 'allowed']);
    }

    public function test_middleware_blocks_request_if_x_device_id_header_is_missing(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/test-middleware-route'); // Tanpa X-Device-ID

        $response->assertStatus(400)
                 ->assertJson(['message' => 'Device ID header (X-Device-ID) is missing.']);
    }

    public function test_middleware_blocks_request_if_device_is_not_recognized(): void
    {
        $user = User::factory()->create();
        // Perangkat user lain, atau tidak ada perangkat sama sekali
        Sanctum::actingAs($user);

        $response = $this->withHeaders([
            'X-Device-ID' => 'unrecognized_device_id',
        ])->getJson('/test-middleware-route');

        $response->assertStatus(403)
                 ->assertJson(['message' => 'This device is not recognized for your account.']);
    }

    public function test_middleware_blocks_request_if_device_is_pending(): void
    {
        $user = User::factory()->create();
        $device = UserDevice::factory()->for($user)->pending()->create();
        Sanctum::actingAs($user);

        $response = $this->withHeaders([
            'X-Device-ID' => $device->device_identifier,
        ])->getJson('/test-middleware-route');

        $response->assertStatus(403)
                 ->assertJson(['message' => 'This device is still pending admin approval.']);
    }

    public function test_middleware_blocks_request_if_device_is_rejected(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $device = UserDevice::factory()->for($user)->rejected($admin)->create();
        Sanctum::actingAs($user);

        $response = $this->withHeaders([
            'X-Device-ID' => $device->device_identifier,
        ])->getJson('/test-middleware-route');

        $response->assertStatus(403)
                 ->assertJson(['message' => 'Access from this device has been rejected.']);
    }
}