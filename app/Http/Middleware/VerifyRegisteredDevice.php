<?php

namespace App\Http\Middleware;

use App\Models\UserDevice;
use App\Services\DeviceManagementService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class VerifyRegisteredDevice
{
    protected DeviceManagementService $deviceService;

    // Inject service
    public function __construct(DeviceManagementService $deviceService)
    {
        $this->deviceService = $deviceService;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::guard('sanctum')->check()) {
            $user = Auth::guard('sanctum')->user();
            $deviceIdentifier = $request->header('X-Device-ID');

            if (!$deviceIdentifier) {
                return response()->json(['message' => 'Device ID header (X-Device-ID) is missing.'], 400);
            }

            $device = $this->deviceService->getDeviceForUserByIdentifier($user, $deviceIdentifier);

            if (!$device) {
                return response()->json(['message' => 'This device is not recognized for your account.'], 403);
            }

            if (!$device->isApproved()) {
                $message = 'Access from this device is not approved.';
                if ($device->status === UserDevice::STATUS_PENDING) {
                    $message = 'This device is still pending admin approval.';
                } elseif ($device->status === UserDevice::STATUS_REJECTED) {
                    $message = 'Access from this device has been rejected.';
                } elseif ($device->status === UserDevice::STATUS_REVOKED) {
                    $message = 'Access for this device has been revoked.';
                }
                return response()->json(['message' => $message, 'device_status' => $device->status], 403);
            }

            // Update last_used_at untuk perangkat ini melalui service
            $this->deviceService->updateDeviceLastUsed($user, $deviceIdentifier);
        }
        return $next($request);
    }
}
