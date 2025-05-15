<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class DeviceManagementService
{
    /**
     * Find or create a pending device for a user.
     *
     * @param User $user The user to find or create a device for
     * @param string $deviceIdentifier Unique identifier for the device
     * @param string|null $deviceName Name of the device (optional)
     * @param string $ipAddress IP address of the device
     * @return UserDevice The found or created device
     */
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

    /**
     * Update the last_used_at timestamp for an approved device.
     *
     * @param User $user The user who owns the device
     * @param string $deviceIdentifier Unique identifier for the device
     * @return bool True if the device was updated, false otherwise
     */
    public function updateDeviceLastUsed(User $user, string $deviceIdentifier): bool
    {
        $device = $user->devices()
            ->where('device_identifier', $deviceIdentifier)
            ->where('status', UserDevice::STATUS_APPROVED) // Only update if approved
            ->first();

        if ($device) {
            return $device->update(['last_used_at' => now()]);
        }
        return false;
    }

    /**
     * Get a device for a user by its identifier.
     *
     * @param User $user The user who owns the device
     * @param string $deviceIdentifier Unique identifier for the device
     * @return UserDevice|null The device if found, null otherwise
     */
    public function getDeviceForUserByIdentifier(User $user, string $deviceIdentifier): ?UserDevice
    {
        return $user->devices()
            ->where('device_identifier', $deviceIdentifier)
            ->first();
    }

    /**
     * List all devices for a user with selected fields.
     *
     * @param User $user The user whose devices to list
     * @return Collection Collection of devices
     */
    public function listUserDevices(User $user): Collection
    {
        return $user->devices()
            ->select([
                'id',
                'user_id',
                'name',
                'device_identifier',
                'status',
                'last_used_at',
                'admin_notes'
            ])
            ->get();
    }

    /**
     * List all devices with optional status filtering and pagination.
     *
     * @param string|null $status Status to filter by (optional)
     * @param int $perPage Number of items per page
     * @return LengthAwarePaginator Paginated devices
     */
    public function listAllDevicesFiltered(?string $status, int $perPage = 15): LengthAwarePaginator
    {
        $query = UserDevice::with(['user:id,name,email', 'approver:id,name']);

        if ($status) {
            $query->where('status', $status);
        }

        return $query->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Get detailed information about a device including user and approver.
     *
     * @param UserDevice $device The device to get details for
     * @return UserDevice The device with loaded relationships
     */
    public function getDeviceDetails(UserDevice $device): UserDevice
    {
        return $device->load('user:id,name,email', 'approver:id,name');
    }

    /**
     * Approves a device. If the user already has an approved device,
     * it will be revoked (one device per user policy).
     *
     * @param UserDevice $deviceToApprove The device to approve
     * @param User $admin The admin who is approving the device
     * @param string|null $notes Notes about the approval (optional)
     * @return UserDevice The approved device
     * @throws RuntimeException If the associated user is not found
     */
    public function approveDevice(UserDevice $deviceToApprove, User $admin, ?string $notes): UserDevice
    {
        return DB::transaction(function () use ($deviceToApprove, $admin, $notes) {
            $targetUser = $deviceToApprove->user;

            if (!$targetUser) {
                // Try to load the relationship if it doesn't exist
                $deviceToApprove->load('user');
                $targetUser = $deviceToApprove->user;

                // If still null, throw an error
                if (!$targetUser) {
                    Log::critical("DeviceManagementService: User relationship is null for UserDevice ID: {$deviceToApprove->id}. Cannot proceed with approval.");
                    // Throw an exception so the transaction is rolled back and the error is clear
                    throw new RuntimeException("Associated user not found for the device being approved (ID: {$deviceToApprove->id}).");
                }
            }

            $oldApprovedDevice = $targetUser->devices()
                ->where('status', UserDevice::STATUS_APPROVED)
                ->where('id', '!=', $deviceToApprove->id)
                ->first();

            if ($oldApprovedDevice) {
                $this->revokeOldDeviceInternal(
                    $oldApprovedDevice,
                    $targetUser,
                    $admin,
                    'Automatically revoked due to approval of new device: ' . $deviceToApprove->name
                );
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

    /**
     * Internal method to revoke an old device.
     *
     * @param UserDevice $oldDevice The device to revoke
     * @param User $targetUser The user who owns the device
     * @param User $admin The admin who is revoking the device
     * @param string $reason Reason for revocation
     * @return void
     */
    private function revokeOldDeviceInternal(UserDevice $oldDevice, User $targetUser, User $admin, string $reason): void
    {
        $oldDevice->status = UserDevice::STATUS_REVOKED;
        $oldDevice->admin_notes = ($oldDevice->admin_notes ? "{$oldDevice->admin_notes}\n" : '') .
            $reason . ' by ' . $admin->name . ' on ' . now()->toDateTimeString();
        $oldDevice->save();
        $this->revokeTokenForDevice($oldDevice, $targetUser);
    }

    /**
     * Revoke authentication tokens for a device.
     *
     * @param UserDevice $device The device to revoke tokens for
     * @param User|null $user The user who owns the device (optional, will be loaded from device if not provided)
     * @return void
     */
    private function revokeTokenForDevice(UserDevice $device, ?User $user = null): void
    {
        $targetUser = $user ?? $device->user;
        if ($targetUser) {
            $tokenName = 'auth_token_user_' . $targetUser->id . '_device_' . $device->id;
            $targetUser->tokens()->where('name', $tokenName)->delete();
        }
    }

    /**
     * Reject a device.
     *
     * @param UserDevice $deviceToReject The device to reject
     * @param User $admin The admin who is rejecting the device
     * @param string $notes Notes about the rejection
     * @return UserDevice The rejected device
     */
    public function rejectDevice(UserDevice $deviceToReject, User $admin, string $notes): UserDevice
    {
        return DB::transaction(function () use ($deviceToReject, $admin, $notes) {
            $deviceToReject->status = UserDevice::STATUS_REJECTED;
            $deviceToReject->approved_by = $admin->id; // Admin who performed the action
            $deviceToReject->approved_at = null;
            $deviceToReject->admin_notes = $notes;
            $deviceToReject->save();

            // Revoke any tokens for this device
            $this->revokeTokenForDevice($deviceToReject);

            // event(new DeviceRejected($deviceToReject));
            return $deviceToReject->fresh();
        });
    }

    /**
     * Revoke a device.
     *
     * @param UserDevice $deviceToRevoke The device to revoke
     * @param User $admin The admin who is revoking the device
     * @param string|null $notes Notes about the revocation (optional)
     * @return UserDevice The revoked device
     */
    public function revokeDevice(UserDevice $deviceToRevoke, User $admin, ?string $notes): UserDevice
    {
        return DB::transaction(function () use ($deviceToRevoke, $admin, $notes) {
            $deviceToRevoke->status = UserDevice::STATUS_REVOKED;
            $deviceToRevoke->admin_notes = $notes ?: 'Device access revoked by admin ' . $admin->name . '.';
            $deviceToRevoke->save();

            $this->revokeTokenForDevice($deviceToRevoke);
            // event(new DeviceRevoked($deviceToRevoke));
            return $deviceToRevoke->fresh();
        });
    }

    /**
     * Registers a device for a user by an admin.
     * Enforces one-device-per-user policy.
     *
     * @param User $targetUser The user to register the device for
     * @param string $deviceIdentifier Unique identifier for the device
     * @param string $deviceName Name of the device
     * @param User $admin The admin who is registering the device
     * @param string|null $notes Notes about the registration (optional)
     * @return UserDevice The registered device
     */
    public function registerDeviceForUserByAdmin(User $targetUser, string $deviceIdentifier, string $deviceName, User $admin, ?string $notes): UserDevice
    {
        return DB::transaction(function () use ($targetUser, $deviceIdentifier, $deviceName, $admin, $notes) {
            $oldApprovedDevice = $targetUser->devices()
                ->where('status', UserDevice::STATUS_APPROVED)
                // If admin registers the same device_identifier, don't revoke it.
                // This means we're updating/re-approving.
                ->where('device_identifier', '!=', $deviceIdentifier)
                ->first();

            if ($oldApprovedDevice) {
                $this->revokeOldDeviceInternal(
                    $oldApprovedDevice,
                    $targetUser,
                    $admin,
                    'Automatically revoked due to admin registration of new device: ' . $deviceName
                );
            }

            // Register or update and approve the NEW/REQUESTED device
            $newDevice = $targetUser->devices()->updateOrCreate(
                ['device_identifier' => $deviceIdentifier],
                [
                    'name' => $deviceName,
                    'status' => UserDevice::STATUS_APPROVED,
                    'approved_by' => $admin->id,
                    'approved_at' => now(),
                    'admin_notes' => $notes ?: 'Device pre-approved by admin ' . $admin->name . '.',
                    'user_id' => $targetUser->id, // Ensure it's filled if creating
                ]
            );

            return $newDevice->fresh();
        });
    }
}
