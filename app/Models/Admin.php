<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class Admin extends Authenticatable
{
    // Needed for Password::sendResetLink(): the reset e-mail is sent with $user->notify().
    // Without it "Forgot password" threw "Call to undefined method Admin::notify()" (HTTP 500).
    use Notifiable;

    protected $table = 'Admins';

    protected $primaryKey = 'admin_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'username',
        'password',
        'full_name',
        'email',
        'phone_number',
        'role',
        'status',
        'dealer_id',
        'supplier_id',
    ];

    protected $hidden = [
        'password',
    ];

    // must_change_password and password_changed_at are NOT fillable on purpose: only the account / reset
    // screens set them (forceFill), never a request field.
    protected $casts = [
        'must_change_password' => 'boolean',
        'password_changed_at'  => 'datetime',
    ];

    /**
     * The company's developer / support login (config/erp.php, default "admin001"): sees every account and
     * the whole audit log, and can set a temporary password for any login. An active ADMIN only.
     */
    public function isDeveloper(): bool
    {
        return $this->role === 'ADMIN'
            && $this->status === 'ACTIVE'
            && in_array(strtolower((string) $this->username), (array) config('erp.developer_usernames', ['admin001']), true);
    }

    /** Route name of this person's home screen, by role. */
    public function homeRouteName(): string
    {
        return match ($this->role) {
            'ADMIN'      => 'admin.dashboard',
            'DEALER'     => 'dealer.dashboard',
            'DISTRIBUTOR' => 'distributor.dashboard',
            'FINANCE'    => 'finance.dashboard',
            'TECHNICIAN' => 'technician.dashboard',
            'SUPPLIER'   => 'supplier.dashboard',
            default      => 'login',
        };
    }

    /** Route name of the screen where this person edits their own details, by role (null = none here). */
    public function profileRouteName(): ?string
    {
        return match ($this->role) {
            'ADMIN'      => 'admin.profile',
            'DISTRIBUTOR' => 'distributor.profile',
            'FINANCE'    => 'finance.profile',
            'TECHNICIAN' => 'technician.profile',
            default      => null,
        };
    }

    public function dealer()
    {
        return $this->belongsTo(Dealer::class);
    }

    public function isDistributor(): bool
    {
        return $this->role === 'DISTRIBUTOR' && $this->status === 'ACTIVE';
    }

    /**
     * The distributor's own business record (its dealers row), or null. A DISTRIBUTOR login only counts
     * while it points at an ACTIVE dealers row whose type really is "distributor"; anything else gets
     * nothing, so a mis-linked login can never see another business's stock or dealers.
     */
    public function distributorDealer(): ?Dealer
    {
        if (! $this->isDistributor() || empty($this->dealer_id)) {
            return null;
        }

        $dealer = Dealer::find($this->dealer_id);

        return $dealer && $dealer->dealer_status === 'distributor' && $dealer->status === 'active' ? $dealer : null;
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }
}