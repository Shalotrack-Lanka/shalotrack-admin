<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class Admin extends Authenticatable
{
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