<?php

use App\Layout\LayoutSnapshotStore;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('layout-snapshots:prune', function (LayoutSnapshotStore $snapshots): void {
    $this->info($snapshots->pruneExpired().' expired Layout Snapshot(s) pruned.');
})->purpose('Prune encrypted Layout Snapshots after their configured TTL');

Schedule::command('layout-snapshots:prune')->hourly()->withoutOverlapping();
