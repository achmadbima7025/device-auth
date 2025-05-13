<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuthService;
use App\Services\DeviceManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{

    public function __construct(
        protected AuthService $authService,
        protected DeviceManagementService $deviceService)
    {
        $this->authService = $authService;
        $this->deviceService = $deviceService;
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
            'device_identifier' => 'required|string|max:255',
            'device_name' => 'sometimes|string|max:255',
        ]);

        try {
            $result = $this->authService->loginUser(
                $credentials,
                $request->device_identifier,
                $request->device_name,
                $request->ip()
            );

            return response()->json([
                'message' => 'Login successful',
                'access_token' => $result['access_token'],
                'token_type' => 'Bearer',
                'user' => $result['user'],
                'device' => $result['device'],
            ]);
        } catch (ValidationException $e) {
            // Jika service melempar ValidationException dengan status code kustom
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], $e->status);
        }
    }

    public function user(Request $request): JsonResponse
    {
        $userDetails = $this->authService->getUserDetails($request->user());
        $device = $this->deviceService->getDeviceForUserByIdentifier($request->user(), $request->header('X-Device-ID'));

        return response()->json([
            'user' => $userDetails,
            'current_device_info' => $device?->only(['id', 'device_identifier', 'name', 'status']),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authService->logoutUser($request->user());
        return response()->json(['message' => 'Logged out successfully']);
    }

    public function listMyDevices(Request $request): JsonResponse
    {
        $devices = $this->deviceService->listUserDevices($request->user());
        return response()->json($devices);
    }
}
