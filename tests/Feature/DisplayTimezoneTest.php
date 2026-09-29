<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\DealerTransferLedger;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Timestamps are stored in UTC. Sri Lanka is UTC+05:30, so a transfer saved at 21:15 UTC on
 * 29 Sep is 02:45 AM on 30 Sep for the people using the portal. Screens must show that,
 * without touching what is stored.
 */
class DisplayTimezoneTest extends TestCase
{
    public function test_local_converts_for_display_and_leaves_the_original_untouched(): void
    {
        $utc = Carbon::parse('2026-09-29 21:15:00', 'UTC');

        $this->assertSame('2026-09-30 02:45 AM', $utc->local()->format('Y-m-d h:i A'));
        $this->assertSame('UTC', $utc->timezoneName, 'the stored value must not change');
        $this->assertSame('2026-09-29 21:15', $utc->format('Y-m-d H:i'));
        $this->assertSame('UTC', config('app.timezone'), 'storage stays UTC');
        $this->assertSame('Asia/Colombo', config('app.display_timezone'));
    }

    public function test_the_stock_transfer_history_shows_sri_lanka_time(): void
    {
        $this->withoutVite();
        \Illuminate\Support\Facades\Http::fake();
        Schema::create('Admins', function (Blueprint $t) {
            $t->string('admin_id')->primary(); $t->string('username')->nullable(); $t->string('password')->nullable();
            $t->string('full_name')->nullable(); $t->string('email')->nullable(); $t->string('phone_number')->nullable();
            $t->string('role'); $t->string('status')->default('ACTIVE');
            $t->unsignedBigInteger('dealer_id')->nullable(); $t->unsignedBigInteger('supplier_id')->nullable();
            $t->rememberToken(); $t->timestamps();
        });
        Schema::create('dealers', function (Blueprint $t) {
            $t->id(); $t->string('full_name'); $t->string('status')->default('active'); $t->timestamps();
        });
        Schema::create('dealer_transfer_ledgers', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('dealer_id'); $t->string('device_category'); $t->integer('quantity'); $t->timestamps();
        });
        Schema::create('setup_shalotrack_devices', function (Blueprint $t) {
            $t->id('shdevice_id'); $t->string('device_category')->nullable(); $t->string('imei_number')->unique();
            $t->string('sim_number')->nullable(); $t->string('status')->nullable(); $t->unsignedBigInteger('dealer_id')->nullable();
            $t->unsignedBigInteger('assigned_customer_id')->nullable(); $t->unsignedBigInteger('transfer_id')->nullable();
            $t->timestamp('allocated_at')->nullable(); $t->unsignedBigInteger('device_type_id')->nullable();
            $t->string('cancel_reason')->nullable(); $t->timestamp('canceled_date')->nullable(); $t->timestamps();
        });
        Schema::create('activated_devices', function (Blueprint $t) {
            $t->id('activated_device_id'); $t->string('imei_number')->nullable(); $t->timestamps();
        });
        \App\Models\Dealer::forceCreate(['id' => 15, 'full_name' => 'kasun rajitha']);
        $ledger = DealerTransferLedger::forceCreate(['dealer_id' => 15, 'device_category' => 'V10 Plus', 'quantity' => 1]);
        \Illuminate\Support\Facades\DB::table('dealer_transfer_ledgers')->where('id', $ledger->id)
            ->update(['created_at' => '2026-09-29 21:15:00', 'updated_at' => '2026-09-29 21:15:00']);
        $this->actingAs(Admin::forceCreate(['admin_id' => 'adm-1', 'username' => 'a', 'role' => 'ADMIN', 'status' => 'ACTIVE']));

        $html = $this->get(route('admin.stock_transfer'))->assertOk()->getContent();

        $this->assertStringContainsString('2026-09-30 02:45 AM', $html);
        $this->assertStringNotContainsString('2026-09-29 09:15 PM', $html);
    }

    /**
     * Guard: a view that prints an admin-owned timestamp straight from the model shows UTC
     * again. Every such call must go through ->local() (or ?->local()?->).
     */
    public function test_no_view_prints_a_stored_timestamp_or_the_clock_in_utc(): void
    {
        $offenders = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views')));
        foreach ($it as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            foreach (file($file->getPathname()) as $n => $line) {
                $bad = preg_match('/(created_at|updated_at|allocated_at|canceled_date|last_synced_at)\)?\??->format\(/', $line)
                    || preg_match('/(?<!local\(\))(?<!->)\bnow\(\)->format\(/', $line)
                    || str_contains($line, '\\Carbon\\Carbon::now()->format(')
                    || preg_match("/\\{\\{\\s*date\\('/", $line);
                if ($bad) {
                    $offenders[] = str_replace(base_path() . '/', '', $file->getPathname()) . ':' . ($n + 1);
                }
            }
        }

        $this->assertSame([], $offenders, "These lines print UTC. Route them through ->local():\n" . implode("\n", $offenders));
    }
}
