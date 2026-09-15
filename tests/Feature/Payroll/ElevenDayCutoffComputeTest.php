<?php

namespace Tests\Feature\Payroll;

use App\Models\Payslip;
use App\Services\Payroll\PayrollService;
use PHPUnit\Framework\Attributes\Test;

/**
 * The 11-day cutoff and the 31st rule, through a whole compute rather than the
 * aggregator alone — what the run's Days column actually shows.
 */
class ElevenDayCutoffComputeTest extends PayrollTestCase
{
    #[Test]
    public function the_26_august_cutoff_computes_as_eleven_days(): void
    {
        $period = $this->period(2026, 9, 'first');
        $night = $this->makeEmployee(23000, 'graveyard');
        $this->fillAttendance($night, $period);

        $service = app(PayrollService::class);
        $run = $service->openRun(2026, 9, 'first');
        $service->compute($run, $this->admin);

        $payslip = Payslip::where('employee_id', $night->id)->sole();

        $this->assertSame(11, (int) $payslip->days_expected);
        $this->assertSame(11, (int) $payslip->days_present);
        $this->assertSame(11, (int) $payslip->night_diff_days);
    }
}
