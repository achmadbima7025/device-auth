<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserDevice extends Model
{
    use HasFactory;

    public const string STATUS_PENDING = 'pending';
    public const string STATUS_APPROVED = 'approved';
    public const string STATUS_REJECTED = 'rejected';
    public const string STATUS_REVOKED = 'revoked';

    protected $fillable = ['user_id', 'device_identifier', 'name', 'status', 'approved_by', 'approved_at', 'admin_notes', 'last_login_ip', 'last_used_at',];

    public function casts(): array
    {
        return ['last_used_at' => 'datetime', 'approved_at' => 'datetime',];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }
}
