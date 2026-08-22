<?php

namespace App\Http\Controllers;

use App\Models\CommissionSlip;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * The commission slip as a PDF, built the same hand-rolled way as the payslip.
 *
 * Printed from the stored slip, never from a live CRM call. A PDF downloaded in
 * October must show what was sent in August, whatever the CRM says today.
 */
class CommissionSlipPdfController
{
    public function __invoke(Request $request, CommissionSlip $slip): Response
    {
        $viewer = Auth::user();

        // HR may print anyone's; an agent may print their own, and only once it
        // has been sent to them.
        if (! $viewer?->can('commissions.view_all')) {
            $employee = $viewer?->employee;

            abort_unless($employee && $slip->employee_id === $employee->id, 403, 'That commission slip is not yours.');
            abort_unless($slip->notified_at !== null, 403, 'That commission slip has not been sent yet.');
        }

        $slip->load(['lines', 'commissionRun', 'employee']);

        $name = str($slip->employeeName())->replaceMatches('/[^A-Za-z0-9 ]+/', '')->squish();
        $filename = $name . ' Commission Slip ' . $slip->commissionRun->month() . '.pdf';

        return response($this->render($slip), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    protected function render(CommissionSlip $slip): string
    {
        $stream = [];

        $text = function (int $size, float $x, float $y, string $value, string $font = 'F1') use (&$stream) {
            $stream[] = 'BT /' . $font . ' ' . $size . ' Tf ' . $x . ' ' . $y . ' Td (' . $this->pdfText($value) . ') Tj ET';
        };
        $right = function (int $size, float $edge, float $y, string $value, string $font = 'F1') use ($text) {
            $text($size, $edge - $this->width($value, $size), $y, $value, $font);
        };
        $row = function (float $y, string $label, string $value, bool $shade = false, bool $bold = false) use (&$stream, $text, $right) {
            if ($shade) {
                $stream[] = '0.93 0.93 0.93 rg 40 ' . ($y - 7) . ' 762 20 re f 0 0 0 rg';
            }
            $text(10, 48, $y, $label, $bold ? 'F2' : 'F1');
            $right(10, 794, $y, $value, $bold ? 'F2' : 'F1');
        };

        // Landscape: the statement has fifteen columns and will not fit upright.
        $stream[] = '1 1 1 rg 0 0 842 595 re f';
        $stream[] = '0.00 0.32 0.18 rg 0 540 842 55 re f';
        $stream[] = '1 1 1 rg';
        $text(20, 48, 558, 'COMMISSION SLIP', 'F2');
        $stream[] = '0 0 0 rg';

        $y = 512;
        $text(10, 48, $y, 'Agent', 'F2');
        $text(10, 140, $y, ': ' . $slip->employeeName());
        $text(10, 430, $y, 'Month', 'F2');
        $text(10, 520, $y, ': ' . $slip->monthLabel());

        $y -= 18;
        $text(10, 48, $y, 'Employee ID', 'F2');
        $text(10, 140, $y, ': ' . ($slip->employeeCode() ?: '-'));
        $text(10, 430, $y, 'Team / Work Type', 'F2');
        $text(10, 545, $y, ': ' . $slip->teamLabel());

        $y -= 18;
        $text(10, 48, $y, 'MTD / Target', 'F2');
        $text(10, 140, $y, ': ' . $this->num($slip->mtd, '$') . ' / ' . $this->num($slip->target, '$')
            . '  (' . $this->pct($slip->mtd_percent) . ')');
        $text(10, 430, $y, 'Issued', 'F2');
        $text(10, 545, $y, ': ' . ($slip->notified_at?->format('F j, Y') ?? 'not yet sent'));

        $stream[] = '0.82 0.84 0.83 rg 40 ' . ($y - 14) . ' 762 1 re f 0 0 0 rg';

        $y -= 40;
        $stream[] = '0.00 0.32 0.18 rg';
        $text(14, 48, $y, 'SUMMARY', 'F2');
        $stream[] = '0 0 0 rg';

        $y -= 24;
        foreach ([
            ['Service commission', $this->num($slip->service_commission, '$')],
            ['Markup commission', $this->num($slip->markup_commission, '$')],
            ['USD total', $this->num($slip->usd_total, '$')],
            ['Exchange rate', $slip->exchange_rate === null ? '-' : number_format((float) $slip->exchange_rate, 4)],
            ['PHP total', $this->num($slip->php_total, 'PHP ')],
            ['Card payment hold', $this->pct($slip->card_hold_percent)],
            ['Card payment hold amount', $this->num($slip->card_hold_amount, 'PHP ')],
        ] as $i => [$label, $value]) {
            $row($y, $label, $value, $i % 2 === 0);
            $y -= 19;
        }

        $y -= 6;
        $row($y, 'NET COMMISSION', $this->num($slip->net_commission, 'PHP '), true, true);

        $y -= 40;
        $stream[] = '0.00 0.32 0.18 rg';
        $text(14, 48, $y, 'TRANSACTION STATEMENT', 'F2');
        $stream[] = '0 0 0 rg';
        $y -= 20;

        if (! $slip->statement_supplied) {
            $text(9, 48, $y, 'The CRM did not send per-sale rows for this month.');
        } elseif ($slip->lines->isEmpty()) {
            $text(9, 48, $y, 'No commission records in ' . $slip->monthLabel() . '.');
        } else {
            $columns = [48, 116, 190, 300, 396, 462, 520, 578, 636, 694, 752];
            $headings = ['Sold', 'Brand', 'Author/Client', 'Book Title', 'Service', 'Payment', 'Sale', 'Svc Comm', 'Mkp Comm', 'Card Hold', 'Net'];

            foreach ($headings as $i => $heading) {
                $text(8, $columns[$i], $y, $heading, 'F2');
            }
            $stream[] = '0.82 0.84 0.83 rg 40 ' . ($y - 6) . ' 762 1 re f 0 0 0 rg';
            $y -= 15;

            foreach ($slip->lines as $index => $line) {
                if ($y < 46) {
                    $text(8, 48, $y, '... ' . ($slip->lines->count() - $index) . ' more row(s) - see the app for the full statement.');
                    break;
                }

                if ($index % 2 === 0) {
                    $stream[] = '0.95 0.95 0.95 rg 40 ' . ($y - 4) . ' 762 14 re f 0 0 0 rg';
                }

                foreach ([
                    $this->clip($line->sold_date, 10),
                    $this->clip($line->brand, 11),
                    $this->clip($line->client, 17),
                    $this->clip($line->book_title, 15),
                    $this->clip($line->service, 10),
                    $this->clip($line->payment_method, 9),
                    $this->num($line->sale_amount, '$'),
                    $this->num($line->service_commission, '$'),
                    $this->num($line->markup_commission, '$'),
                    $this->num($line->card_hold_amount, ''),
                    $this->num($line->net_commission, ''),
                ] as $i => $cell) {
                    $text(8, $columns[$i], $y, $cell);
                }

                $y -= 14;
            }
        }

        $text(8, 48, 28, 'Figures supplied by the CRM and locked when this run was computed. Queries about any amount go to your team lead.');

        return $this->buildPdf(implode("\n", $stream));
    }

    protected function num($value, string $prefix): string
    {
        return $value === null ? '-' : $prefix . number_format((float) $value, 2);
    }

    protected function pct($value): string
    {
        return $value === null ? '-' : number_format((float) $value, 2) . '%';
    }

    protected function clip(?string $value, int $length): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '-';
        }

        return strlen($value) > $length ? substr($value, 0, $length - 1) . '.' : $value;
    }

    protected function width(string $value, int $size): float
    {
        return strlen($value) * $size * 0.48;
    }

    protected function pdfText(string $value): string
    {
        $value = str_replace(["\r", "\n", "\t"], ' ', $value);
        $value = preg_replace('/[^\x20-\x7E]/', '-', $value) ?? $value;

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
    }

    protected function buildPdf(string $stream): string
    {
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
            '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [0];

        foreach ($objects as $index => $object) {
            $offsets[$index + 1] = strlen($pdf);
            $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";

        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= str_pad((string) $offsets[$i], 10, '0', STR_PAD_LEFT) . " 00000 n \n";
        }

        return $pdf . "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
    }
}
