<?php

namespace App\Providers;

use App\Models\AttendanceBreak;
use App\Models\AttendanceDay;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\PayslipAdjustment;
use App\Models\User;
use App\Observers\AttendanceLockObserver;
use App\Observers\PayslipAdjustmentObserver;
use App\Observers\PayslipObserver;
use App\Services\Sms\LogDriver;
use App\Services\Sms\SemaphoreDriver;
use App\Services\Sms\SmsDriver;
use App\Services\Sms\TwilioDriver;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         * Which SMS provider is in use, decided once here.
         *
         * Anything unrecognised — including the default — resolves to the log
         * driver, which writes the message and sends nothing. Failing closed
         * matters more than failing loudly: a typo in SMS_DRIVER should mean no
         * texts, never texts to the whole company through a provider nobody
         * meant to use.
         */
        $this->app->singleton(SmsDriver::class, function () {
            $sms = config('services.sms');

            return match ($sms['driver'] ?? 'log') {
                'semaphore' => new SemaphoreDriver(
                    $sms['semaphore']['key'] ?? null,
                    $sms['semaphore']['sender_name'] ?? null,
                    $sms['semaphore']['endpoint'] ?? 'https://api.semaphore.co/api/v4/messages',
                    (int) ($sms['timeout'] ?? 15),
                ),
                'twilio' => new TwilioDriver(
                    $sms['twilio']['sid'] ?? null,
                    $sms['twilio']['token'] ?? null,
                    $sms['twilio']['from'] ?? null,
                    (int) ($sms['timeout'] ?? 15),
                ),
                default => new LogDriver,
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * Routes, menus and pages all gate on can(), so this single hook is
         * what makes position-derived and individually granted permissions
         * work everywhere at once.
         *
         * Returning null rather than false when the permission is absent lets
         * any policy or gate defined elsewhere still have its say.
         */
        Gate::before(function (User $user, string $ability) {
            return $user->hasEffectivePermission($ability) ?: null;
        });

        // Refuses any write to a finalised or paid payslip, whatever code path
        // it arrives through.
        Payslip::observe(PayslipObserver::class);
        PayslipAdjustment::observe(PayslipAdjustmentObserver::class);

        // Same for the attendance underneath a settled payslip — editing it
        // afterwards makes the record and the payslip disagree with nothing to
        // say which is right.
        AttendanceDay::observe(AttendanceLockObserver::class);

        AttendanceBreak::saving(fn (AttendanceBreak $b) => (new AttendanceLockObserver)->savingBreak($b));
        AttendanceBreak::deleting(fn (AttendanceBreak $b) => (new AttendanceLockObserver)->deletingBreak($b));

        // A run changing status changes what is locked, so the observer's
        // per-request memo has to be dropped.
        PayrollRun::saved(fn () => AttendanceLockObserver::flush());
        PayrollRun::deleted(fn () => AttendanceLockObserver::flush());
    }
}
