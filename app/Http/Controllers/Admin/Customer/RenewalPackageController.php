<?php

namespace App\Http\Controllers\Admin\Customer;

use App\Http\Controllers\Controller;
use App\Models\RenewalPackage;
use App\Services\Audit;
use App\Services\RenewalPackagesApi;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The pricing master (Renewal Plans guideline). ADMIN only. Packages are fixed (they map to the
 * API's durations); what an admin edits is price, channel margins, warranty length and availability.
 * Every change is audited with who and when, and carries an effective date.
 */
class RenewalPackageController extends Controller
{
    public function __construct(private RenewalPackagesApi $api)
    {
    }

    private const KEYS = ['customer_price', 'company_margin', 'distributor_margin', 'retailer_margin', 'warranty_months', 'is_active', 'effective_from'];

    public function index()
    {
        return view('admin.renewal-packages.index', [
            'packages' => RenewalPackage::orderBy('sort_order')->get(),
        ]);
    }

    public function update(Request $request, RenewalPackage $package)
    {
        $money = ['nullable', 'numeric', 'min:0', 'max:9999999.99', 'decimal:0,2'];
        $data = $request->validate([
            'customer_price'     => $money,
            'company_margin'     => $money,
            'distributor_margin' => $money,
            'retailer_margin'    => $money,
            'warranty_months'    => ['required', 'integer', 'min:0', 'max:120'],
            'is_active'          => ['required', 'boolean'],
            'effective_from'     => ['required', 'date'],
        ]);

        $parts = [$data['company_margin'] ?? null, $data['distributor_margin'] ?? null, $data['retailer_margin'] ?? null];
        $anyMargin = count(array_filter($parts, fn ($v) => $v !== null && $v !== '')) > 0;
        $price = $data['customer_price'] ?? null;
        $hasPrice = $price !== null && $price !== '';

        if ($hasPrice && count(array_filter($parts, fn ($v) => $v === null || $v === '')) > 0) {
            throw ValidationException::withMessages(['customer_price' => 'A priced package needs all three margins (company, distributor, retailer).']);
        }
        if (! $hasPrice && $anyMargin) {
            throw ValidationException::withMessages(['customer_price' => 'Enter the customer price too, or clear the margins.']);
        }
        // The guideline's rule: Customer price = company + distributor + retailer margin.
        if ($hasPrice && abs((float) $price - array_sum(array_map('floatval', $parts))) > 0.004) {
            throw ValidationException::withMessages(['customer_price' => 'The customer price must equal company + distributor + retailer margin.']);
        }

        $before = $package->only(self::KEYS);
        $package->update([
            'customer_price'     => $hasPrice ? $price : null,
            'company_margin'     => $hasPrice ? $data['company_margin'] : null,
            'distributor_margin' => $hasPrice ? $data['distributor_margin'] : null,
            'retailer_margin'    => $hasPrice ? $data['retailer_margin'] : null,
            'warranty_months'    => $data['warranty_months'],
            'is_active'          => $data['is_active'],
            'effective_from'     => $data['effective_from'],
            'updated_by'         => auth()->user()?->admin_id,
        ]);

        $changes = Audit::diff($this->plain($before), $this->plain($package->fresh()->only(self::KEYS)), self::KEYS);
        if ($changes !== []) {
            Audit::record('package.updated', 'renewal_package', (string) $package->id, $package->label, $changes, ['code' => $package->code]);
        }

        // The mobile app must show the price the admin just set. The save above is already committed, so a
        // failed push never undoes it; the admin is told plainly and can retry (php artisan renewal-packages:push).
        $push = $this->api->push();

        $response = back()->with('success', "{$package->label} saved" . ($changes === [] ? ' (nothing changed).' : '.') . ($push['ok'] ? ' ' . $push['message'] : ''));

        return $push['ok'] ? $response : $response->with('warning', $push['message'] . ' The price is saved here but the app still shows the old one. Press Save again in a minute, or run "php artisan renewal-packages:push".');
    }

    /** Dates and decimals as plain strings so the audit diff compares values, not object types. */
    private function plain(array $row): array
    {
        foreach ($row as $k => $v) {
            if ($v instanceof \DateTimeInterface) {
                $row[$k] = $v->format('Y-m-d');
            } elseif (is_bool($v)) {
                $row[$k] = $v ? 'yes' : 'no';
            } elseif ($v !== null) {
                $row[$k] = (string) $v;
            }
        }

        return $row;
    }
}