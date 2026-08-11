<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('leave:run-monthly-accrual')->dailyAt('01:00');
Schedule::command('leave:run-annual-reset')->yearlyOn(1, 1, '01:30');

// Picks up payslips whose send was cut short — a mail outage mid-batch
// finishes itself rather than leaving half the company unnotified. Only
// unsent ones are touched, so this never mails anybody twice.
//
// Nothing here computes, finalises or pays. Money moves only when a human
// clicks.
Schedule::command('payroll:notify-payslips')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

// Shared hosting has no persistent queue worker, so the scheduler drains the
// queue instead. --stop-when-empty keeps each run short; withoutOverlapping
// stops a slow batch from stacking workers on top of each other.
Schedule::command('queue:work --stop-when-empty --max-time=55 --tries=3')
    ->everyMinute()
    ->withoutOverlapping();
