<?php

use App\Jobs\UpdateYtDlpJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('youtube:maintenance --scheduled')
    ->everyThirtyMinutes()
    ->withoutOverlapping();

Schedule::job(new UpdateYtDlpJob('weekly-schedule'))
    ->weeklyOn(UpdateYtDlpJob::weeklyUpdateDay(), UpdateYtDlpJob::weeklyUpdateTime())
    ->withoutOverlapping()
    ->when([UpdateYtDlpJob::class, 'isAutoUpdateEnabled']);
