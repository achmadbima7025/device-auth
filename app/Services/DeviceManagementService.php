<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Support\Facades\DB;
use Illuminate\Pagination\LengthAwarePaginator;

class DeviceManagementService
{
    public function findOrCreatePendingDeviceForUser(User $user, string $deviceIdentifier, ?string $deviceName, string $ipAddress): UserDevice
    {
        $device = $user->devices()->firstOrNew(
            ['device_identifier' => $deviceIdentifier],
            [
                'name' => $deviceName ?: 'Unknown Device (' . now()->toDateTimeString() . ')',
                'status' => UserDevice::STATUS_PENDING,
                'last_login_ip' => $ipAddress,
            ]
        );

        if (!$device->exists) {
            $device->save();
            // event(new NewDevicePendingApproval($device));
        }
        return $device;
    }

    public function updateDeviceLastUsed(User $user, string $deviceIdentifier): bool
    {
        $device = $user->devices()
            ->where('device_identifier', $deviceIdentifier)
            ->where('status', UserDevice::STATUS_APPROVED) // Hanya update jika approved
            ->first();

        if ($device) {
            return $device->update(['last_used_at' => now()]);
        }
        return false;
    }

    public function getDeviceForUserByIdentifier(User $user, string $deviceIdentifier): ?UserDevice
    {
         return $user->devices()
            ->where('device_identifier', $deviceIdentifier)
            ->first();
    }

    public function listUserDevices(User $user): \Illuminate\Database\Eloquent\Collection
    {
        return $user->devices()->select(['id', 'name', 'device_identifier', 'status', 'last_used_at', 'admin_notes'])->get();
    }

    public function listAllDevicesFiltered(?string $status, int $perPage = 15): LengthAwarePaginator
    {
        $query = UserDevice::with('user:id,name,email', 'approver:id,name');

        if ($status) {
            $query->where('status', $status);
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    public function getDeviceDetails(UserDevice $device): UserDevice
    {
        return $device->load('user:id,name,email', 'approver:id,name');
    }

    /**
     * Approves a device. If the user already has an approved device,
     * it will be revoked (one device per user policy).
     */
    public function approveDevice(UserDevice $deviceToApprove, User $admin, ?string $notes): UserDevice
    {
        return DB::transaction(function () use ($deviceToApprove, $admin, $notes) {
            $targetUser = $deviceToApprove->user;

            $oldApprovedDevice = $targetUser->devices()
                ->where('status', UserDevice::STATUS_APPROVED)
                ->where('id', '!=', $deviceToApprove->id)
                ->first();

            if ($oldApprovedDevice) {
                $this->revokeOldDeviceInternal($oldApprovedDevice, $targetUser, $admin, 'Automatically revoked due to approval of new device: ' . $deviceToApprove->name);
            }

            $deviceToApprove->status = UserDevice::STATUS_APPROVED;
            $deviceToApprove->approved_by = $admin->id;
            $deviceToApprove->approved_at = now();
            $deviceToApprove->admin_notes = $notes ?: 'Device approved by admin ' . $admin->name . '.';
            $deviceToApprove->save();

            // event(new DeviceApproved($deviceToApprove));
            return $deviceToApprove->fresh();
        });
    }

    public function rejectDevice(UserDevice $deviceToReject, User $admin, string $notes): UserDevice
    {
        $deviceToReject->status = UserDevice::STATUS_REJECTED;
        $deviceToReject->approved_by = $admin->id; // Admin yang melakukan aksi
        $deviceToReject->approved_at = null;
        $deviceToReject->admin_notes = $notes;
        $deviceToReject->save();

        // event(new DeviceRejected($deviceToReject));
        return $deviceToReject;
    }

    public function revokeDevice(UserDevice $deviceToRevoke, User $admin, ?string $notes): UserDevice
    {
        $deviceToRevoke->status = UserDevice::STATUS_REVOKED;
        $deviceToRevoke->admin_notes = $notes ?: 'Device access revoked by admin ' . $admin->name . '.';
        $deviceToRevoke->save();

        $this->revokeTokenForDevice($deviceToRevoke);
        // event(new DeviceRevoked($deviceToRevoke));
        return $deviceToRevoke;
    }

    /**
     * Registers a device for a user by an admin.
     * Enforces one-device-per-user policy.
     */
    public function registerDeviceForUserByAdmin(
        User $targetUser,
        string $deviceIdentifier,
        string $deviceName,
        User $admin,
        ?string $notes
    ): UserDevice {
        return DB::transaction(function () use ($targetUser, $deviceIdentifier, $deviceName, $admin, $notes) {
            $oldApprovedDevice = $targetUser->devices()
                ->where('status', UserDevice::STATUS_APPROVED)
                // Jika admin mendaftarkan device_identifier yang sama, jangan cabut dirinya sendiri.
                // Ini berarti kita update/re-approve.
                ->where('device_identifier', '!=', $deviceIdentifier)
                ->first();

            if ($oldApprovedDevice) {
                 $this->revokeOldDeviceInternal($oldApprovedDevice, $targetUser, $admin, 'Automatically revoked due to admin registration of new device: ' . $deviceName);
            }

            // Daftarkan atau update dan setujui perangkat BARU/YANG DIMINTA
            $newDevice = $targetUser->devices()->updateOrCreate(
                ['device_identifier' => $deviceIdentifier],
                [
                    'name' => $deviceName,
                    'status' => UserDevice::STATUS_APPROVED,
                    'approved_by' => $admin->id,
                    'approved_at' => now(),
                    'admin_notes' => $notes ?: 'Device pre-approved by admin ' . $admin->name . '.',
                    'user_id' => $targetUser->id, // Pastikan diisi jika create
                ]
            );
            return $newDevice->fresh();
        });
    }

    private function revokeOldDeviceInternal(UserDevice $oldDevice, User $targetUser, User $admin, string $reason): void
    {
        $oldDevice->status = UserDevice::STATUS_REVOKED;
        $oldDevice->admin_notes = ($oldDevice->admin_notes ? "{$oldDevice->admin_notes}\n" : '') . $reason . ' by ' . $admin->name . ' on ' . now()->toDateTimeString();
        $oldDevice->save();
        $this->revokeTokenForDevice($oldDevice, $targetUser);
    }

    private function revokeTokenForDevice(UserDevice $device, ?User $user = null): void
    {
        $targetUser = $user ?? $device->user;
        if ($targetUser) {
            $tokenName = 'auth_token_user_' . $targetUser->id . '_device_' . $device->id;
            $targetUser->tokens()->where('name', $tokenName)->delete();
        }
    }
}