<?php

return [

    /*
    | Supplier delivery scanning (goods receipt of devices and SIMs). ON by default. It stays quietly off until
    | the database migrations for it have run (see SupplierDeliveryService::isEnabled). SUPPLIER_DELIVERIES_ENABLED=false
    | is the emergency switch: it hides the feature without a deploy; recorded deliveries stay in the database.
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

];
