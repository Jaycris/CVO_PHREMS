<?php

namespace Database\Seeders;

use App\Models\CashCategory;
use App\Models\CashEntry;
use Illuminate\Database\Seeder;

/**
 * Starting categories for a Philippine BPO's cash record.
 *
 * Enough to start recording on day one without inventing a filing system
 * first, and short enough that nobody is scrolling a list of forty. They are
 * editable, so what survives is whatever the company actually spends on.
 *
 * Re-running is safe — it matches on name and side, so a renamed or
 * deactivated category is left as HR left it.
 */
class CashCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            CashEntry::IN => [
                'Client Payment',
                'Commission Revenue',
                'Refund Received',
                'Other Income',
            ],
            CashEntry::OUT => [
                'Salaries and Wages',
                'Government Remittance',
                'Rent',
                'Utilities',
                'Internet and Phone',
                'Equipment and Software',
                'Office Supplies',
                'Transportation',
                'Professional Fees',
                'Government Fees and Permits',
                'Staff Welfare',
                'Repairs and Maintenance',
                'Other Expense',
            ],
        ];

        foreach ($categories as $direction => $names) {
            foreach ($names as $order => $name) {
                CashCategory::firstOrCreate(
                    ['name' => $name, 'direction' => $direction],
                    ['sort_order' => $order, 'is_active' => true],
                );
            }
        }
    }
}
