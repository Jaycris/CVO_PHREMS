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
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
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
