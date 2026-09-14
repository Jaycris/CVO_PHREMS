<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class DashboardSky
{
    private const SYNODIC_MONTH = 29.53058867;

    private const KNOWN_NEW_MOON_JULIAN_DAY = 2451550.1;

    /** @return array{phase: string, phase_name: string, cloud_cover: int|null, cloudy: bool, is_day: bool} */
    public function for(CarbonInterface $at): array
    {
        $phase = $this->phaseFraction($at);
        [$phaseKey, $phaseName] = $this->phaseLabel($phase);
        $weather = $this->weather($at);
        $cloudCover = $weather['cloud_cover'];

        return [
            'phase' => $phaseKey,
            'phase_name' => $phaseName,
            'cloud_cover' => $cloudCover,
            'cloudy' => $cloudCover !== null && $cloudCover >= 25,
            'is_day' => $weather['is_day'],
        ];
    }

    private function phaseFraction(CarbonInterface $at): float
    {
        $julianDay = ($at->copy()->utc()->getTimestamp() / 86400) + 2440587.5;
        $cycles = ($julianDay - self::KNOWN_NEW_MOON_JULIAN_DAY) / self::SYNODIC_MONTH;

        return $cycles - floor($cycles);
    }

    /** @return array{string, string} */
    private function phaseLabel(float $phase): array
    {
        return match (true) {
            $phase < 0.0625 || $phase >= 0.9375 => ['new', 'New Moon'],
            $phase < 0.1875 => ['waxing-crescent', 'Waxing Crescent'],
            $phase < 0.3125 => ['first-quarter', 'First Quarter'],
            $phase < 0.4375 => ['waxing-gibbous', 'Waxing Gibbous'],
            $phase < 0.5625 => ['full', 'Full Moon'],
            $phase < 0.6875 => ['waning-gibbous', 'Waning Gibbous'],
            $phase < 0.8125 => ['last-quarter', 'Last Quarter'],
            default => ['waning-crescent', 'Waning Crescent'],
        };
    }

    /** @return array{cloud_cover: int|null, is_day: bool} */
    private function weather(CarbonInterface $at): array
    {
        $fallback = [
            'cloud_cover' => null,
            'is_day' => $at->hour >= 6 && $at->hour < 18,
        ];

        if (app()->runningUnitTests()) {
            return $fallback;
        }

        $latitude = (float) config('services.dashboard_weather.latitude');
        $longitude = (float) config('services.dashboard_weather.longitude');
        $cacheKey = "dashboard-sky:weather:{$latitude}:{$longitude}";

        return Cache::remember($cacheKey, now()->addMinutes(20), function () use ($fallback, $latitude, $longitude): array {
            try {
                $response = Http::acceptJson()
                    ->timeout((int) config('services.dashboard_weather.timeout', 3))
                    ->get('https://api.open-meteo.com/v1/forecast', [
                        'latitude' => $latitude,
                        'longitude' => $longitude,
                        'current' => 'cloud_cover,is_day',
                        'timezone' => config('app.timezone'),
                        'forecast_days' => 1,
                    ])
                    ->throw();

                $cloudCover = $response->json('current.cloud_cover');
                $isDay = $response->json('current.is_day');

                return [
                    'cloud_cover' => is_numeric($cloudCover)
                        ? max(0, min(100, (int) round($cloudCover)))
                        : null,
                    'is_day' => is_numeric($isDay) ? (bool) $isDay : $fallback['is_day'],
                ];
            } catch (Throwable) {
                return $fallback;
            }
        });
    }
}
