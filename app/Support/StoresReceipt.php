<?php

namespace App\Support;

use App\Models\Employee;
use Illuminate\Http\UploadedFile;

/**
 * Receipt attachments for expense claims.
 *
 * Unlike profile photos these go on the private disk. A receipt carries an
 * employee's spending — where they were, what they bought — and a guessable
 * public URL would put that in the open. They are served through a controller
 * that checks who is asking.
 *
 * PDFs are allowed alongside images because most e-receipts arrive as one.
 */
trait StoresReceipt
{
    /** @return list<string> */
    protected function receiptRules(): array
    {
        // No SVG: it can carry script. 8MB covers a phone photo of a receipt
        // without letting an upload exhaust request memory.
        return ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'];
    }

    protected function storeReceipt(Employee $employee, ?UploadedFile $receipt): ?string
    {
        if (! $receipt) {
            return null;
        }

        return $receipt->store('receipts/' . $employee->id, 'local');
    }
}
