<?php

namespace App\Http\Controllers;

use App\Models\CashEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One month of the money record as a spreadsheet.
 *
 * In and out are separate columns rather than one signed figure, because this
 * is opened in Excel by a person, and a column of numbers where some are
 * negative is read wrongly at a glance far more often than two columns are.
 */
class CashLedgerExportController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        $month = $this->resolveMonth($request->query('month'));
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        $entries = CashEntry::with('category', 'recordedBy')
            ->between($start, $end)
            ->orderBy('entry_date')
            ->orderBy('id')
            ->get();

        $filename = 'money-in-out-' . $month->format('Y-m') . '.csv';

        return response()->streamDownload(function () use ($entries, $month) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['Date', 'Description', 'Category', 'Reference', 'Money In', 'Money Out', 'Note', 'Recorded by']);

            $in = 0.0;
            $out = 0.0;

            foreach ($entries as $entry) {
                $isIn = $entry->isIn();
                $amount = (float) $entry->amount;
                $isIn ? $in += $amount : $out += $amount;

                fputcsv($handle, [
                    $entry->entry_date->format('Y-m-d'),
                    $entry->description,
                    $entry->category?->name ?? 'Not categorised',
                    $entry->reference,
                    $isIn ? number_format($amount, 2, '.', '') : '',
                    $isIn ? '' : number_format($amount, 2, '.', ''),
                    $entry->note,
                    $entry->recordedBy?->name,
                ]);
            }

            // Totals on the sheet itself. Whoever opens this wants the answer,
            // not a column to sum.
            fputcsv($handle, []);
            fputcsv($handle, [
                $month->format('F Y') . ' total', '', '', '',
                number_format($in, 2, '.', ''),
                number_format($out, 2, '.', ''),
            ]);
            fputcsv($handle, ['Net', '', '', '', number_format($in - $out, 2, '.', '')]);

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    /** Falls back to this month rather than erroring on a mangled parameter. */
    protected function resolveMonth(?string $month): Carbon
    {
        if (is_string($month) && preg_match('/^\d{4}-\d{2}$/', $month)) {
            try {
                return Carbon::createFromFormat('Y-m', $month)->startOfMonth();
            } catch (\Throwable) {
                // Falls through to today.
            }
        }

        return Carbon::now()->startOfMonth();
    }
}
