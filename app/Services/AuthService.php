<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function loginUser(array $credentials, string $deviceIdentifier, ?string $deviceName, string $ipAddress): array
    {
        $user = User::where('email', $credentials['email'])->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        // Cek atau buat entri perangkat
        $device = $user->devices()->firstOrNew(
            ['device_identifier' => $deviceIdentifier],
            [
                'name' => $deviceName ?: 'Unknown Device (' . now()->toDateTimeString() . ')',
                'status' => UserDevice::STATUS_PENDING, // Default ke pending jika baru
                'last_login_ip' => $ipAddress, // Diisi saat ini
            ]
        );

        if (!$device->exists) {
            $device->save();
            // Bisa trigger event untuk notifikasi admin
            // event(new NewDevicePendingApproval($device));
            throw ValidationException::withMessages([
                'device_status' => 'Device registration request received. Please wait for admin approval.',
            ])->status(403); // Menggunakan status 403 untuk ini
        }

        if (!$device->isApproved()) {
            $message = $this->getDeviceStatusMessage($device);
            throw ValidationException::withMessages([
                'device_status' => $message,
            ])->status(403);
        }

        $device->last_login_ip = $ipAddress;
        $device->last_used_at = now();
        $device->save();

        // Hapus token LAMA dengan nama yang sama jika ada (opsional, tapi baik untuk kebersihan)
        $tokenName = "auth_token_user_{$user->id}_device_{$device->id}";
        $user->tokens()->where('name', $tokenName)->delete();

        $token = $user->createToken($tokenName)->plainTextToken;

        return [
            'access_token' => $token,
            'user' => $user->only(['id', 'name', 'email', 'role']),
            'device' => $device->only(['id', 'device_identifier', 'name', 'status']),
        ];
    }

    public function logoutUser(User $user): void
    {
        $user->currentAccessToken()->delete();
    }

    public function getUserDetails(User $user): array
    {
        return $user->only(['id', 'name', 'email']);
    }

    private function getDeviceStatusMessage(UserDevice $device): string
    {
        switch ($device->status) {
            case UserDevice::STATUS_PENDING:
                return 'Your device is still pending admin approval.';
            case UserDevice::STATUS_REJECTED:
                $message = 'Your device registration has been rejected.';
                if ($device->admin_notes) {
                    $message .= " Reason: {$device->admin_notes}";
                }
                return $message;
            case UserDevice::STATUS_REVOKED:
                $message = 'Access for this device has been revoked.';
                if ($device->admin_notes) {
                    $message .= " Notes: {$device->admin_notes}";
                }
                return $message;
            default:
                return 'Device access denied.';
        }
    }
}