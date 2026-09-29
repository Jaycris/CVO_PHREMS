<?php

namespace Tests\Feature;

use App\Models\JobPosting;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * HR writing the adverts the company website shows.
 *
 * The point of keeping them here is that taking a role down is one click, not
 * an email to whoever owns the website — so what counts is that draft, closed
 * and expired roles never reach the public endpoint.
 */
class JobPostingTest extends TestCase
{
    use RefreshDatabase;

    protected User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->hr = User::factory()->create();
        $this->hr->assignRole('Admin');
        $this->hr->givePermissionTo('recruitment.manage');
    }

    protected function write(array $overrides = [])
    {
        $component = Livewire::actingAs($this->hr)
            ->test('recruitment.index')
            ->call('create')
            ->set('title', 'Sales Agent (Graveyard)')
            ->set('description', 'Selling publishing services to US authors.')
            ->set('headcount', '3')
            ->set('workplace_type', 'Remote')
            ->set('employment_type', 'Full-time')
            ->set('apply_email', 'hr@creativisionoutsourcing.com');

        foreach ($overrides as $property => $value) {
            $component->set($property, $value);
        }

        return $component->call('save');
    }

    #[Test]
    public function a_new_posting_starts_as_a_draft_and_is_not_on_the_website(): void
    {
        $this->write()->assertHasNoErrors();

        $posting = JobPosting::sole();

        $this->assertSame(JobPosting::DRAFT, $posting->status);
        $this->assertFalse($posting->isLive());
        $this->getJson('/api/careers/openings')->assertOk()->assertJsonPath('count', 0);
    }

    #[Test]
    public function publishing_puts_it_on_the_website_and_closing_takes_it_off(): void
    {
        $this->write();
        $posting = JobPosting::sole();

        Livewire::actingAs($this->hr)->test('recruitment.index')->call('publish', $posting->id);

        $this->getJson('/api/careers/openings')
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.title', 'Sales Agent (Graveyard)')
            ->assertJsonPath('data.0.openings', 3);

        Livewire::actingAs($this->hr)->test('recruitment.index')->call('close', $posting->id);

        $this->getJson('/api/careers/openings')->assertOk()->assertJsonPath('count', 0);
    }

    #[Test]
    public function a_role_past_its_closing_date_comes_off_by_itself(): void
    {
        $this->write(['closes_on' => now()->subDay()->toDateString()]);
        $posting = JobPosting::sole();

        Livewire::actingAs($this->hr)->test('recruitment.index')->call('publish', $posting->id);

        $this->assertTrue($posting->fresh()->hasExpired());
        $this->getJson('/api/careers/openings')->assertOk()->assertJsonPath('count', 0);
    }

    #[Test]
    public function republishing_after_a_correction_does_not_make_it_look_new(): void
    {
        $this->write();
        $posting = JobPosting::sole();

        Livewire::actingAs($this->hr)->test('recruitment.index')->call('publish', $posting->id);
        $firstPublished = $posting->fresh()->published_at;

        Livewire::actingAs($this->hr)->test('recruitment.index')->call('close', $posting->id);
        Livewire::actingAs($this->hr)->test('recruitment.index')->call('publish', $posting->id);

        $this->assertEquals($firstPublished, $posting->fresh()->published_at);
    }

    #[Test]
    public function an_advert_has_to_say_how_to_apply(): void
    {
        $this->write(['apply_email' => '', 'apply_url' => ''])->assertHasErrors('apply_email');

        $this->assertSame(0, JobPosting::count());
    }

    #[Test]
    public function two_roles_with_the_same_title_get_their_own_links(): void
    {
        $this->write();
        $this->write();

        $this->assertSame(
            ['sales-agent-graveyard', 'sales-agent-graveyard-2'],
            JobPosting::orderBy('id')->pluck('slug')->all(),
        );
    }

    #[Test]
    public function a_published_posting_cannot_be_deleted_by_mistake(): void
    {
        $this->write();
        $posting = JobPosting::sole();

        Livewire::actingAs($this->hr)->test('recruitment.index')->call('publish', $posting->id);

        Livewire::actingAs($this->hr)
            ->test('recruitment.index')
            ->call('deleteDraft', $posting->id)
            ->assertForbidden();

        $this->assertSame(1, JobPosting::count());
    }

    #[Test]
    public function somebody_without_the_permission_cannot_post_a_role(): void
    {
        $employee = User::factory()->create();
        $employee->assignRole('Employee');

        $this->actingAs($employee)->get('/recruitment')->assertForbidden();

        Livewire::actingAs($employee)
            ->test('recruitment.index')
            ->call('create')
            ->assertForbidden();
    }
}
