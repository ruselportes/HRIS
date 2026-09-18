<?php

use App\Services\ActingForemanService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Phase 7 (UC-06): acting foreman covers end on their own. Requests also end
// expired covers before reading leadership, so this only keeps the audit
// trail close to the moment; it is not what the expiry depends on.
Artisan::command('crews:end-expired-covers', function (ActingForemanService $acting) {
    $this->info($acting->endExpired().' expired acting foreman cover(s) ended.');
})->purpose('Return crews to their regular foreman when an acting cover has run out');

Schedule::command('crews:end-expired-covers')->everyFiveMinutes();
