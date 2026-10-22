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
    public const CATEGORY_SECURITY = 'security';
    public const CATEGORY_ACTIVITY = 'activity';

    /** action => [category, human label]. The viewer's filter and labels come from here. */
    public const ACTIONS = [
        'distributor.device_activated' => [self::CATEGORY_MONEY, 'Distributor activated a device and recorded its payment'],
        'distributor.subscription_renewed' => [self::CATEGORY_MONEY, 'Distributor renewed a subscription'],
        'distributor.customer_registered' => [self::CATEGORY_ACCOUNT, 'Distributor registered a customer'],
        'subscription.updated'       => [self::CATEGORY_MONEY, 'Payment / subscription edited'],
        'bank_slip.viewed'           => [self::CATEGORY_MONEY, 'Bank slip viewed'],
        'renewal.approved'           => [self::CATEGORY_MONEY, 'Customer renewal approved'],
        'renewal.rejected'           => [self::CATEGORY_MONEY, 'Customer renewal rejected'],
        'renewal.slip_viewed'        => [self::CATEGORY_MONEY, 'Renewal slip viewed'],
        'commission.payout_recorded' => [self::CATEGORY_MONEY, 'Commission payout recorded'],
        'commission.rate_saved'      => [self::CATEGORY_MONEY, 'Commission rate saved'],
        'commission.rate_deleted'    => [self::CATEGORY_MONEY, 'Commission rate removed'],
        'package.updated'            => [self::CATEGORY_MONEY, 'Renewal package price / warranty changed'],
        'invoice.issued'             => [self::CATEGORY_MONEY, 'Wholesale invoice issued'],
        'credit_note.issued'         => [self::CATEGORY_MONEY, 'Credit note issued'],
        'receipt.recorded'           => [self::CATEGORY_MONEY, 'Payment receipt recorded'],
        'receipt.approved'           => [self::CATEGORY_MONEY, 'Payment receipt approved'],
        'receipt.rejected'           => [self::CATEGORY_MONEY, 'Payment receipt rejected'],
        'receipt.reversed'           => [self::CATEGORY_MONEY, 'Payment receipt reversed'],
        'billing.settings_changed'   => [self::CATEGORY_MONEY, 'Billing settings changed'],
        'billing.party_updated'      => [self::CATEGORY_MONEY, 'Billing party details edited'],
        'billing.document_downloaded' => [self::CATEGORY_MONEY, 'Invoice / statement downloaded'],

        'device.intake_committed'    => [self::CATEGORY_DEVICE, 'Devices registered (scan intake)'],
        'device.command_sent'        => [self::CATEGORY_DEVICE, 'Dealer or distributor sent a command to a device'],
        'device.command_denied'      => [self::CATEGORY_SECURITY, 'Command to a device refused (not their customer)'],
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
        'stock.transferred'          => [self::CATEGORY_DEVICE, 'Devices transferred (company or distributor to distributor / dealer)'],
        'ledger.transfer_deleted'    => [self::CATEGORY_DEVICE, 'Dealer transfer history record removed'],

        'technician.created'         => [self::CATEGORY_ACCOUNT, 'Technician added'],
        'technician.updated'         => [self::CATEGORY_ACCOUNT, 'Technician edited'],
        'dealer.created'             => [self::CATEGORY_ACCOUNT, 'Dealer created'],
        'dealer.updated'             => [self::CATEGORY_ACCOUNT, 'Dealer profile edited'],
        'dealer.status_changed'      => [self::CATEGORY_ACCOUNT, 'Dealer enabled / disabled'],
        'customer_lead.deleted'      => [self::CATEGORY_ACCOUNT, 'Dealer removed a customer'],
        'account.profile_updated'    => [self::CATEGORY_ACCOUNT, 'Own profile edited'],
        'account.role_changed'       => [self::CATEGORY_ACCOUNT, 'Login role changed (dealer / distributor)'],
        'account.password_reset'     => [self::CATEGORY_ACCOUNT, 'Password reset (temporary password set by an administrator)'],
        'account.password_changed'   => [self::CATEGORY_ACCOUNT, 'Own password changed'],
        'account.password_reset_dismissed' => [self::CATEGORY_ACCOUNT, 'Password reset request dismissed'],
        'admin.created'              => [self::CATEGORY_ACCOUNT, 'Administrator added'],

        'auth.login'                 => [self::CATEGORY_SECURITY, 'Signed in'],
        'auth.logout'                => [self::CATEGORY_SECURITY, 'Signed out'],
        'auth.login_failed'          => [self::CATEGORY_SECURITY, 'Failed sign-in attempt'],
        'auth.lockout'               => [self::CATEGORY_SECURITY, 'Locked out (too many failed attempts)'],
        'auth.password_reset_requested' => [self::CATEGORY_SECURITY, 'Forgot-password request'],
        'auth.reauth_failed'         => [self::CATEGORY_SECURITY, 'Wrong password on a sensitive action'],

        // Written automatically for anything that has no detailed entry of its own (see AuditAdminActivity).
        'request.write'              => [self::CATEGORY_ACTIVITY, 'Portal change (automatic record)'],
        'data.downloaded'            => [self::CATEGORY_ACTIVITY, 'File or report downloaded'],
    ];

    public const CATEGORIES = [
        self::CATEGORY_MONEY   => 'Money and subscriptions',
        self::CATEGORY_DEVICE  => 'Device lifecycle',
        self::CATEGORY_ACCOUNT => 'Dealer and user admin',
        self::CATEGORY_SECURITY => 'Sign-in and security',
        self::CATEGORY_ACTIVITY => 'Other portal activity',
    ];

    /** Browser, system and device class of whoever made the request, read from the stored User-Agent. */
    public function clientDevice(): array
    {
        return \App\Support\UserAgent::parse($this->user_agent);
    }

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