<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeRequest;
use App\Models\RequestType;
use App\Models\User;
use App\Notifications\EmployeeRequestDecidedForYou;
use App\Notifications\EmployeeRequestStatusUpdated;
use App\Services\EmployeeRequestService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The CEO or COO deciding a request that was waiting on somebody's manager.
 *
 * A request used to sit until its manager acted, which is fine until the
 * manager is on leave and the employee needs an answer about Thursday. The
 * manager is told afterwards, so the decision does not vanish from their queue
 * unexplained.
 */
class RequestDecidedByLeadershipTest extends TestCase
{
    use RefreshDatabase;

    protected Employee $manager;

    protected Employee $staff;

    protected RequestType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->manager = $this->employeeWithLogin('Maria', 'Santos');
        $this->staff = $this->employeeWithLogin('Rio', 'Moreno');
        $this->staff->update(['reports_to_id' => $this->manager->id]);

        $this->type = RequestType::create([
            'code' => 'wfh',
            'name' => 'Work From Home',
            'needs_dates' => false,
            'is_active' => true,
        ]);
    }

    protected function employeeWithLogin(string $first, string $last): Employee
    {
        $user = User::factory()->create(['name' => $first . ' ' . $last]);
        $user->assignRole('Employee');

        $employee = Employee::factory()->create(['first_name' => $first, 'last_name' => $last]);
        $employee->forceFill(['user_id' => $user->id])->save();

        return $employee->fresh();
    }

    protected function ceo(bool $withEmployeeRecord = true): User
    {
        $user = User::factory()->create(['name' => 'Jay Cris']);
        $user->assignRole('Admin');
        $user->givePermissionTo(['requests.view_all', 'requests.decide_any']);

        if ($withEmployeeRecord) {
            Employee::factory()->create(['first_name' => 'Jay', 'last_name' => 'Cris'])
                ->forceFill(['user_id' => $user->id])->save();
        }

        return $user->fresh();
    }

    protected function fileRequest(): EmployeeRequest
    {
        return app(EmployeeRequestService::class)
            ->submit($this->staff, $this->type, 'Internet is being repaired at the office.');
    }

    #[Test]
    public function a_request_goes_to_the_employees_manager_as_before(): void
    {
        $this->assertSame($this->manager->id, $this->fileRequest()->manager_id);
    }

    #[Test]
    public function the_ceo_decides_a_request_waiting_on_a_manager(): void
    {
        Notification::fake();

        $request = $this->fileRequest();
        $ceo = $this->ceo();

        Livewire::actingAs($ceo)
            ->test('requests.index')
            ->call('review', $request->id)
            ->call('decide', true)
            ->assertSet('errorMessage', null);

        $decided = $request->fresh();

        $this->assertSame('approved', $decided->status);
        $this->assertSame($ceo->id, $decided->decided_by_user_id);
        // Still recorded as the manager it was routed to.
        $this->assertSame($this->manager->id, $decided->manager_id);
    }

    #[Test]
    public function the_manager_is_told_it_was_decided_for_them(): void
    {
        Notification::fake();

        $request = $this->fileRequest();
        $ceo = $this->ceo();

        app(EmployeeRequestService::class)->decide($request, $ceo->employee, true, null, $ceo);

        Notification::assertSentTo(
            $this->manager->user,
            EmployeeRequestDecidedForYou::class,
            function (EmployeeRequestDecidedForYou $notification) {
                return str_contains($notification->message, 'Jay Cris approved')
                    && str_contains($notification->message, 'Rio Moreno');
            },
        );

        // And the employee still hears the outcome.
        Notification::assertSentTo($this->staff->user, EmployeeRequestStatusUpdated::class);
    }

    #[Test]
    public function a_manager_deciding_their_own_queue_is_told_nothing(): void
    {
        Notification::fake();

        $request = $this->fileRequest();

        app(EmployeeRequestService::class)->decide($request, $this->manager, true, null, $this->manager->user);

        Notification::assertNotSentTo($this->manager->user, EmployeeRequestDecidedForYou::class);
        $this->assertSame($this->manager->user->id, $request->fresh()->decided_by_user_id);
    }

    #[Test]
    public function the_ceo_sees_everything_still_waiting(): void
    {
        $this->fileRequest();

        Livewire::actingAs($this->ceo())
            ->test('requests.index')
            ->assertSee('Rio Moreno')
            ->assertSee('Everything still waiting');
    }

    #[Test]
    public function a_ceo_with_no_employee_record_can_still_decide(): void
    {
        Notification::fake();

        $request = $this->fileRequest();

        Livewire::actingAs($this->ceo(withEmployeeRecord: false))
            ->test('requests.index')
            ->call('review', $request->id)
            ->call('decide', true)
            ->assertSet('errorMessage', null);

        $this->assertSame('approved', $request->fresh()->status);
    }

    #[Test]
    public function nobody_else_can_decide_somebody_elses_request(): void
    {
        Notification::fake();

        $request = $this->fileRequest();
        $colleague = $this->employeeWithLogin('Ana', 'Reyes');

        Livewire::actingAs($colleague->user)
            ->test('requests.index')
            ->call('review', $request->id)
            ->call('decide', true)
            ->assertSet('errorMessage', 'You cannot decide this request.');

        $this->assertTrue($request->fresh()->isPending());
    }
}
