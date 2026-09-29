<?php

namespace Tests\Feature;

use App\Models\JobPosting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What the company website reads.
 *
 * Public and unauthenticated on purpose — a job advert is meant for strangers,
 * and a token in a website's JavaScript is not a secret. What matters is that
 * only published roles come back, and only the fields written for the public.
 */
class CareersApiTest extends TestCase
{
    use RefreshDatabase;

    protected function posting(array $overrides = []): JobPosting
    {
        return JobPosting::create(array_merge([
            'title' => 'Sales Agent',
            'summary' => 'Sell publishing services to US authors.',
            'description' => 'Full description.',
            'headcount' => 2,
            'workplace_type' => 'Remote',
            'employment_type' => 'Full-time',
            'location' => 'Cebu City',
            'apply_email' => 'hr@creativisionoutsourcing.com',
            'status' => JobPosting::PUBLISHED,
            'published_at' => now(),
        ], $overrides));
    }

    #[Test]
    public function the_website_needs_no_token(): void
    {
        $this->posting();

        $this->getJson('/api/careers/openings')->assertOk()->assertJsonPath('count', 1);
    }

    #[Test]
    public function one_role_can_be_read_by_its_link(): void
    {
        $posting = $this->posting();

        $this->getJson('/api/careers/openings/' . $posting->slug)
            ->assertOk()
            ->assertJsonPath('data.title', 'Sales Agent')
            ->assertJsonPath('data.openings', 2)
            ->assertJsonPath('data.location', 'Cebu City')
            ->assertJsonPath('data.apply_email', 'hr@creativisionoutsourcing.com');
    }

    #[Test]
    public function a_draft_is_invisible_and_says_nothing_about_itself(): void
    {
        // Not 403: a careers page is no place to confirm that the company is
        // quietly hiring for something.
        $posting = $this->posting(['status' => JobPosting::DRAFT, 'published_at' => null]);

        $this->getJson('/api/careers/openings')->assertOk()->assertJsonPath('count', 0);
        $this->getJson('/api/careers/openings/' . $posting->slug)->assertNotFound();
    }

    #[Test]
    public function the_salary_is_only_sent_when_hr_said_it_may_be(): void
    {
        $posting = $this->posting(['salary_min' => 20000, 'salary_max' => 25000]);

        $this->getJson('/api/careers/openings/' . $posting->slug)
            ->assertOk()
            ->assertJsonPath('data.salary_range', null)
            ->assertDontSee('20,000');

        $posting->update(['salary_visible' => true]);

        $this->getJson('/api/careers/openings/' . $posting->slug)
            ->assertOk()
            ->assertJsonPath('data.salary_range', '₱20,000 - ₱25,000');
    }

    #[Test]
    public function nothing_internal_travels_with_an_advert(): void
    {
        $posting = $this->posting(['salary_min' => 20000, 'salary_visible' => false]);

        $body = $this->getJson('/api/careers/openings/' . $posting->slug)->assertOk()->content();

        foreach (['salary_min', 'salary_max', 'created_by_user_id', 'position_id', 'department_id', 'status', 'updated_at'] as $internal) {
            $this->assertStringNotContainsString($internal, $body, "{$internal} reached the public careers endpoint");
        }
    }

    #[Test]
    public function the_newest_role_is_listed_first(): void
    {
        $this->posting(['title' => 'Older Role', 'published_at' => now()->subWeek()]);
        $this->posting(['title' => 'Newest Role', 'published_at' => now()]);

        $this->getJson('/api/careers/openings')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Newest Role')
            ->assertJsonPath('data.1.title', 'Older Role');
    }
}
