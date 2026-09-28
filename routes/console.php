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

// Feeds the admin dashboard's week-over-week trend indicators. Runs late in
// the day (23:55) so the snapshot reflects the day's activity, not its
// first hour. See App\Console\Commands\SnapshotDashboardMetrics.
Schedule::command('dashboard:snapshot')->dailyAt('23:55');