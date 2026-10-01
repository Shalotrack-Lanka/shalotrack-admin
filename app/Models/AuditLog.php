<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One audit-trail entry. Written only through App\Services\Audit. Immutable: any attempt to update
 * or delete a row through the model throws. (Pruning old rows uses the query builder on purpose.)
 */
class AuditLog extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'occurred_at' => 'datetime',
        'changes'     => 'array',
        'meta'        => 'array',
    ];

    public const CATEGORY_MONEY = 'money';
    public const CATEGORY_DEVICE = 'device';
    public const CATEGORY_ACCOUNT = 'account';

    /** action => [category, human label]. The viewer's filter and labels come from here. */
    public const ACTIONS = [
        'subscription.updated'       => [self::CATEGORY_MONEY, 'Payment / subscription edited'],
        'bank_slip.viewed'           => [self::CATEGORY_MONEY, 'Bank slip viewed'],
        'commission.payout_recorded' => [self::CATEGORY_MONEY, 'Commission payout recorded'],
        'commission.rate_saved'      => [self::CATEGORY_MONEY, 'Commission rate saved'],
        'commission.rate_deleted'    => [self::CATEGORY_MONEY, 'Commission rate removed'],

        'device.intake_committed'    => [self::CATEGORY_DEVICE, 'Devices registered (scan intake)'],
        'device.activated'           => [self::CATEGORY_DEVICE, 'Device bound to a customer'],
        'device.reactivated'         => [self::CATEGORY_DEVICE, 'Expired device reactivated'],
        'device.replaced'            => [self::CATEGORY_DEVICE, 'Device replaced'],
        'device.status_changed'      => [self::CATEGORY_DEVICE, 'Device status changed'],
        'device.assigned'            => [self::CATEGORY_DEVICE, 'Dealer assigned a device to a customer'],
        'device.unassigned'          => [self::CATEGORY_DEVICE, 'Dealer unassigned a device'],
        'device.reassigned'          => [self::CATEGORY_DEVICE, 'Dealer reassigned a device'],
        'device.marked_broken'       => [self::CATEGORY_DEVICE, 'Dealer marked a device broken'],
        'device.moved_to_pending'    => [self::CATEGORY_DEVICE, 'Dealer moved a device to pending'],

        'dealer.created'             => [self::CATEGORY_ACCOUNT, 'Dealer created'],
        'dealer.updated'             => [self::CATEGORY_ACCOUNT, 'Dealer profile edited'],
        'dealer.status_changed'      => [self::CATEGORY_ACCOUNT, 'Dealer enabled / disabled'],
        'account.profile_updated'    => [self::CATEGORY_ACCOUNT, 'Own profile edited'],
        'account.password_reset'     => [self::CATEGORY_ACCOUNT, 'Password reset'],
    ];

    public const CATEGORIES = [
        self::CATEGORY_MONEY   => 'Money and subscriptions',
        self::CATEGORY_DEVICE  => 'Device lifecycle',
        self::CATEGORY_ACCOUNT => 'Dealer and user admin',
    ];

    public static function labelFor(string $action): string
    {
        return self::ACTIONS[$action][1] ?? $action;
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit log rows are immutable.'));
        static::deleting(fn () => throw new LogicException('Audit log rows cannot be deleted.'));
    }
}