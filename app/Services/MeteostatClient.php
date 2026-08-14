<?php

/**
 * Fishing Peg Manager
 * 
 * @author      Matteo
 * @copyright   2026 Matteo
 * @license     GNU Affero General Public License v3 (AGPL-3.0)
 * 
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published
 * by the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 * 
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MeteostatClient
{
    private string $host;
    private ?string $key;

    public function __construct(
        string $host = 'meteostat.p.rapidapi.com',
        ?string $key = null,
    ) {
        $this->host = config('services.meteostat.host', $host);
        $this->key = config('services.meteostat.key', $key);
    }

    public function fetchHourlySeries(
        string $locationLabel,
        float $latitude,
        float $longitude,
        ?int $altitude,
        Carbon $start,
        Carbon $end,
    ): array
    {
        $hourlyRaw = $this->pointHourly($latitude, $longitude, $altitude, $start, $end);
        $hourly = $this->filterHourlyRange($hourlyRaw, $start, $end);

        return [
            'city' => $locationLabel,
            'location' => [
                'latitude' => $latitude,
                'longitude' => $longitude,
                'altitude' => $altitude,
            ],
            'start' => $start->copy()->toIso8601String(),
            'end' => $end->copy()->toIso8601String(),
            'hourly' => $hourly,
        ];
    }

    private function headers(): array
    {
        if (!$this->key) {
            throw new RuntimeException('Missing Meteostat API key. Set METEOSTAT_API_KEY in the .env file.');
        }

        return [
            'x-rapidapi-host' => $this->host,
            'x-rapidapi-key' => $this->key,
        ];
    }

    private function baseUrl(string $path): string
    {
        return sprintf('https://%s/%s', $this->host, ltrim($path, '/'));
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    private function pointHourly(float $latitude, float $longitude, ?int $altitude, Carbon $start, Carbon $end): array
    {
        $query = [
            'lat' => $latitude,
            'lon' => $longitude,
            'start' => $start->clone()->setTimezone('Europe/Rome')->format('Y-m-d'),
            'end' => $end->clone()->setTimezone('Europe/Rome')->format('Y-m-d'),
            'tz' => 'Europe/Rome',
            'units' => 'metric',
        ];
        if ($altitude !== null) {
            $query['alt'] = $altitude;
        }

        $response = Http::withHeaders($this->headers())
            ->get($this->baseUrl('/point/hourly'), $query);

        if ($response->failed()) {
            throw new RuntimeException('Error retrieving Meteostat data (point): ' . $response->body());
        }

        return $response->json('data', []);
    }

    /**
     * @param array<int, array<string,mixed>> $hourly
     * @return array<int, array<string,mixed>>
     */
    private function filterHourlyRange(array $hourly, Carbon $start, Carbon $end): array
    {
        $timezone = 'Europe/Rome';
        $startCopy = $start->copy()->setTimezone($timezone);
        $endCopy = $end->copy()->setTimezone($timezone);

        return array_values(array_filter($hourly, function ($entry) use ($startCopy, $endCopy, $timezone) {
            if (!is_array($entry) || !isset($entry['time'])) {
                return false;
            }

            try {
                $time = Carbon::parse($entry['time'], $timezone);
            } catch (\Throwable) {
                return false;
            }

            return $time->betweenIncluded($startCopy, $endCopy);
        }));
    }
}
