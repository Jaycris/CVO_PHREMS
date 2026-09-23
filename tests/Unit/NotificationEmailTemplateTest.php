<?php

namespace Tests\Unit;

use App\Models\BankDetailRequest;
use App\Models\Employee;
use App\Models\ReimbursementRequest;
use App\Models\User;
use App\Notifications\BankDetailChangeNotice;
use App\Notifications\ReimbursementActionNeeded;
use Tests\TestCase;

class NotificationEmailTemplateTest extends TestCase
{
    public function test_reimbursement_review_uses_the_phrems_email_template(): void
    {
        $employee = new Employee([
            'employee_id' => 'EMP-1001',
            'first_name' => 'Ric Steven',
            'last_name' => 'Moreno',
        ]);
        $claim = new ReimbursementRequest([
            'amount_requested' => 2042,
            'expense_date' => '2026-09-13',
            'category' => 'supplies',
            'description' => 'MIBF 2026 expenses',
        ]);
        $claim->setRelation('employee', $employee);

        $mail = (new ReimbursementActionNeeded($claim))->toMail(new User(['name' => 'Jay']));
        $html = view($mail->view, $mail->viewData)->render();

        $this->assertSame('emails.reimbursement-action-needed', $mail->view);
        $this->assertStringContainsString('Reimbursement needs review', $html);
        $this->assertStringContainsString('PHP 2,042.00', $html);
        $this->assertStringContainsString('Review Claim', $html);
        $this->assertStringContainsString('CreativeVision-email-logo.png', $html);
    }

    public function test_bank_detail_notice_uses_the_phrems_email_template_and_masks_accounts(): void
    {
        $employee = new Employee([
            'employee_id' => 'EMP-1002',
            'first_name' => 'Celjoy',
            'last_name' => 'Salibay',
        ]);
        $request = new BankDetailRequest([
            'bank_name' => 'GCash',
            'bank_account_number' => '09123459724',
            'reason' => 'First account on file, entered by the employee.',
            'status' => 'approved',
        ]);
        $request->setRelation('employee', $employee);

        $mail = (new BankDetailChangeNotice(
            $request,
            'Celjoy P. Salibay has entered their payout account for the first time.',
            'Payout account set - Celjoy P. Salibay',
        ))->toMail(new User(['name' => 'Jay']));
        $html = view($mail->view, $mail->viewData)->render();

        $this->assertSame('emails.bank-detail-change-notice', $mail->view);
        $this->assertStringContainsString('Payout account set', $html);
        $this->assertStringContainsString('GCash •••••••9724', $html);
        $this->assertStringNotContainsString('09123459724', $html);
        $this->assertStringContainsString('View Bank Details', $html);
        $this->assertStringContainsString('CreativeVision-email-logo.png', $html);
    }

    public function test_email_logo_is_optimized_for_inline_delivery(): void
    {
        $path = public_path('images/CreativeVision-email-logo.png');
        $dimensions = getimagesize($path);

        $this->assertFileExists($path);
        $this->assertSame(320, $dimensions[0]);
        $this->assertSame(320, $dimensions[1]);
        $this->assertLessThan(20_000, filesize($path));
    }
}
