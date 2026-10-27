<?php

namespace Tests\Pgsql\Concerns;

use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Support\Facades\Http;

/**
 * Shared helpers for the supplier, purchase-order and stock suites. Uses BuildsIntakeData for device types, stock
 * rows and Excel files, so a class that uses this trait must also use BuildsIntakeData.
 * Every row is rolled back by DatabaseTransactions.
 */
trait BuildsSupplierData
{
    protected function quietApi(): void
    {
        // The admin sidebar asks the app server for the complaint count on every page.
        Http::fake(['*' => Http::response(['data' => []], 200)]);
    }

    protected function makeSupplier(array $overrides = []): Supplier
    {
        $n = $this->nextIntakeNumber();

        return Supplier::create(array_merge([
            'name'         => "Autotest Supplier {$n}",
            'email'        => "autotest+supplier{$n}@example.test",
            'phone_number' => '+94 77 123 4567',
            'country'      => 'Sri Lanka',
            'status'       => 'Active',
        ], $overrides));
    }

    /** A product linked to a device type (it touches stock when ordered). */
    protected function makeDeviceProduct(?\App\Models\DeviceType $type = null): Product
    {
        $type ??= $this->makeDeviceType();

        return Product::create([
            'product_name'   => trim($type->device_category . ' ' . $type->model),
            'device_type_id' => $type->id,
        ]);
    }

    /** A product with no device type (a SIM package, a service...): never touches stock. */
    protected function makePlainProduct(?string $name = null): Product
    {
        return Product::create(['product_name' => $name ?? 'Autotest SIM package ' . $this->nextIntakeNumber()]);
    }

    protected function offer(Supplier $supplier, Product $product, float $price = 100.0, int $qty = 5): void
    {
        $supplier->products()->attach($product->id, ['price' => $price, 'qty' => $qty]);
    }
}