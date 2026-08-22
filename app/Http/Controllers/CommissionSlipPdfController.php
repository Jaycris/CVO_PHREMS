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

        $summaryCell = function (float $x, float $rightEdge, float $y, string $label, string $value, bool $shade = false) use (&$stream, $text, $right) {
            if ($shade) {
                $stream[] = '0.95 0.95 0.95 rg ' . ($x - 8) . ' ' . ($y - 7) . ' ' . ($rightEdge - $x + 8) . ' 19 re f 0 0 0 rg';
            }

            $text(9, $x, $y, $label);
            $right(9, $rightEdge - 8, $y, $value, 'F2');
        };

        $header = $this->headerImage();

        // Landscape: the statement has fifteen columns and will not fit upright.
        $stream[] = '1 1 1 rg 0 0 842 595 re f';
        if ($header) {
            $stream[] = 'q 842 0 0 95 0 500 cm /Im1 Do Q';
        }

        $stream[] = '0.00 0.32 0.18 rg';
        $text(24, 310, 467, 'COMMISSION SLIP', 'F2');
        $stream[] = '0 0 0 rg';
        $text(8, 350, 451, 'MONTHLY PERFORMANCE STATEMENT', 'F2');

        $y = 424;
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

        $y -= 38;
        $stream[] = '0.00 0.32 0.18 rg';
        $text(14, 48, $y, 'SUMMARY', 'F2');
        $stream[] = '0 0 0 rg';

        $leftSummary = [
            ['Service commission (USD)', $this->num($slip->service_commission, '$')],
            ['Markup commission (USD)', $this->num($slip->markup_commission, '$')],
            ['USD total', $this->num($slip->usd_total, '$')],
            ['Exchange rate', $slip->exchange_rate === null ? '-' : number_format((float) $slip->exchange_rate, 4)],
        ];
        $rightSummary = [
            ['PHP total', $this->num($slip->php_total, 'PHP ')],
            ['Card payment hold', $this->pct($slip->card_hold_percent)],
            ['Card payment hold amount', $this->num($slip->card_hold_amount, 'PHP ')],
            ['Target attainment', $this->pct($slip->mtd_percent)],
        ];

        $y -= 23;
        foreach ($leftSummary as $i => [$label, $value]) {
            $summaryCell(48, 410, $y, $label, $value, $i % 2 === 0);
            $summaryCell(438, 802, $y, $rightSummary[$i][0], $rightSummary[$i][1], $i % 2 === 0);
            $y -= 19;
        }

        $y -= 3;
        $stream[] = '0.90 0.96 0.93 rg 40 ' . ($y - 9) . ' 762 23 re f 0 0 0 rg';
        $text(11, 48, $y, 'NET COMMISSION', 'F2');
        $right(13, 794, $y, $this->num($slip->net_commission, 'PHP '), 'F2');
        $y -= 25;

        if ($slip->usd_total !== null && $slip->exchange_rate !== null) {
            $text(8, 48, $y, 'Currency conversion', 'F2');
            $right(8, 794, $y,
                $this->num($slip->usd_total, '$') . ' USD  x  '
                . number_format((float) $slip->exchange_rate, 4) . '  =  '
                . $this->num($slip->php_total, 'PHP '),
                'F2');
            $y -= 17;
        }

        $y -= 10;
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

        return $this->buildPdf(implode("\n", $stream), $header);
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

    protected function headerImage(): ?array
    {
        $path = public_path('images/CreativeVision-LOGO-v2-03.png');

        if (! function_exists('imagecreatefrompng') || ! is_file($path)) {
            return null;
        }

        $source = imagecreatefrompng($path);

        if (! $source) {
            return null;
        }

        $width = 1684;
        $height = 190;
        $canvas = imagecreatetruecolor($width, $height);

        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $width, $height, $white);

        $darkGreen = imagecolorallocate($canvas, 5, 76, 51);
        $deepGreen = imagecolorallocate($canvas, 3, 57, 41);
        $accentGreen = imagecolorallocate($canvas, 15, 112, 68);

        $mainCurve = $this->cubicCurve([610, 0], [790, 8], [875, 176], [1175, 148], 30);
        $mainCurve = array_merge($mainCurve, $this->cubicCurve([1175, 148], [1360, 130], [1535, 104], [1684, 126], 18, true));
        $mainShape = [610, 0, 1684, 0, 1684, 126];
        foreach (array_reverse($mainCurve) as [$x, $y]) {
            $mainShape[] = $x;
            $mainShape[] = $y;
        }
        imagefilledpolygon($canvas, $mainShape, $darkGreen);

        $bandTop = [];
        $bandBottom = [];
        $bandCurve = array_values(array_filter($mainCurve, fn (array $point) => $point[0] >= 850));
        foreach ($bandCurve as $index => [$x, $y]) {
            $thickness = (int) round(12 * min(1, $index / 10));
            $bandTop[] = [$x, min($height, $y)];
            $bandBottom[] = [$x, min($height, $y + $thickness)];
        }
        $bandShape = [];
        foreach ($bandTop as [$x, $y]) {
            $bandShape[] = $x;
            $bandShape[] = $y;
        }
        foreach (array_reverse($bandBottom) as [$x, $y]) {
            $bandShape[] = $x;
            $bandShape[] = $y;
        }
        imagefilledpolygon($canvas, $bandShape, $accentGreen);

        $lowerTop = $this->cubicCurve([0, 164], [165, 115], [390, 128], [555, 176], 32);
        $lowerBottom = [];
        $last = max(1, count($lowerTop) - 1);
        foreach ($lowerTop as $index => [$x, $y]) {
            $lowerBottom[] = [$x, min($height, $y + (int) round(22 * (1 - ($index / $last))) + 2)];
        }
        $lowerShape = [];
        foreach ($lowerTop as [$x, $y]) {
            $lowerShape[] = $x;
            $lowerShape[] = $y;
        }
        foreach (array_reverse($lowerBottom) as [$x, $y]) {
            $lowerShape[] = $x;
            $lowerShape[] = $y;
        }
        imagefilledpolygon($canvas, $lowerShape, $deepGreen);

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $logoWidth = 320;
        $logoHeight = (int) round($logoWidth * ($sourceHeight / $sourceWidth));
        imagealphablending($canvas, true);
        imagecopyresampled($canvas, $source, 58, 34, 0, 0, $logoWidth, $logoHeight, $sourceWidth, $sourceHeight);

        ob_start();
        imagejpeg($canvas, null, 90);
        $data = ob_get_clean();

        imagedestroy($source);
        imagedestroy($canvas);

        return $data ? compact('data', 'width', 'height') : null;
    }

    protected function cubicCurve(array $start, array $controlOne, array $controlTwo, array $end, int $steps, bool $skipFirst = false): array
    {
        $points = [];

        for ($step = $skipFirst ? 1 : 0; $step <= $steps; $step++) {
            $t = $step / $steps;
            $inverse = 1 - $t;
            $x = ($inverse ** 3 * $start[0]) + (3 * $inverse ** 2 * $t * $controlOne[0]) + (3 * $inverse * $t ** 2 * $controlTwo[0]) + ($t ** 3 * $end[0]);
            $y = ($inverse ** 3 * $start[1]) + (3 * $inverse ** 2 * $t * $controlOne[1]) + (3 * $inverse * $t ** 2 * $controlTwo[1]) + ($t ** 3 * $end[1]);

            $points[] = [(int) round($x), (int) round($y)];
        }

        return $points;
    }

    protected function buildPdf(string $stream, ?array $header = null): string
    {
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Resources << /Font << /F1 4 0 R /F2 5 0 R >>' . ($header ? ' /XObject << /Im1 7 0 R >>' : '') . ' >> /Contents 6 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
            '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream",
        ];

        if ($header) {
            $objects[] = '<< /Type /XObject /Subtype /Image /Width ' . $header['width'] . ' /Height ' . $header['height'] . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen($header['data']) . " >>\nstream\n" . $header['data'] . "\nendstream";
        }

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
