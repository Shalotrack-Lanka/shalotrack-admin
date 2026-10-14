<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * A "forgot password" request waiting for an administrator.
 */
class PasswordResetRequest extends Model
{
    public const PENDING = 'PENDING';
    public const DONE = 'DONE';
    public const DISMISSED = 'DISMISSED';

    protected $guarded = [];

    protected $casts = [
        'requested_at' => 'datetime',
        'resolved_at'  => 'datetime',
    ];

    public function admin()
    {
        return $this->belongsTo(Admin::class, 'admin_id', 'admin_id');
    }

    /** Number shown next to "Profile" for administrators. Never throws (the table may not exist yet). */
    public static function pendingCount(): int
    {
        try {
            return (int) Cache::remember('password_reset_requests.pending', 30, fn () => static::where('status', self::PENDING)->count());
        } catch (Throwable) {
            return 0;
        }
    }

    public static function forgetPendingCount(): void
    {
        Cache::forget('password_reset_requests.pending');
    }
}