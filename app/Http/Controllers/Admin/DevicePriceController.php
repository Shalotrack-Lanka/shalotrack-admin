<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DevicePrice;
use App\Models\DeviceType;
use App\Services\Audit;
use App\Services\Billing\BillingSettings;
use App\Services\DevicePriceChain;
use Illuminate\Http\Request;

/**
 * The device price master: landing cost and the three channel margins per device type. ADMIN only (the
 * route group enforces it). Prices and percentages are calculated by DevicePriceChain, never typed in, so
 * they cannot disagree with their parts. Every change is audited with who, what and the effective date.
 */
class DevicePriceController extends Controller
{
    private const KEYS = ['landing_cost', 'company_margin', 'distributor_margin', 'retailer_margin', 'effective_from'];

    public function __construct(private BillingSettings $settings)
    {
    }

    public function index()
    {
        $prices = DevicePrice::all()->keyBy('device_type_id');
        $taxRate = $this->settings->taxEnabled() ? $this->settings->get('tax_rate') : null;

        $rows = DeviceType::orderBy('device_category')->orderBy('model')->get()->map(function (DeviceType $type) use ($prices, $taxRate) {
            $price = $prices->get($type->id);

            return [
                'type'  => $type,
                'price' => $price,
                'chain' => $price
                    ? DevicePriceChain::build($price->landing_cost, $price->company_margin, $price->distributor_margin, $price->retailer_margin, $taxRate)
                    : null,
            ];
        });

        return view('admin.device-prices.index', [
            'rows'     => $rows,
            'taxRate'  => $taxRate,
            'taxLabel' => $this->settings->get('tax_label'),
        ]);
    }

    public function update(Request $request, DeviceType $deviceType)
    {
        $money = ['required', 'numeric', 'min:0', 'max:9999999.99', 'decimal:0,2'];
        $data = $request->validate([
            'landing_cost'       => ['required', 'numeric', 'gt:0', 'max:9999999.99', 'decimal:0,2'],
            'company_margin'     => $money,
            'distributor_margin' => $money,
            'retailer_margin'    => $money,
            'effective_from'     => ['required', 'date'],
        ]);

        $existing = DevicePrice::where('device_type_id', $deviceType->id)->first();
        $before = $existing ? $this->plain($existing->only(self::KEYS)) : array_fill_keys(self::KEYS, null);

        $price = DevicePrice::updateOrCreate(
            ['device_type_id' => $deviceType->id],
            $data + ['updated_by' => auth()->user()?->admin_id],
        );

        $changes = Audit::diff($before, $this->plain($price->fresh()->only(self::KEYS)), self::KEYS);
        if ($changes !== []) {
            Audit::record(
                'device_price.updated',
                'device_type',
                (string) $deviceType->id,
                trim("{$deviceType->device_category} {$deviceType->model}"),
                $changes,
            );
        }

        return back()->with('success', trim("{$deviceType->device_category} {$deviceType->model}") . ' price saved' . ($changes === [] ? ' (nothing changed).' : '.'));
    }

    /** Dates and decimals as plain strings so the audit diff compares values, not object types. */
    private function plain(array $row): array
    {
        foreach ($row as $k => $v) {
            if ($v instanceof \DateTimeInterface) {
                $row[$k] = $v->format('Y-m-d');
            } elseif ($v !== null) {
                $row[$k] = (string) $v;
            }
        }

        return $row;
    }
}
