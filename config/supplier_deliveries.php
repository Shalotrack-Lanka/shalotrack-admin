<?php

return [

    /*
    | Supplier delivery scanning (goods receipt of devices and SIMs). OFF by default: the code is deployed
    | dark and nothing in the portal changes until SUPPLIER_DELIVERIES_ENABLED=true. Turning it off again
    | hides the feature without a deploy; the recorded deliveries stay in the database.
    */
    'enabled' => (bool) env('SUPPLIER_DELIVERIES_ENABLED', false),

];
