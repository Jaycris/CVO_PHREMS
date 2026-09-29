<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeRequest;
use App\Models\RequestType;
use App\Models\User;
use App\Notifications\EmployeeRequestActionNeeded;
use App\Notifications\EmployeeRequestDecidedForYou;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every email from PHREMS looks like PHREMS sent it.
 *
 * Half the notifications went out in Laravel's default styling — no logo, no
 * colours, a grey button — which reads to an employee as a system nobody
 * finished, or worse, as somebody else's email pretending to be payroll.
 */
class BrandedEmailTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The guard for the next one somebody writes.
     *
     * A notification that builds a MailMessage without handing it one of this
     * app's templates is using Laravel's, and that is what this catches.
     */
    #[Test]
    public function no_notification_falls_back_to_laravels_own_styling(): void
    {
        $plain = [];

        foreach (glob(app_path('Notifications/*.php')) as $file) {
            $source = (string) file_get_contents($file);

            if (! str_contains($source, 'new MailMessage')) {
                continue;
            }

            if (! str_contains($source, "->view('emails.") && ! str_contains($source, "->markdown('emails.")) {
                $plain[] = basename($file, '.php');
            }
        }

        $this->assertSame([], $plain, 'These send Laravel default styling: ' . implode(', ', $plain));
    }

    protected function request(): EmployeeRequest
    {
        $this->seed(RoleSeeder::class);

        $employee = Employee::factory()->create(['first_name' => 'Ric', 'last_name' => 'Moreno']);
        $employee->forceFill(['user_id' => User::factory()->create()->id])->save();

        $type = RequestType::create([
            'code' => 'wfh',
            'name' => 'Work From Home',
            'needs_dates' => false,
            'is_active' => true,
        ]);

        return EmployeeRequest::create([
            'employee_id' => $employee->id,
            'request_type_id' => $type->id,
            'details' => 'Internet is being repaired at the office.',
            'status' => 'pending_manager',
        ]);
    }

    #[Test]
    public function a_request_email_carries_the_company_logo_and_the_phrems_header(): void
    {
        $request = $this->request();

        $html = (new EmployeeRequestActionNeeded($request))->toMail($request->employee->user)->render();

        $this->assertStringContainsString('CreatiVision Outsourcing', $html);
        $this->assertStringContainsString('PHREMS', $html);
        $this->assertStringContainsString('Work From Home request needs approval', $html);
        $this->assertStringContainsString('Ric Moreno has filed a Work From Home request.', $html);
        $this->assertStringContainsString('Review Request', $html);
    }

    #[Test]
    public function a_decision_note_is_shown_where_it_cannot_be_skimmed_past(): void
    {
        $request = $this->request();
        $request->update(['decision_note' => 'Come in on Thursday instead.']);

        $html = (new EmployeeRequestDecidedForYou($request, 'Jay Cris approved it.'))
            ->toMail($request->employee->user)
            ->render();

        $this->assertStringContainsString('Jay Cris approved it.', $html);
        $this->assertStringContainsString('Note: Come in on Thursday instead.', $html);
    }

    #[Test]
    public function an_email_with_nothing_to_click_still_reads_properly(): void
    {
        // The shared template is used by notices with no button as well, and a
        // dangling "if the button does not work" footer would be nonsense.
        $html = view('emails.notice', [
            'heading' => 'Just so you know',
            'lines' => ['Something happened.'],
        ])->render();

        $this->assertStringContainsString('Something happened.', $html);
        $this->assertStringNotContainsString('If the button does not work', $html);
    }
}
