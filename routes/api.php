<?php

use App\Http\Controllers\Api\CrmEmployeeLookupController;
use App\Http\Middleware\AuthenticateCrmRequest;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| CRM lookup
|--------------------------------------------------------------------------
|
| The CRM calls in here when an admin uses the HRIS Employee search on its
| Create User form. Read-only, token-guarded, and limited to the fields in
| CrmSafeEmployee — nothing about pay, government IDs or home life leaves this
| app.
|
| Rate limited because it is the one door in this application that answers to a
| shared secret rather than a person, and a leaked token should be a nuisance
| rather than a bulk export of the staff directory.
|
*/

Route::middleware([AuthenticateCrmRequest::class, 'throttle:60,1'])
    ->prefix('api/crm')
    ->group(function () {
        Route::get('/health', [CrmEmployeeLookupController::class, 'health'])->name('api.crm.health');
        Route::get('/employees', [CrmEmployeeLookupController::class, 'search'])->name('api.crm.employees.search');
        Route::get('/employees/{employeeId}', [CrmEmployeeLookupController::class, 'show'])->name('api.crm.employees.show');
    });
