<?php

namespace App\Models;

use App\Enums\DeviceStatus;
use Illuminate\Database\Eloquent\Model;

class SetupShalotrackDevice extends Model
{
    protected $table = 'setup_shalotrack_devices';
    protected $primaryKey = 'shdevice_id';

    protected $fillable = [
        'device_category',
        'imei_number',
        'sim_number',
        'iccid',
        'imsi',
        'registered_by',
        'intake_source',
        'status',
        'cancel_reason',
        'canceled_date',
        'dealer_id',
        'device_type_id',
        'allocated_at',
        'transfer_id',
    ];

    protected $casts = [
        'canceled_date' => 'datetime',
        'allocated_at'  => 'datetime',
    ];

    /**
     * Lifecycle (company activates first, dealer sells after):
     *
     *   Not Activated -> (admin activates) -> Activated, unsold
     *   Activated, unsold -> (admin transfers) -> in a dealer's stock
     *   dealer assigns to their customer -> Assigned to Customer
     *   admin binds it to the customer's vehicle + subscription in
     *   Customer Device Management -> Activated, with an activated_devices row
     *
     * "Bound" therefore means "has an activated_devices row for this IMEI"; the
     * status column alone cannot tell an unsold Activated device from a sold one.
     */
    protected static function notBound($query)
    {
        return $query->whereNotIn('imei_number', function ($sub) {
            $sub->select('imei_number')->from('activated_devices');
        });
    }

    /** Company stock an admin may transfer to a dealer: activated, unsold, with a SIM. */
    public function scopeCompanyStockForTransfer($query)
    {
        return static::notBound(
            $query->whereNull('dealer_id')
                ->whereNotNull('sim_number')
                ->where('status', DeviceStatus::Activated->value)
        );
    }

    /**
     * Devices a dealer can actually hand to a customer: theirs, activated, not yet
     * assigned to a customer. The assign actions require exactly that, so every
     * dealer stock list and count MUST use this scope; a looser filter shows
     * devices with an Assign button that can only fail. A legacy 'Not Activated'
     * device in dealer stock is NOT sellable until an admin activates it.
     */
    public function scopeAvailableForDealer($query, $dealerId)
    {
        return static::notBound(
            $query->where('dealer_id', $dealerId)
                ->where(function ($q) {
                    $q->whereNull('assigned_customer_id')->orWhere('assigned_customer_id', 0);
                })
                ->where('status', DeviceStatus::Activated->value)
        );
    }

    /**
     * Constraint for devices an admin may bind to a customer vehicle in Customer
     * Device Management: company-held activated devices that are not bound yet, or
     * devices a dealer has sold ('Assigned to Customer'). A dealer's unsold stock
     * is excluded: the dealer sells that. Works on Eloquent and query builders
     * (used by the form's exists rule too).
     */
    public static function applyBindableConstraint($query)
    {
        return static::notBound($query->where(function ($q) {
            $q->where(function ($q) {
                $q->where('status', DeviceStatus::Activated->value)->whereNull('dealer_id');
            })->orWhere('status', DeviceStatus::AssignedToCustomer->value);
        }));
    }

    public function scopeBindableByAdmin($query)
    {
        return static::applyBindableConstraint($query);
    }

    public function dealer()
    {
        return $this->belongsTo(Dealer::class);
    }

    public function deviceType()
    {
        return $this->belongsTo(DeviceType::class, 'device_type_id');
    }

    public function transferLedger()
    {
        return $this->belongsTo(DealerTransferLedger::class, 'transfer_id');
    }

        public function assignedCustomer()
    {
        return $this->belongsTo(DealerCustomerAd::class, 'assigned_customer_id', 'id');
    }
}