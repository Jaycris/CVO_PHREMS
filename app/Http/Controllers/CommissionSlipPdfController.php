<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Services\Crm\CommissionSlip;
use App\Services\Crm\CommissionSlipService;
use App\Services\Crm\CrmUnavailable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * The commission slip as a PDF, built the same hand-rolled way as the payslip.
 *
 * No PDF library on purpose — MyPayslipPdfController already proves the
 * approach and shared hosting does not want dompdf's memory footprint.
 */
class CommissionSlipPdfController
{
    public function __invoke(Request $request, CommissionSlipService $service): Response
    {
        $viewer = Auth::user();
        $employee = $viewer?->employee;

        // HR may download anyone's; everyone else may download only their own.
        if ($request->filled('employee') && $viewer?->can('commissions.view_all')) {
            $employee = Employee::findOrFail((int) $request->integer('employee'));
        }

        abort_unless($employee, 403, 'No employee profile is linked to your account.');

        $month = (string) $request->query('month', now('Asia/Manila')->format('Y-m'));

        try {
            $slip = $service->forEmployee($employee, $month);
        } catch (CrmUnavailable $e) {
            abort(503, $e->getMessage());
        }

        $name = str($slip->agentName ?: 'Agent')->replaceMatches('/[^A-Za-z0-9 ]+/', '')->squish();
        $filename = $name . ' Commission Slip ' . $slip->month . '.pdf';

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
        $text(10, 140, $y, ': ' . ($slip->agentName ?: '-'));
        $text(10, 430, $y, 'Month', 'F2');
        $text(10, 520, $y, ': ' . $slip->monthLabel());

        $y -= 18;
        $text(10, 48, $y, 'Team / Work Type', 'F2');
        $text(10, 140, $y, ': ' . (trim(implode(' / ', array_filter([$slip->team, $slip->workType]))) ?: '-'));
        $text(10, 430, $y, 'MTD / Target', 'F2');
        $text(10, 520, $y, ': ' . $this->num($slip->mtd, '$') . ' / ' . $this->num($slip->target, '$')
            . '  (' . $this->pct($slip->mtdPercent) . ')');

        $stream[] = '0.82 0.84 0.83 rg 40 ' . ($y - 14) . ' 762 1 re f 0 0 0 rg';

        $y -= 42;
        $stream[] = '0.00 0.32 0.18 rg';
        $text(14, 48, $y, 'SUMMARY', 'F2');
        $stream[] = '0 0 0 rg';

        $y -= 26;
        foreach ([
            ['Service commission', $this->num($slip->serviceCommission, '$')],
            ['Markup commission', $this->num($slip->markupCommission, '$')],
            ['USD total', $this->num($slip->usdTotal, '$')],
            ['Exchange rate', $slip->exchangeRate === null ? '-' : number_format($slip->exchangeRate, 4)],
            ['PHP total', $this->num($slip->phpTotal, 'PHP ')],
            ['Card payment hold', $this->pct($slip->cardHoldPercent)],
            ['Card payment hold amount', $this->num($slip->cardHoldAmount, 'PHP ')],
        ] as $i => [$label, $value]) {
            $row($y, $label, $value, $i % 2 === 0);
            $y -= 20;
        }

        $y -= 6;
        $row($y, 'NET COMMISSION', $this->num($slip->netCommission, 'PHP '), true, true);

        $y -= 44;
        $stream[] = '0.00 0.32 0.18 rg';
        $text(14, 48, $y, 'TRANSACTION STATEMENT', 'F2');
        $stream[] = '0 0 0 rg';
        $y -= 22;

        if (! $slip->statementSupplied) {
            $text(9, 48, $y, 'The CRM did not send per-sale rows for this month.');
        } elseif ($slip->transactions->isEmpty()) {
            $text(9, 48, $y, 'No commission records in ' . $slip->monthLabel() . '.');
        } else {
            $columns = [48, 116, 190, 300, 396, 462, 520, 578, 636, 694, 752];
            $headings = ['Sold', 'Brand', 'Author/Client', 'Book Title', 'Service', 'Payment', 'Sale', 'Svc Comm', 'Mkp Comm', 'Card Hold', 'Net'];

            foreach ($headings as $i => $heading) {
                $text(8, $columns[$i], $y, $heading, 'F2');
            }
            $stream[] = '0.82 0.84 0.83 rg 40 ' . ($y - 6) . ' 762 1 re f 0 0 0 rg';
            $y -= 16;

            foreach ($slip->transactions as $index => $t) {
                if ($y < 48) {
                    $text(8, 48, $y, '... ' . ($slip->transactions->count() - $index) . ' more row(s) - see the app for the full statement.');
                    break;
                }

                if ($index % 2 === 0) {
                    $stream[] = '0.95 0.95 0.95 rg 40 ' . ($y - 4) . ' 762 15 re f 0 0 0 rg';
                }

                foreach ([
                    $this->clip($t->soldDate, 10),
                    $this->clip($t->brand, 11),
                    $this->clip($t->client, 17),
                    $this->clip($t->bookTitle, 15),
                    $this->clip($t->service, 10),
                    $this->clip($t->paymentMethod, 9),
                    $this->num($t->saleAmount, '$'),
                    $this->num($t->serviceCommission, '$'),
                    $this->num($t->markupCommission, '$'),
                    $this->num($t->cardHoldAmount, ''),
                    $this->num($t->netCommission, ''),
                ] as $i => $cell) {
                    $text(8, $columns[$i], $y, $cell);
                }

                $y -= 15;
            }
        }

        $text(8, 48, 28, 'Figures supplied by the CRM. Queries about any amount go to your team lead.');

        return $this->buildPdf(implode("\n", $stream));
    }

    protected function num(?float $value, string $prefix): string
    {
        return $value === null ? '-' : $prefix . number_format($value, 2);
    }

    protected function pct(?float $value): string
    {
        return $value === null ? '-' : number_format($value, 2) . '%';
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
