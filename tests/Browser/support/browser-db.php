<?php

/**
 * Seed and clean up the data the browser tests need. Used only by the browser test runner (never by the app).
 *
 *   php tests/Browser/support/browser-db.php check
 *   php tests/Browser/support/browser-db.php setup    <state-file.json>
 *   php tests/Browser/support/browser-db.php teardown <state-file.json>
 *
 * SAFETY: every command first checks that the database connection is the isolated "autotest" schema and an
 * "autotest_user..." login, exactly like the PHPUnit Pgsql suite does. Anything else stops with exit code 2
 * before a single row is read or written. Only rows this script created (remembered in the state file by id)
 * are ever deleted.
 */

use App\Models\Admin;
use App\Models\Dealer;
use App\Models\DeviceType;
use App\Models\Supplier;
use App\Models\Technician;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

$root = dirname(__DIR__, 3);
chdir($root);

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';

require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// ───────────────────────── the safety check ─────────────────────────
try {
    $row = DB::selectOne('select current_schema() as s, current_user as u');
} catch (Throwable $e) {
    fwrite(STDERR, "BROWSER TESTS STOPPED: cannot connect to the database (" . $e->getMessage() . ")\n");
    exit(2);
}
if (DB::getDriverName() !== 'pgsql' || $row->s !== 'autotest' || ! str_starts_with($row->u, 'autotest_user')) {
    fwrite(STDERR, "BROWSER TESTS STOPPED: unsafe database connection ({$row->s} / {$row->u}). Only the autotest schema is allowed.\n");
    exit(2);
}

// Any failure from here on must stop the run with a non-zero exit code, never look like success.
set_exception_handler(function (Throwable $e) {
    fwrite(STDERR, "BROWSER TEST DATA ERROR: " . $e->getMessage() . "\n");
    exit(1);
});

$command = $argv[1] ?? '';
$stateFile = $argv[2] ?? null;

if ($command === 'check') {
    echo "ok\n";
    exit(0);
}

if (! in_array($command, ['setup', 'teardown'], true) || ! $stateFile) {
    fwrite(STDERR, "usage: browser-db.php check | setup <state.json> | teardown <state.json>\n");
    exit(1);
}

// ───────────────────────── removing rows safely ─────────────────────────
// Visiting a page can make the portal create rows that point at a test dealer or supplier (billing parties,
// ledgers, ...). So before a parent row is deleted, every row that points at it is deleted first, found from the
// database's own foreign keys, however deep the chain goes. Only rows that point at OUR rows are touched.
$purge = function (string $table, string $column, array $ids, int $depth = 0) use (&$purge): void {
    if (! $ids) {
        return;
    }
    if ($depth > 6) {
        throw new RuntimeException("dependency chain too deep at {$table}");
    }

    $foreignKeys = DB::select(
        "select c.conrelid::regclass::text as child, a.attname as child_col
           from pg_constraint c
           join pg_attribute a on a.attrelid = c.conrelid and a.attnum = c.conkey[1]
          where c.contype = 'f' and c.confrelid = ?::regclass and array_length(c.conkey, 1) = 1",
        [$table]
    );

    foreach ($foreignKeys as $fk) {
        $child = trim($fk->child, '"');
        $primary = DB::selectOne(
            "select a.attname from pg_index i join pg_attribute a on a.attrelid = i.indrelid and a.attnum = any(i.indkey)
              where i.indrelid = ?::regclass and i.indisprimary and i.indnatts = 1",
            [$fk->child]
        );

        if ($primary) {
            $childIds = DB::table($child)->whereIn($fk->child_col, $ids)->pluck($primary->attname)->all();
            // A table that points at itself (dealers.distributor_id) only recurses on rows that are not ours already.
            $childIds = array_values(array_diff($childIds, $table === $child ? $ids : []));
            $purge($child, $primary->attname, $childIds, $depth + 1);
        }

        DB::table($child)->whereIn($fk->child_col, $ids)->delete();
    }

    DB::table($table)->whereIn($column, $ids)->delete();
};

// Rows from an earlier run that was killed before its cleanup (Ctrl+C, a closed terminal). The names below are
// reserved for the browser tests and this schema is the isolated autotest one, so removing by name is safe.
$sweep = function () use ($purge): void {
    DB::transaction(function () use ($purge) {
        $adminIds = DB::table('Admins')->where('username', 'like', 'autotest-browser-%')->pluck('admin_id')->all();
        if ($adminIds) {
            DB::table('audit_logs')->whereIn('actor_id', $adminIds)->delete();
            DB::table('password_reset_requests')->whereIn('admin_id', $adminIds)->delete();
            DB::table('Admins')->whereIn('admin_id', $adminIds)->delete();
        }
        DB::table('audit_logs')->where('actor_name', 'like', 'Autotest Browser %')->delete();
        DB::table('password_reset_requests')->where('username', 'like', 'autotest-browser-%')->delete();

        $purge('device_types', 'id', DeviceType::where('device_category', 'like', 'AUTOTEST-BROWSER-%')->pluck('id')->all());
        $purge('technicians', 'id', Technician::where('name', 'like', 'Autotest Browser %')->pluck('id')->all());
        $purge('suppliers', 'id', Supplier::where('name', 'like', 'Autotest Browser %')->pluck('id')->all());
        $purge('dealers', 'id', Dealer::where('full_name', 'like', 'Autotest Browser %')->pluck('id')->all());
    });
};

// ───────────────────────── setup ─────────────────────────
if ($command === 'setup') {
    $sweep();

    $tag      = 'br' . substr(md5(random_bytes(8)), 0, 8);
    $password = 'Autotest#12345';
    $created  = ['admins' => [], 'dealers' => [], 'suppliers' => [], 'technicians' => [], 'device_types' => []];

    $dealer = Dealer::create([
        'full_name' => "Autotest Browser Dealer {$tag}", 'dealer_status' => 'retailer', 'region' => 'colombo',
        'country' => 'Sri Lanka', 'contact_email' => "autotest+{$tag}dealer@example.test", 'status' => 'active',
        'created_by' => 'autotest',
    ]);
    $created['dealers'][] = $dealer->id;

    $distributor = Dealer::create([
        'full_name' => "Autotest Browser Distributor {$tag}", 'dealer_status' => 'distributor', 'region' => 'colombo',
        'country' => 'Sri Lanka', 'contact_email' => "autotest+{$tag}distributor@example.test", 'status' => 'active',
        'created_by' => 'autotest',
    ]);
    $created['dealers'][] = $distributor->id;

    $supplier = Supplier::create([
        'name' => "Autotest Browser Supplier {$tag}", 'email' => "autotest+{$tag}supplier@example.test",
        'phone_number' => '+94 77 123 4567', 'country' => 'Sri Lanka', 'status' => 'Active',
    ]);
    $created['suppliers'][] = $supplier->id;

    $type = DeviceType::create([
        'device_category' => "AUTOTEST-BROWSER-{$tag}", 'model' => 'Basic', 'protocol' => 'GT06', 'features' => [],
    ]);
    $created['device_types'][] = $type->id;

    $links = [
        'ADMIN'       => [],
        'DEALER'      => ['dealer_id' => $dealer->id],
        'DISTRIBUTOR' => ['dealer_id' => $distributor->id],
        'SUPPLIER'    => ['supplier_id' => $supplier->id],
        'FINANCE'     => [],
        'TECHNICIAN'  => [],
    ];

    $users = [];
    foreach ($links as $role => $extra) {
        $id = (string) Str::uuid();
        $admin = new Admin();
        $admin->forceFill(array_merge([
            'admin_id' => $id, 'username' => 'autotest-browser-' . strtolower($role) . '-' . $tag,
            'password' => Hash::make($password), 'full_name' => 'Autotest Browser ' . $role,
            'email' => "autotest+{$tag}" . strtolower($role) . '@example.test', 'role' => $role,
            'status' => 'ACTIVE', 'must_change_password' => false,
        ], $extra))->save();

        $created['admins'][] = $id;
        $users[$role] = ['username' => $admin->username, 'admin_id' => $id];
    }

    // Every page that needs no id in its address, with the role it is for. Read from the real route table, so a
    // new page is picked up by the smoke test automatically.
    $pages = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        $uri = $route->uri();
        if (! in_array('GET', $route->methods(), true) || str_contains($uri, '{') || str_starts_with($uri, 'api/')
            || in_array($uri, ['up', 'storage/{path}'], true) || str_starts_with($uri, '_') || str_starts_with($uri, 'sanctum/')) {
            continue;
        }
        $roles = [];
        $developerOnly = false;
        foreach ($route->gatherMiddleware() as $m) {
            if ($m === 'developer' || (is_string($m) && str_contains($m, 'EnsureDeveloper'))) {
                $developerOnly = true;
            }
            // The alias 'role:ADMIN,DEALER' (or the class name with the same ':roles' ending).
            if (is_string($m) && (str_starts_with($m, 'role:') || str_contains($m, 'EnsureRole:'))) {
                $roles = array_map('trim', explode(',', substr($m, strpos($m, ':') + 1)));
            }
        }
        $pages[] = ['uri' => '/' . ltrim($uri, '/'), 'name' => $route->getName(), 'roles' => $roles, 'developerOnly' => $developerOnly];
    }

    file_put_contents($stateFile, json_encode([
        'tag' => $tag, 'password' => $password, 'users' => $users, 'created' => $created, 'pages' => $pages,
    ], JSON_PRETTY_PRINT));

    echo "seeded " . count($users) . " logins, " . count($pages) . " pages\n";
    exit(0);
}

// ───────────────────────── teardown ─────────────────────────
$state = json_decode((string) @file_get_contents($stateFile), true);
if (! is_array($state) || empty($state['created'])) {
    fwrite(STDERR, "nothing to clean up (no state file)\n");
    exit(0);
}

$adminIds = $state['created']['admins'] ?? [];
$usernames = array_column($state['users'] ?? [], 'username');

DB::transaction(function () use ($state, $adminIds, $usernames, $purge) {
    if ($adminIds) {
        DB::table('audit_logs')->whereIn('actor_id', $adminIds)->delete();
        DB::table('password_reset_requests')->whereIn('admin_id', $adminIds)->delete();
        DB::table('Admins')->whereIn('admin_id', $adminIds)->delete();
    }
    if ($usernames) {
        DB::table('password_reset_requests')->whereIn('username', $usernames)->delete();
    }

    $purge('device_types', 'id', $state['created']['device_types'] ?? []);
    $purge('technicians', 'id', $state['created']['technicians'] ?? []);
    $purge('suppliers', 'id', $state['created']['suppliers'] ?? []);
    $purge('dealers', 'id', $state['created']['dealers'] ?? []);
});

$sweep();

@unlink($stateFile);
echo "cleaned up\n";
