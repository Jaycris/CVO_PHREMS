<?php

namespace Database\Seeders;

use App\Models\Holiday;
use Illuminate\Database\Seeder;

/**
 * The 2026 Philippine holidays that are fixed by law.
 *
 * Deliberately not the whole year. Two kinds of holiday cannot be seeded and
 * have to be typed in from the proclamation when it lands:
 *
 *   Eid'l Fitr and Eid'l Adha follow the Islamic lunar calendar and are set by
 *   Malacanang only after the moon is sighted, usually a few days ahead.
 *
 *   The "additional special (non-working) days" — most years 24 December and 2
 *   November, sometimes a long-weekend bridge — exist purely at the
 *   President's discretion and change from year to year.
 *
 * Seeding a guess at those would be worse than leaving them out, because a
 * wrong holiday pays people for a day they were supposed to work and nobody
 * checks a list that looks complete.
 *
 * Re-running this is safe: it matches on date and name, so a holiday HR has
 * re-typed or re-categorised is left exactly as they left it.
 */
class HolidaySeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->holidays() as [$date, $name, $type]) {
            Holiday::firstOrCreate(
                ['date' => $date, 'name' => $name],
                ['type' => $type],
            );
        }
    }

    /** @return list<array{string, string, string}> */
    protected function holidays(): array
    {
        $regular = Holiday::REGULAR;
        $special = Holiday::SPECIAL_NON_WORKING;

        return [
            ['2026-01-01', 'New Year\'s Day', $regular],
            ['2026-02-17', 'Chinese New Year', $special],
            ['2026-04-02', 'Maundy Thursday', $regular],
            ['2026-04-03', 'Good Friday', $regular],
            ['2026-04-04', 'Black Saturday', $special],
            ['2026-04-09', 'Araw ng Kagitingan', $regular],
            ['2026-05-01', 'Labor Day', $regular],
            ['2026-06-12', 'Independence Day', $regular],
            ['2026-08-21', 'Ninoy Aquino Day', $special],
            ['2026-08-31', 'National Heroes Day', $regular],
            ['2026-11-01', 'All Saints\' Day', $special],
            ['2026-11-30', 'Bonifacio Day', $regular],
            ['2026-12-08', 'Feast of the Immaculate Conception', $special],
            ['2026-12-25', 'Christmas Day', $regular],
            ['2026-12-30', 'Rizal Day', $regular],
            ['2026-12-31', 'Last Day of the Year', $special],
        ];
    }
}
