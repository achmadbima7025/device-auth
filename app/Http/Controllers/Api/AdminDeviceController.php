<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Services\DeviceManagementService;

class AdminDeviceController extends Controller
{
    protected DeviceManagementService $deviceService;

    public function __construct(DeviceManagementService $deviceService)
    {
        $this->deviceService = $deviceService;
        // Terapkan middleware admin di sini atau di rute
        // $this->middleware('is.admin'); // Contoh
    }

    public function listAllDevices(Request $request): JsonResponse
    {
        $devices = $this->deviceService->listAllDevicesFiltered($request->query('status'));
        return response()->json($devices);
    }

    public function showDevice(UserDevice $device): JsonResponse // Route model binding
    {
        $detailedDevice = $this->deviceService->getDeviceDetails($device);
        return response()->json($detailedDevice);
    }

    public function approveDevice(Request $request, UserDevice $userDevice): JsonResponse
    {
        $admin = $request->user(); // Mendapatkan admin yang sedang login
        $userDevice->load('user');

        if (!$userDevice->user) {
            // Log error atau lempar exception, karena ini tidak seharusnya terjadi
            Log::error("User not found for UserDevice ID: {$userDevice->id} in AdminDeviceController@approveDevice");
            return response()->json(['message' => 'Internal server error: Associated user not found for the device.'], 500);
        }

        $approvedDevice = $this->deviceService->approveDevice($userDevice, $admin, $request->input('notes'));
        return response()->json(['message' => 'Device approved successfully.', 'device' => $approvedDevice]);
    }

    public function rejectDevice(Request $request, UserDevice $userDevice): JsonResponse
    {
        $request->validate(['notes' => 'required|string|max:500']);
        $admin = $request->user();
        $userDevice->load('user');

        $rejectedDevice = $this->deviceService->rejectDevice($userDevice, $admin, $request->notes);
        return response()->json(['message' => 'Device rejected successfully.', 'device' => $rejectedDevice]);
    }

    public function revokeDevice(Request $request, UserDevice $userDevice): JsonResponse
    {
        $admin = $request->user();
        $userDevice->load('user');
        $revokedDevice = $this->deviceService->revokeDevice($userDevice, $admin, $request->input('notes'));
        return response()->json(['message' => 'Device access revoked successfully.', 'device' => $revokedDevice]);
    }

    public function registerDeviceForUser(Request $request): JsonResponse
    {
        $validatedData = $request->validate([
            'user_id' => 'required|exists:users,id',
            'device_identifier' => 'required|string|max:255',
            'device_name' => 'required|string|max:255',
            'notes' => 'sometimes|string|max:500',
        ]);

        $admin = $request->user();
        $targetUser = User::findOrFail($validatedData['user_id']);

        $device = $this->deviceService->registerDeviceForUserByAdmin(
            $targetUser,
            $validatedData['device_identifier'],
            $validatedData['device_name'],
            $admin,
            $validatedData['notes'] ?? null
        );
        return response()->json(['message' => 'Device registered and approved for user.', 'device' => $device], 201);
    }
}
