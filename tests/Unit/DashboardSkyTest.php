<?php

namespace Tests\Unit;

use App\Services\DashboardSky;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class DashboardSkyTest extends TestCase
{
    public function test_it_resolves_a_waxing_crescent_for_cebu_on_september_14_2026(): void
    {
        $sky = app(DashboardSky::class)->for(
            CarbonImmutable::parse('2026-09-14 23:00:00', 'Asia/Manila')
        );

        $this->assertSame('waxing-crescent', $sky['phase']);
        $this->assertSame('Waxing Crescent', $sky['phase_name']);
        $this->assertFalse($sky['is_day']);
    }

    public function test_it_resolves_the_full_moon_window(): void
    {
        $sky = app(DashboardSky::class)->for(
            CarbonImmutable::parse('2026-09-27 00:49:00', 'Asia/Manila')
        );

        $this->assertSame('full', $sky['phase']);
        $this->assertSame('Full Moon', $sky['phase_name']);
    }

    public function test_it_uses_a_daytime_fallback_when_weather_is_not_queried(): void
    {
        $sky = app(DashboardSky::class)->for(
            CarbonImmutable::parse('2026-09-14 12:00:00', 'Asia/Manila')
        );

        $this->assertTrue($sky['is_day']);
    }

    public function test_every_resolved_phase_has_its_own_rendered_moon_asset(): void
    {
        foreach ([
            'new',
            'waxing-crescent',
            'first-quarter',
            'waxing-gibbous',
            'full',
            'waning-gibbous',
            'last-quarter',
            'waning-crescent',
        ] as $phase) {
            $this->assertFileExists(public_path("images/dashboard-moon-{$phase}.webp"));
        }
    }
}
