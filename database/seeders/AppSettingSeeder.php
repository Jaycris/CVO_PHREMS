<?php

namespace Database\Seeders;

use App\Models\AppSetting;
use App\Services\Sms\SmsGateway;
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
            [
                'key' => AppSetting::DTR_ROWS_PER_PAGE,
                'default' => '10',
                'label' => 'Rows per page — Daily Time Record',
                'description' => 'The DTR holds a row per employee per day, so it fills up far faster than the other tables. Set it higher to work through a whole cutoff without paging.',
                'type' => 'choice',
                'group' => 'Tables',
            ],

            /*
             * Both default to No, and stay that way until somebody turns them
             * on. Every text costs a credit, so nothing starts sending merely
             * because the code shipped — and switching one off during a bad
             * afternoon is a dropdown here rather than a deploy.
             */
            [
                'key' => SmsGateway::OFFSITE_WORK,
                'default' => '0',
                'label' => 'Text staff about off-site work',
                'description' => 'Sends a text when off-site days are set, changed or cancelled, on top of the email. They are away from a desk on those days, which is the point.',
                'type' => 'boolean',
                'group' => 'Text Messages',
            ],
            [
                'key' => SmsGateway::URGENT_ANNOUNCEMENT,
                'default' => '0',
                'label' => 'Allow announcements to be texted',
                'description' => 'Adds a "Text everybody as well" option when posting a notice. It is never automatic — whoever writes the notice ticks it, having seen what the text will say and how many staff it reaches.',
                'type' => 'boolean',
                'group' => 'Text Messages',
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
