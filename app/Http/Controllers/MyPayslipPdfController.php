<?php

namespace App\Http\Controllers;

use App\Models\Payslip;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class MyPayslipPdfController
{
    public function __invoke(Payslip $payslip): Response
    {
        $employee = Auth::user()?->employee;

        abort_unless($employee && $payslip->employee_id === $employee->id, 403);
        abort_unless($payslip->notified_at !== null, 403);

        $payslip->load('payrollRun');

        $lines = $payslip->lines()->orderBy('sort_order')->get();
        $earnings = $lines->where('section', 'earning')->filter(fn ($line) => (float) $line->amount >= 0)->values();
        $deductions = $lines
            ->where('section', 'earning')
            ->filter(fn ($line) => (float) $line->amount < 0)
            ->map(function ($line) {
                $line = clone $line;
                $line->amount = abs((float) $line->amount);

                return $line;
            })
            ->concat($lines->where('section', 'deduction'))
            ->values();

        $employeeName = str($payslip->employeeName() ?: 'Employee')->replaceMatches('/[^A-Za-z0-9 ]+/', '')->squish();
        $filename = $employeeName . ' Payslip ' . $payslip->payrollRun->pay_date->format('Y-m-d') . '.pdf';

        return response($this->renderPdf($payslip, $earnings, $deductions), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    protected function renderPdf(Payslip $payslip, Collection $earnings, Collection $deductions): string
    {
        $stream = [];
        $text = function (int $size, float $x, float $y, string $value, string $font = 'F1') use (&$stream) {
            $stream[] = 'BT /' . $font . ' ' . $size . ' Tf ' . $x . ' ' . $y . ' Td (' . $this->pdfText($value) . ') Tj ET';
        };
        $rightText = function (int $size, float $right, float $y, string $value, string $font = 'F1') use ($text) {
            $text($size, $right - $this->approxTextWidth($value, $size), $y, $value, $font);
        };
        $row = function (float $y, string $label, ?string $amount = null, bool $shade = false, bool $bold = false) use (&$stream, $text, $rightText) {
            if ($shade) {
                $stream[] = '0.93 0.93 0.93 rg 68 ' . ($y - 7) . ' 476 20 re f 0 0 0 rg';
            }

            $text(10, 76, $y, $label, $bold ? 'F2' : 'F1');

            if ($amount !== null && $amount !== '') {
                $rightText(10, 536, $y, $amount, $bold ? 'F2' : 'F1');
            }
        };

        $header = $this->headerImage();
        $period = $payslip->payrollRun->period_start->format('F j') . ' - ' . $payslip->payrollRun->period_end->format('F j, Y');
        $payDate = $payslip->payrollRun->pay_date->format('F j, Y');
        $position = $payslip->employee_snapshot['position'] ?? '-';
        $reference = 'CV-PY-' . $payslip->payrollRun->pay_date->format('ymd') . '-' . str_pad((string) $payslip->id, 3, '0', STR_PAD_LEFT);

        $stream[] = '1 1 1 rg 0 0 612 792 re f';
        if ($header) {
            $stream[] = 'q 612 0 0 163 0 629 cm /Im1 Do Q';
        }

        $stream[] = '0 0 0 rg';
        $text(27, 181, 574, 'EMPLOYEE PAYSLIP', 'F2');
        $stream[] = '0 0 0 rg';

        $text(10, 68, 530, 'Pay Period', 'F2');
        $text(10, 168, 530, ':');
        $text(10, 190, 530, $period);
        $text(10, 336, 530, 'Pay Date', 'F2');
        $text(10, 438, 530, ':');
        $text(10, 462, 530, $payDate);
        $text(10, 68, 512, 'Employee Name', 'F2');
        $text(10, 168, 512, ':');
        $text(10, 190, 512, $payslip->employeeName());
        $text(10, 336, 512, 'Position', 'F2');
        $text(10, 438, 512, ':');
        $text(10, 462, 512, $position);
        $stream[] = '0.82 0.84 0.83 rg 68 490 476 1 re f';

        $stream[] = '0.00 0.32 0.18 rg';
        $text(18, 68, 458, 'EARNINGS', 'F2');
        $stream[] = '0 0 0 rg';

        $y = 426;
        $row($y, 'No. of Days', $payslip->days_present . ' / ' . $payslip->days_expected, true);
        $y -= 24;
        foreach ($earnings as $line) {
            $row($y, $line->label . ($line->detail ? ' - ' . $line->detail : ''), $this->amount((float) $line->amount), $y % 48 === 14);
            $y -= 24;
        }
        $row($y, 'Total Earnings', $this->amount((float) $earnings->sum('amount')), true, true);

        $y -= 54;
        $stream[] = '0.00 0.32 0.18 rg';
        $text(18, 68, $y, 'DEDUCTIONS', 'F2');
        $stream[] = '0 0 0 rg';
        $y -= 32;
        if ($deductions->isEmpty()) {
            $row($y, 'Nothing deducted.', '', true);
            $y -= 24;
        } else {
            foreach ($deductions as $line) {
                $row($y, $line->label . ($line->detail ? ' - ' . $line->detail : ''), $this->amount((float) $line->amount), $y % 48 === 14);
                $y -= 24;
            }
        }
        $row($y, 'Total Deduction', $this->amount((float) $deductions->sum('amount')), false, true);
        $y -= 34;
        $row($y, 'Net Pay', $this->amount((float) $payslip->net_pay), true, true);

        $text(10, 68, 125, 'Payment Method', 'F2');
        $text(10, 168, 125, ':');
        $text(10, 190, 125, 'Direct Deposit');
        $text(10, 336, 125, 'Payroll Reference No', 'F2');
        $text(10, 462, 125, ':');
        $text(10, 484, 125, $reference);

        $text(10, 68, 72, 'HR Note :', 'F2');
        $text(9, 68, 52, 'For questions or discrepancies regarding this payslip, please contact Human Resources.');

        return $this->buildPdf(implode("\n", $stream), $header);
    }

    protected function amount(float $amount): string
    {
        return number_format($amount, 2);
    }

    protected function money(float $amount): string
    {
        return 'PHP ' . number_format($amount, 2);
    }

    protected function approxTextWidth(string $value, int $size): float
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

        $width = 1200;
        $height = 320;
        $canvas = imagecreatetruecolor($width, $height);

        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $width, $height, $white);

        $darkGreen = imagecolorallocate($canvas, 5, 76, 51);
        $deepGreen = imagecolorallocate($canvas, 3, 57, 41);
        $accentGreen = imagecolorallocate($canvas, 15, 112, 68);

        $mainCurve = $this->cubicCurve([390, 0], [570, 32], [650, 232], [875, 208], 30);
        $mainCurve = array_merge($mainCurve, $this->cubicCurve([875, 208], [1010, 196], [1110, 155], [1200, 172], 18, true));
        $mainShape = [390, 0, 1200, 0, 1200, 172];
        foreach (array_reverse($mainCurve) as [$x, $y]) {
            $mainShape[] = $x;
            $mainShape[] = $y;
        }
        imagefilledpolygon($canvas, $mainShape, $darkGreen);

        $bandTop = [];
        $bandBottom = [];
        $bandCurve = array_values(array_filter($mainCurve, fn (array $point) => $point[0] >= 520));
        foreach ($bandCurve as $index => [$x, $y]) {
            $thickness = (int) round(16 * min(1, $index / 12));
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

        $lowerTop = $this->cubicCurve([0, 255], [150, 180], [345, 192], [515, 246], 32);
        $lowerBottom = [];
        $last = max(1, count($lowerTop) - 1);
        foreach ($lowerTop as $index => [$x, $y]) {
            $lowerBottom[] = [$x, $y + (int) round(32 * (1 - ($index / $last))) + 2];
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
        $logoWidth = 300;
        $logoHeight = (int) round($logoWidth * ($sourceHeight / $sourceWidth));
        imagealphablending($canvas, true);
        imagecopyresampled($canvas, $source, 62, 48, 0, 0, $logoWidth, $logoHeight, $sourceWidth, $sourceHeight);

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
            $x = ($inverse ** 3 * $start[0])
                + (3 * $inverse ** 2 * $t * $controlOne[0])
                + (3 * $inverse * $t ** 2 * $controlTwo[0])
                + ($t ** 3 * $end[0]);
            $y = ($inverse ** 3 * $start[1])
                + (3 * $inverse ** 2 * $t * $controlOne[1])
                + (3 * $inverse * $t ** 2 * $controlTwo[1])
                + ($t ** 3 * $end[1]);

            $points[] = [(int) round($x), (int) round($y)];
        }

        return $points;
    }

    protected function buildPdf(string $stream, ?array $logo = null): string
    {
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R /F2 5 0 R >>' . ($logo ? ' /XObject << /Im1 7 0 R >>' : '') . ' >> /Contents 6 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
            "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream",
        ];

        if ($logo) {
            $objects[] = '<< /Type /XObject /Subtype /Image /Width ' . $logo['width'] . ' /Height ' . $logo['height'] . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen($logo['data']) . " >>\nstream\n" . $logo['data'] . "\nendstream";
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
