<?php

namespace App\Services\Billing;

use App\Models\AuditLog;
use App\Services\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Switches and defaults for wholesale billing. Safe defaults: tax OFF (the legal rate is never guessed),
 * approval OFF (until the Finance role exists), payment terms 30 days.
 */
class BillingSettings
{
    public const DEFAULTS = [
        'tax_enabled'       => '0',
        'tax_rate'          => '0.00',
        'tax_label'         => 'VAT',
        'payment_terms_days' => '30',
        'approval_required' => '0',
        'invoice_footer'    => '',
    ];

    public function all(): array
    {
        $stored = DB::table('billing_settings')->pluck('value', 'key')->all();

        return array_replace(self::DEFAULTS, array_intersect_key($stored, self::DEFAULTS));
    }

    public function get(string $key): string
    {
        return (string) ($this->all()[$key] ?? '');
    }

    public function taxEnabled(): bool
    {
        return $this->get('tax_enabled') === '1' && (float) $this->get('tax_rate') > 0;
    }

    public function approvalRequired(): bool
    {
        return $this->get('approval_required') === '1';
    }

    public function termsDays(): int
    {
        return max(0, min(365, (int) $this->get('payment_terms_days')));
    }

    /** @param array<string,string> $new validated values; only known keys are stored */
    public function save(array $new, ?string $adminId): void
    {
        $old = $this->all();
        $new = array_intersect_key($new, self::DEFAULTS);

        DB::transaction(function () use ($new, $adminId) {
            foreach ($new as $key => $value) {
                $exists = DB::table('billing_settings')->where('key', $key)->exists();
                $row = ['value' => (string) $value, 'updated_by' => $adminId, 'updated_at' => now()];
                $exists
                    ? DB::table('billing_settings')->where('key', $key)->update($row)
                    : DB::table('billing_settings')->insert(['key' => $key, 'created_at' => now()] + $row);
            }
        });

        $diff = Audit::diff($old, array_replace($old, $new), array_keys(self::DEFAULTS));
        if ($diff !== []) {
            Audit::record('billing.settings_changed', 'billing_settings', 'settings', 'Billing settings', $diff);
        }
    }
}
