<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled jobs. On Hostinger shared hosting add ONE cron entry (every minute):
|   * * * * * cd /home/USER/domains/YOURSITE/laravel && php artisan schedule:run >> /dev/null 2>&1
| No queue worker, Redis or WebSocket server is needed.
|--------------------------------------------------------------------------
*/
Schedule::command('subscriptions:sync')->dailyAt('00:30')->withoutOverlapping();
Schedule::command('library:send-reminders')->dailyAt('07:00')->withoutOverlapping();
Schedule::command('db:backup --keep=14')->dailyAt('02:00')->withoutOverlapping();
