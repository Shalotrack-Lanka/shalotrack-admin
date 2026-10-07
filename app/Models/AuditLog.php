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
        'renewal.approved'           => [self::CATEGORY_MONEY, 'Customer renewal approved'],
        'renewal.rejected'           => [self::CATEGORY_MONEY, 'Customer renewal rejected'],
        'renewal.slip_viewed'        => [self::CATEGORY_MONEY, 'Renewal slip viewed'],
        'commission.payout_recorded' => [self::CATEGORY_MONEY, 'Commission payout recorded'],
        'commission.rate_saved'      => [self::CATEGORY_MONEY, 'Commission rate saved'],
        'commission.rate_deleted'    => [self::CATEGORY_MONEY, 'Commission rate removed'],
        'package.updated'            => [self::CATEGORY_MONEY, 'Renewal package price / warranty changed'],

        'device.intake_committed'    => [self::CATEGORY_DEVICE, 'Devices registered (scan intake)'],
        'device.activated'           => [self::CATEGORY_DEVICE, 'Device bound to a customer'],
        'device.reactivated'         => [self::CATEGORY_DEVICE, 'Expired device reactivated'],
        'device.replaced'            => [self::CATEGORY_DEVICE, 'Device replaced'],
        'install.updated'            => [self::CATEGORY_DEVICE, 'Install details edited'],
        'job.scheduled'              => [self::CATEGORY_DEVICE, 'Technician job scheduled'],
        'job.rescheduled'            => [self::CATEGORY_DEVICE, 'Technician job changed'],
        'job.completed'              => [self::CATEGORY_DEVICE, 'Technician job completed'],
        'job.cancelled'              => [self::CATEGORY_DEVICE, 'Technician job cancelled'],
        'device.status_changed'      => [self::CATEGORY_DEVICE, 'Device status changed'],
        'device.assigned'            => [self::CATEGORY_DEVICE, 'Dealer assigned a device to a customer'],
        'device.unassigned'          => [self::CATEGORY_DEVICE, 'Dealer unassigned a device'],
        'device.reassigned'          => [self::CATEGORY_DEVICE, 'Dealer reassigned a device'],
        'device.marked_broken'       => [self::CATEGORY_DEVICE, 'Dealer marked a device broken'],
        'device.moved_to_pending'    => [self::CATEGORY_DEVICE, 'Dealer moved a device to pending'],
        'ledger.stock_deleted'       => [self::CATEGORY_DEVICE, 'Stock ledger record removed'],
        'ledger.transfer_deleted'    => [self::CATEGORY_DEVICE, 'Dealer transfer history record removed'],

        'technician.created'         => [self::CATEGORY_ACCOUNT, 'Technician added'],
        'technician.updated'         => [self::CATEGORY_ACCOUNT, 'Technician edited'],
        'dealer.created'             => [self::CATEGORY_ACCOUNT, 'Dealer created'],
        'dealer.updated'             => [self::CATEGORY_ACCOUNT, 'Dealer profile edited'],
        'dealer.status_changed'      => [self::CATEGORY_ACCOUNT, 'Dealer enabled / disabled'],
        'customer_lead.deleted'      => [self::CATEGORY_ACCOUNT, 'Dealer removed a customer'],
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