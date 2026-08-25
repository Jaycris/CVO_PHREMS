<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Employee;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The one door into this app that is not a person signing in.
 *
 * The CRM reaches these endpoints with a shared token, over the internet, to
 * read employee records. Two things therefore have to hold and keep holding:
 * nothing gets through without the token, and what does get through carries
 * only the fields the CRM is allowed to hold. The second is the one that would
 * fail quietly — an extra column added to Employee later shows up in the JSON
 * and nobody notices until a salary is sitting in another system's database.
 */
class CrmApiTest extends TestCase
{
    use RefreshDatabase;

    protected string $plaintext;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        // The env fallback must not be what is authenticating these tests.
        config(['services.crm.inbound_token' => null]);
        putenv('CRM_INBOUND_API_TOKEN=');

        $this->plaintext = ApiToken::issue('Test CRM')['plaintext'];
    }

    protected function auth(): array
    {
        return ['Authorization' => 'Bearer ' . $this->plaintext];
    }

    #[Test]
    public function a_request_with_no_token_is_refused(): void
    {
        $this->getJson('/api/crm/health')->assertUnauthorized();
        $this->getJson('/api/crm/employees')->assertUnauthorized();
    }

    #[Test]
    public function a_request_with_the_wrong_token_is_refused(): void
    {
        $this->getJson('/api/crm/employees', ['Authorization' => 'Bearer hris_notarealtoken'])
            ->assertUnauthorized();
    }

    #[Test]
    public function a_valid_token_gets_through(): void
    {
        $this->getJson('/api/crm/health', $this->auth())->assertOk();
    }

    #[Test]
    public function a_revoked_token_stops_working(): void
    {
        ApiToken::query()->delete();

        $this->getJson('/api/crm/health', $this->auth())->assertUnauthorized();
    }

    #[Test]
    public function the_search_finds_an_employee_by_id_and_by_name(): void
    {
        $employee = Employee::factory()->create([
            'employee_id' => 'EMP-4242',
            'first_name' => 'Maria',
            'last_name' => 'Santos',
        ]);

        $this->getJson('/api/crm/employees?q=EMP-4242', $this->auth())
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.hris_employee_id', 'EMP-4242');

        $this->getJson('/api/crm/employees?q=Santos', $this->auth())
            ->assertOk()
            ->assertJsonPath('data.0.hris_employee_id', $employee->employee_id);
    }

    #[Test]
    public function an_unknown_employee_id_is_a_clean_404(): void
    {
        $this->getJson('/api/crm/employees/EMP-DOESNOTEXIST', $this->auth())
            ->assertNotFound();
    }

    /**
     * The whole point of the allow-list, asserted field by field.
     *
     * These are the exact fields the user said must never leave this system.
     * If someone widens CrmSafeEmployee, or swaps it for the model, this fails.
     */
    #[Test]
    public function sensitive_fields_never_leave_the_building(): void
    {
        Employee::factory()->create([
            'employee_id' => 'EMP-4242',
            'basic_salary' => 65000,
            'tin_number' => '123-456-789-000',
            'sss_number' => '34-1234567-8',
            'philhealth_number' => '12-345678901-2',
            'pagibig_number' => '1234-5678-9012',
            'birthdate' => '1990-05-14',
        ]);

        foreach ([
            '/api/crm/employees?q=EMP-4242',
            '/api/crm/employees/EMP-4242',
        ] as $url) {
            $body = $this->getJson($url, $this->auth())->assertOk()->content();

            foreach ([
                'basic_salary', 'allowance', 'tin_number', 'sss_number',
                'philhealth_number', 'pagibig_number', 'birthdate',
                'civil_status', 'address', 'emergency_contact_name', 'personal_email',
                'personal_contact_number',
                'bank_account_number', 'bank_name',
            ] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $body,
                    "{$forbidden} was exposed to the CRM by {$url}");
            }

            // The actual values, not just the field names — a renamed key
            // would still be a leak.
            foreach (['65000', '123-456-789-000', '34-1234567-8', '1990-05-14'] as $value) {
                $this->assertStringNotContainsString($value, $body,
                    "a sensitive value leaked to the CRM through {$url}");
            }
        }
    }

    #[Test]
    public function the_lookup_returns_the_fields_the_crm_actually_needs(): void
    {
        Employee::factory()->create(['employee_id' => 'EMP-4242']);

        $this->getJson('/api/crm/employees/EMP-4242', $this->auth())
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'hris_employee_id', 'first_name', 'last_name', 'email',
                    'department', 'position', 'employment_status',
                    'employment_type', 'workplace_type', 'is_active',
                ],
            ]);
    }

    #[Test]
    public function the_endpoints_are_rate_limited(): void
    {
        // 60 a minute. A shared token plus an unthrottled search is how one
        // leaked secret becomes a full copy of the staff directory.
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/crm/health', $this->auth())->assertOk();
        }

        $this->getJson('/api/crm/health', $this->auth())->assertStatus(429);
    }

    #[Test]
    public function a_blank_phone_name_falls_back_to_the_employees_real_name(): void
    {
        // The field is optional, so leaving it blank has to still give the CRM
        // a name to open its Create User form with. Sending null made it
        // optional in name only.
        Employee::factory()->create([
            "employee_id" => "EMP-4242",
            "first_name" => "Maria",
            "last_name" => "Santos",
            "phone_name" => null,
        ]);

        $this->getJson("/api/crm/employees/EMP-4242", $this->auth())
            ->assertOk()
            ->assertJsonPath("data.first_name", "Maria")
            ->assertJsonPath("data.last_name", "Santos");
    }

    #[Test]
    public function a_phone_name_still_wins_when_one_is_set(): void
    {
        Employee::factory()->create([
            "employee_id" => "EMP-4243",
            "first_name" => "Maria",
            "last_name" => "Santos",
            "phone_name" => "Lewis Anderson",
        ]);

        $this->getJson("/api/crm/employees/EMP-4243", $this->auth())
            ->assertOk()
            ->assertJsonPath("data.first_name", "Lewis")
            ->assertJsonPath("data.last_name", "Anderson");
    }
}