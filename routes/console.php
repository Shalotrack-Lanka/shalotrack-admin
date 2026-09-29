<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// customers:sync now also syncs vehicles internally — both tables stay
// current together. Running every 5 minutes keeps the admin portal and
// dealer portal within one cycle of live app data at all times.
Schedule::command('customers:sync')->everyFiveMinutes();

Schedule::command('devices:expire-subscriptions')->everyMinute();

// Pushes subscription status to the C# API so it can gate live tracking /
// tracking history for devices requiring renewal (see
// SyncSubscriptionStatusToApi for why this direction and this mechanism).
// Runs right after devices:expire-subscriptions each minute so a freshly
// lapsed subscription reaches the API within the same minute it lapses,
// not up to 5 minutes later.
Schedule::command('subscriptions:sync-to-api')->everyMinute();

// Feeds the admin dashboard's week-over-week trend indicators. Runs late in
// the day (23:55) so the snapshot reflects the day's activity, not its
// first hour. See App\Console\Commands\SnapshotDashboardMetrics.
Schedule::command('dashboard:snapshot')->dailyAt('23:55');