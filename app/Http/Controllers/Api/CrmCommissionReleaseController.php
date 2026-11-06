<?php

namespace App\Http\Controllers\Api;

use App\Models\CommissionSlip;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class CrmCommissionReleaseController
{
    public function __invoke(Request $request)
    {
        $data = $request->validate([
            'hris_employee_id' => ['required', 'string', 'max:50'], 'month' => ['required', 'date_format:Y-m'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.key' => ['required', 'string', 'distinct', 'max:100'],
            'items.*.crm_payment_id' => ['required', 'integer', 'min:1'],
            'items.*.crm_agent_id' => ['required', 'integer', 'min:1'],
            'items.*.sold_date' => ['required', 'date_format:Y-m-d'],
            'items.*.brand' => ['required', 'string', 'max:255'],
            'items.*.author' => ['required', 'string', 'max:255'],
            'items.*.book_title' => ['required', 'string', 'max:2000'],
            'items.*.service' => ['required', 'string', 'max:255'],
            'items.*.payment_method' => ['nullable', 'string', 'max:100'],
            'items.*.sale_amount' => ['required', 'numeric', 'min:0'],
        ]);
        $month = Carbon::createFromFormat('!Y-m', $data['month']);
        foreach ($data['items'] as $item) {
            abort_unless(substr($item['sold_date'], 0, 7) === $data['month'], 422, 'Every sale must belong to the requested month.');
        }
        $employee = Employee::where('employee_id', $data['hris_employee_id'])->firstOrFail();
        $slips = CommissionSlip::with(['commissionRun', 'lines'])
            ->where('employee_id', $employee->id)->whereNotNull('notified_at')->whereNull('fetch_error')
            ->where(function ($query) use ($month, $data) {
                $query->whereHas('commissionRun', fn ($query) => $query
                    ->whereDate('period_start', '<=', $month->copy()->endOfMonth()->toDateString())
                    ->whereDate('period_end', '>=', $month->toDateString()))
                    ->orWhereHas('lines', fn ($query) => $query
                        ->whereDate('sold_date', '>=', $month->toDateString())
                        ->whereDate('sold_date', '<=', $month->copy()->endOfMonth()->toDateString()));
                $query->orWhereHas('lines', fn ($query) => $query->whereIn('crm_payment_id', array_column($data['items'], 'crm_payment_id')));
            })->get();
        $candidates = collect();
        foreach ($slips as $slip) {
            foreach ($slip->lines as $line) $candidates->push(['slip' => $slip, 'line' => $line]);
        }
        $results = [];
        $usedLines = [];
        foreach ($data['items'] as $item) {
            $direct = $candidates->filter(fn ($candidate) => (int) $candidate['line']->crm_payment_id === (int) $item['crm_payment_id']
                && (int) $candidate['line']->crm_agent_id === (int) $item['crm_agent_id']);
            $matches = $direct->isNotEmpty() ? $direct : $candidates->filter(function ($candidate) use ($item) {
                $line = $candidate['line'];
                if ($line->crm_payment_id !== null) return false;
                foreach (['brand' => 'brand', 'author' => 'client', 'book_title' => 'book_title', 'service' => 'service', 'payment_method' => 'payment_method'] as $field => $column) {
                    if ($this->normalize($item[$field] ?? '') !== $this->normalize($line->$column ?? '')) return false;
                }
                return ($line->sold_date ? Carbon::parse($line->sold_date)->toDateString() : null) === $item['sold_date']
                    && $line->sale_amount !== null && (int) round((float) $line->sale_amount * 100) === (int) round((float) $item['sale_amount'] * 100);
            });
            $result = ['key' => $item['key'], 'status' => 'unreleased'];
            if ($matches->count() > 1) {
                $result['status'] = 'unknown';
                $result['reason'] = 'More than one sent slip line matches this payment.';
            } elseif ($matches->count() === 1) {
                $match = $matches->first(); $line = $match['line']; $slip = $match['slip'];
                $fields = ['usd_total', 'service_commission', 'markup_commission', 'php_total', 'card_hold_amount', 'net_commission'];
                if (collect($fields)->contains(fn ($field) => $line->$field === null) || $slip->exchange_rate === null) {
                    $result['status'] = 'unknown';
                    $result['reason'] = 'The sent slip line has an incomplete commission breakdown.';
                } else {
                    $result += ['reference' => 'slip:'.$slip->id.'/line:'.$line->id, 'released_at' => $slip->notified_at->toIso8601String(),
                        'breakdown' => ['usd_total' => (float) $line->usd_total, 'service_commission' => (float) $line->service_commission,
                            'markup_commission' => (float) $line->markup_commission, 'php_total' => (float) $line->php_total,
                            'hold_amount' => (float) $line->card_hold_amount, 'net_commission' => (float) $line->net_commission,
                            'exchange_rate' => (float) $slip->exchange_rate, 'card_payment_hold_percent' => (float) $slip->card_hold_percent]];
                    $result['status'] = 'released';
                    $usedLines[$line->id][] = $item['key'];
                }
            } elseif ($slips->contains(function ($slip) use ($item) {
                $covers = $slip->commissionRun && $slip->commissionRun->period_start->toDateString() <= $item['sold_date']
                    && $slip->commissionRun->period_end->toDateString() >= $item['sold_date'];
                return $covers && (! $slip->statement_supplied || $slip->lines->contains(fn ($line) => $line->crm_payment_id === null));
            })) {
                $result['status'] = 'unknown';
                $result['reason'] = 'A legacy sent slip covers this date but the payment cannot be identified uniquely.';
            }
            $results[$item['key']] = $result;
        }
        foreach ($usedLines as $keys) {
            if (count($keys) > 1) foreach ($keys as $key) {
                $results[$key] = ['key' => $key, 'status' => 'unknown', 'reason' => 'Multiple CRM payments match the same legacy sent line.'];
            }
        }
        return response()->json(['complete' => true, 'hris_employee_id' => $employee->employee_id,
            'month' => $data['month'], 'release_basis' => 'sent', 'data' => array_values($results)])
            ->header('Cache-Control', 'private, no-store');
    }

    private function normalize(string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text)));
    }
}
