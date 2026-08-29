<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Keeping a payroll system out of search results.
 *
 * The login page names the company and the software, and a payroll system
 * answering to a search for "CreatiVision payroll" is an invitation to try
 * passwords against it. The onboarding and password-setup links are signed URLs
 * that should never be crawled and cached at all.
 */
class SearchIndexingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_login_page_refuses_indexing(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive, noimageindex');
        $response->assertSee('name="robots"', false);
    }

    #[Test]
    public function a_redirect_carries_the_header_too(): void
    {
        // A redirect has no <head> to put a meta tag in, which is why the
        // header exists rather than only the tag.
        $this->get('/dashboard')
            ->assertRedirect()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive, noimageindex');
    }

    #[Test]
    public function a_signed_in_page_refuses_indexing(): void
    {
        $this->seed(RoleSeeder::class);

        $user = User::factory()->create(['is_super_admin' => true]);
        $user->assignRole('Admin');
        Employee::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive, noimageindex');
    }

    #[Test]
    public function crawling_is_still_allowed_so_the_refusal_can_be_read(): void
    {
        /*
         * The trap this avoids: a Disallow stops the crawl, so the noindex is
         * never fetched, so anything already listed stays listed. Blocking and
         * de-indexing are opposites here.
         */
        // Comments stripped first — the file explains this rule in prose, and
        // matching the explanation rather than the rule would fail forever.
        $directives = collect(file(public_path('robots.txt')))
            ->map(fn (string $line) => trim($line))
            ->reject(fn (string $line) => $line === '' || str_starts_with($line, '#'));

        $this->assertTrue(
            $directives->contains('Disallow:'),
            'robots.txt no longer allows crawling, so the noindex will never be read.'
        );

        $this->assertFalse($directives->contains(fn (string $line) => str_starts_with($line, 'Disallow: /')));
    }
}
