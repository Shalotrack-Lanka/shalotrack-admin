<?php

return [

    /*
    | Supplier delivery scanning (goods receipt of devices and SIMs). ON by default. It stays quietly off until
    | the database migrations for it have run (see SupplierDeliveryService::isEnabled). SUPPLIER_DELIVERIES_ENABLED=false
    | is the emergency switch: it hides the feature without a deploy; recorded deliveries stay in the database.
    |
    | While it is on, the purchasing chain is strict: goods are received only against a purchase order and never
    | more than the order is still waiting for, and stock is added only from a scanned delivery (its scanned
    | count; no typed quantities, no stock file import).
    */
    'enabled' => filter_var(env('SUPPLIER_DELIVERIES_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    /*
    | Enforcement date (YYYY-MM-DD). Empty (the default) = WARN ONLY: a device or SIM that was not received on a
    | supplier delivery is still registered, with a clear warning. From this date on it is REFUSED instead.
    | Devices and SIMs registered before the date are never affected. A value that is not a real date is
    | ignored (warn only), so a typo can never lock registration.
    | A device that WAS received on a delivery of a different device type is always refused (while enabled),
    | because registering it would take stock from the wrong type.
    */
    'enforce_from' => env('SUPPLIER_DELIVERIES_ENFORCE_FROM'),

    /*
    | Goods are received AGAINST A PURCHASE ORDER and the order is the limit (received can never pass ordered).
    | SUPPLIER_DELIVERIES_REQUIRE_PO=false lets a delivery be saved without an order (emergency switch only);
    | a delivery that does name an order is always held to it.
    */
    'require_purchase_order' => filter_var(env('SUPPLIER_DELIVERIES_REQUIRE_PO', true), FILTER_VALIDATE_BOOLEAN),

    /*
    | Stock on Manage Stock (typed quantity and the stock import file) comes ONLY from a received delivery, so it
    | can never be more than the supplier delivered. SUPPLIER_ALLOW_TYPED_STOCK=true re-opens typing and import
    | (emergency switch only, for example before the first purchase order exists).
    */
    'allow_typed_stock' => filter_var(env('SUPPLIER_ALLOW_TYPED_STOCK', false), FILTER_VALIDATE_BOOLEAN),

];
