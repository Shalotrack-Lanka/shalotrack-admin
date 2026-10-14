<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Developer account(s)
    |--------------------------------------------------------------------------
    |
    | Usernames (comma separated, not case sensitive) of the ADMIN logins that act as the
    | company's developer / support account. Besides everything an administrator can do, they:
    |   - see every account of every role, and can set a temporary password for any of them
    |     (the person must then choose their own at the next sign-in),
    |   - see the whole audit log (every other administrator sees only their own entries).
    |
    | Nobody else can take one of these names: usernames are unique ignoring case, the profile
    | page cannot change a username, and the add-administrator form refuses an existing name.
    | Override on the server with ERP_DEVELOPER_USERNAMES=admin001,other.name
    |
    */
    'developer_usernames' => array_values(array_filter(array_map(
        fn ($u) => strtolower(trim($u)),
        explode(',', (string) env('ERP_DEVELOPER_USERNAMES', 'admin001'))
    ))),

];