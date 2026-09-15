<?php

namespace Tests\Feature\Payroll;

use App\Models\PayrollSetting;
use PHPUnit\Framework\Attributes\Test;

/**
 * The seeded payroll settings fit the live database.
 *
 * The test database does not enforce string lengths, so a description over
 * 255 characters passed every test and then stopped the seeder on Hostinger's
 * MySQL, where the column is a VARCHAR(255).
 */
class PayrollSettingSeedTest extends PayrollTestCase
{
    #[Test]
    public function every_setting_fits_its_column(): void
    {
        foreach (PayrollSetting::all() as $setting) {
            $this->assertLessThanOrEqual(255, mb_strlen((string) $setting->description), "{$setting->key} description is too long for MySQL");
            $this->assertLessThanOrEqual(255, mb_strlen((string) $setting->label), "{$setting->key} label is too long for MySQL");
        }
    }
}
