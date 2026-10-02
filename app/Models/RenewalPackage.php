<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/** One row of the pricing master (see the create_renewal_packages migration). */
class RenewalPackage extends Model
{
    protected $guarded = [];

    protected $casts = [
        'customer_price'     => 'decimal:2',
        'company_margin'     => 'decimal:2',
        'distributor_margin' => 'decimal:2',
        'retailer_margin'    => 'decimal:2',
        'is_active'          => 'boolean',
        'effective_from'     => 'date',
    ];

    /** Warranty months for a subscription_model string; 0 when unknown (or the table is not there yet). */
    public static function warrantyMonthsFor(?string $model): int
    {
        return (int) (self::forModel($model)?->warranty_months ?? 0);
    }

    public static function forModel(?string $model): ?self
    {
        if (! $model || ! Schema::hasTable('renewal_packages')) {
            return null;
        }

        return self::where('model', $model)->first();
    }

    /** A package can be offered to customers only when it is active and priced. */
    public function isOffered(): bool
    {
        return $this->is_active && $this->customer_price !== null;
    }
}