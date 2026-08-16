<?php

namespace App\Livewire\Concerns;

use App\Models\AppSetting;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Livewire\WithPagination;

/**
 * Pagination for the app's tables, sized by one company-wide setting.
 *
 * The size is read on every render rather than copied into a property, so
 * changing it on the Settings screen takes effect for everyone the next time
 * they load a page — not only for people who had not opened it yet.
 */
trait WithTablePagination
{
    use WithPagination;

    public function perPage(): int
    {
        return AppSetting::rowsPerPage();
    }

    /**
     * Pages an already-built collection.
     *
     * Reports assemble their rows in PHP — the attendance summary walks every
     * employee and totals their days — so there is no query left to call
     * paginate() on by the time the rows exist.
     */
    protected function paginateCollection(Collection $rows, string $pageName = 'page'): LengthAwarePaginator
    {
        $perPage = $this->perPage();
        $page = (int) Paginator::resolveCurrentPage($pageName) ?: 1;

        return new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['pageName' => $pageName]
        );
    }
}
