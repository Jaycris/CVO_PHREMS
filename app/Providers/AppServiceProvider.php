<?php

namespace App\Providers;

use App\Models\Payslip;
use App\Models\PayslipAdjustment;
use App\Models\User;
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
    }
}
