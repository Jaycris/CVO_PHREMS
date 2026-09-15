<?php

namespace App\Http\Controllers;

use App\Models\AgentPayment;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * The PDF of an agent's pay slip.
 *
 * Extends the payroll payslip controller for its drawing helpers — the header
 * artwork, the text and PDF assembly — so the two documents look like they
 * came from the same company. The layout differs because the content does: no
 * attendance, no government deductions, one payment made outside payroll.
 */
class AgentPaySlipPdfController extends MyPayslipPdfController
{
    public function download(AgentPayment $agentPayment): Response
    {
        $employee = Auth::user()?->employee;

        // Yours, or nobody's. There is no HR view of this download — the
        // CEO/COO screen already shows every figure on it.
        abort_unless($employee && $agentPayment->employee_id === $employee->id, 403);

        $agentPayment->load('employee.position');

        $name = str($agentPayment->employee->fullName() ?: 'Employee')->replaceMatches('/[^A-Za-z0-9 ]+/', '')->squish();
        $filename = $name . ' Pay Slip ' . $agentPayment->month . '.pdf';

        return response($this->renderAgentSlip($agentPayment), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    protected function renderAgentSlip(AgentPayment $payment): string
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

        $employee = $payment->employee;
        $header = $this->headerImage();

        $stream[] = '1 1 1 rg 0 0 612 792 re f';
        if ($header) {
            $stream[] = 'q 612 0 0 163 0 629 cm /Im1 Do Q';
        }

        $stream[] = '0 0 0 rg';
        $text(27, 232, 574, 'PAY SLIP', 'F2');

        $text(10, 68, 530, 'Pay For', 'F2');
        $text(10, 168, 530, ':');
        $text(10, 190, 530, $payment->monthLabel());
        $text(10, 336, 530, 'Paid On', 'F2');
        $text(10, 438, 530, ':');
        $text(10, 462, 530, $payment->paid_on->format('F j, Y'));

        $text(10, 68, 512, 'Employee Name', 'F2');
        $text(10, 168, 512, ':');
        $text(10, 190, 512, $employee->fullName() ?: $employee->employee_id);
        $text(10, 336, 512, 'Employee ID', 'F2');
        $text(10, 438, 512, ':');
        $text(10, 462, 512, (string) $employee->employee_id);

        $text(10, 68, 494, 'Position', 'F2');
        $text(10, 168, 494, ':');
        $text(10, 190, 494, $employee->position?->title ?? '-');

        $stream[] = '0.82 0.84 0.83 rg 68 474 476 1 re f';

        $stream[] = '0.00 0.32 0.18 rg';
        $text(18, 68, 442, 'PAYMENT', 'F2');
        $stream[] = '0 0 0 rg';

        $y = 410;
        $row($y, $payment->description, $this->amount((float) $payment->amount), true);
        $y -= 24;

        if ($payment->mtd_usd !== null) {
            $row($y, 'Month-to-date sales (for reference)', 'USD ' . number_format((float) $payment->mtd_usd, 2));
            $y -= 24;
        }

        $y -= 10;
        $row($y, 'Net Pay', 'PHP ' . $this->amount((float) $payment->amount), true, true);

        $text(10, 68, 150, 'Payment Reference', 'F2');
        $text(10, 168, 150, ':');
        $text(10, 190, 150, $payment->reference ?: '-');
        $text(10, 336, 150, 'Slip Reference No', 'F2');
        $text(10, 438, 150, ':');
        $text(10, 462, 150, $payment->referenceCode());

        $text(10, 68, 72, 'Note :', 'F2');
        $text(9, 68, 52, 'Paid outside the regular payroll run. For questions about this slip, please contact Human Resources.');

        return $this->buildPdf(implode("\n", $stream), $header);
    }
}
