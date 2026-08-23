<?php

namespace App\Services\Concerns;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;

/**
 * Two helpers for the same mistake, made in five services.
 *
 * Checking "is this already filed?" or "is this still pending?" and *then*
 * writing is two steps, and anything can happen between them once more than
 * one PHP process is running. Both callers pass the check, both write, and the
 * result is a duplicate request or a decision applied twice.
 *
 * `php artisan serve` handles one request at a time, so none of this can be
 * reproduced locally — it only starts happening on real hosting, which is
 * exactly when it is most expensive to discover.
 *
 * The rule these two enforce: take the lock first, re-read the state you are
 * about to act on, and do the check and the write inside one transaction.
 */
trait SerialisesConcurrentWrites
{
    /**
     * Re-reads a row with it locked for the rest of the transaction.
     *
     * The model handed into a service method was loaded before the button was
     * clicked, so its status is a memory of how things were, not how they are.
     * This is how you find out what is actually true.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @return TModel
     */
    protected function lockRow(string $model, int $id): Model
    {
        return $model::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Holds one employee's row for the rest of the transaction.
     *
     * Used where the thing being protected is not a single row but a question
     * asked across several — "do they already have one of these pending?",
     * "have they enough leave credits?". Locking the employee serialises that
     * employee's own submissions and nobody else's, so two people filing at
     * the same time never wait on each other.
     */
    protected function lockEmployee(Employee|int $employee): void
    {
        Employee::query()
            ->whereKey($employee instanceof Employee ? $employee->id : $employee)
            ->lockForUpdate()
            ->first();
    }
}
