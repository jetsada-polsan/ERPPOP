<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('erp:backup --keep-days=30')->dailyAt('02:15')->withoutOverlapping();
Schedule::command('erp:health')->hourly()->withoutOverlapping();
Schedule::command('erp:restore-drill')->monthlyOn(1, '04:30')->withoutOverlapping();
