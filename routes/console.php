<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The hosting cron invokes `php artisan schedule:run`; this keeps scheduled
// publication independent from WordPress/wp-cron.
Schedule::command('wp:publish-scheduled-posts')
    ->everyMinute()
    ->withoutOverlapping()
    ->when(static fn (): bool => (bool) config('services.wordpress.scheduling_enabled', false));
