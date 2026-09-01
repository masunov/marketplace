<?php

use App\Console\Commands\RecoverOrdersCommand;
use App\Console\Commands\RecoverPaymentCallbacksCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(RecoverPaymentCallbacksCommand::class)
        ->everyMinute()
        ->withoutOverlapping();

Schedule::command(RecoverOrdersCommand::class)
        ->everyFiveMinutes()
        ->withoutOverlapping();
