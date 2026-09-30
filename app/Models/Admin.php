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

    public function dealer()
    {
        return $this->belongsTo(Dealer::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }
}