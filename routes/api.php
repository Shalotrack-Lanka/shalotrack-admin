<?php

use Illuminate\Support\Facades\Route;

// All routes here automatically start with http://127.0.0.1:8000/api/...
//
// Customer-facing, Firebase-authenticated endpoints do NOT belong here.
// Customers and the Android app talk to the C# API (api.shalotrack.com),
// which already owns Firebase JWT verification and is the source of
// truth for Customer records. This admin app's CustomerAd table is a
// read-only sync mirror of that data (see customers:sync /
// SyncCustomersFromApi) — writing to it from a locally-verified Firebase
// token would create customer_id values that never match the API's real
// records. An earlier scaffold (`auth.firebase` middleware + /Customers/me)
// attempted this and was removed 2026-09-28; see VerifyAdminSyncKey for
// the correct pattern for the API to call back into this app.

// Called BY the C# API (reverse direction of the existing sync
// commands, which THIS app calls INTO the API). Protected by the
// same shared secret both systems already agree on -- see
// VerifyAdminSyncKey and AdminSyncKeyMiddleware on the API side.
Route::middleware(['auth.adminsync'])->group(function () {
    Route::get('/internal/dealer-lookup', [\App\Http\Controllers\Api\DealerLookupController::class, 'lookup']);
});