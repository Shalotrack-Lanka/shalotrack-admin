<?php

/*
| Cross-origin access is CLOSED. The admin portal is a same-origin web app, and the Android app is a native
| client (CORS is a browser rule, so it does not apply to it). With no paths listed, no cross-origin browser
| request is ever answered with permissive headers. If a browser client on another origin is ever needed,
| list that ONE origin below; never use '*' together with credentials.
*/
return [
    'paths'                    => [],
    'allowed_methods'          => ['GET', 'POST'],
    'allowed_origins'          => [],
    'allowed_origins_patterns' => [],
    'allowed_headers'          => ['Content-Type', 'X-Requested-With'],
    'exposed_headers'          => [],
    'max_age'                  => 0,
    'supports_credentials'     => false,
];
