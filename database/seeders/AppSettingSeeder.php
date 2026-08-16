<?php

namespace Database\Seeders;

use App\Models\AppSetting;
use Illuminate\Database\Seeder;

class AppSettingSeeder extends Seeder
{
    /**
     * The wording is refreshed on every reseed, the value is not. Reseeding
     * must never undo a choice an admin made on the settings screen.
     */
    public function run(): void
    {
        $settings = [
            [
                'key' => 'rows_per_page',
                'default' => '10',
                'label' => 'Rows per page',
                'description' => 'How many records each table shows before splitting into pages.',
                'type' => 'choice',
                'group' => 'Tables',
            ],
        ];

        foreach ($settings as $setting) {
            $default = $setting['default'];
            unset($setting['default']);

            $row = AppSetting::firstOrNew(['key' => $setting['key']]);
            $row->fill($setting);
            $row->value ??= $default;
            $row->save();
        }

        AppSetting::flushCache();
    }
}
