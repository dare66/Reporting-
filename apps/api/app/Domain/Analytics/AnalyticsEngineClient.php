<?php

namespace App\Domain\Analytics;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Client for the Python analytics engine (statistical forecasting and
 * anomaly detection). The engine is stateless: series in, results out.
 */
class AnalyticsEngineClient
{
    /** @param array<int, array{period: string, value: float|null}> $series */
    public function forecast(array $series, string $grain, int $horizon): array
    {
        return $this->post('/v1/analytics/forecast', compact('series', 'grain', 'horizon'));
    }

    /** @param array<int, array{period: string, value: float|null}> $series */
    public function anomalies(array $series, string $grain, float $threshold = 3.0): array
    {
        return $this->post('/v1/analytics/anomalies', compact('series', 'grain', 'threshold'));
    }

    private function post(string $path, array $payload): array
    {
        try {
            $response = Http::baseUrl(config('aixbi.ai.url'))
                ->withToken((string) config('aixbi.ai.token'))
                ->timeout(30)
                ->acceptJson()
                ->post($path, $payload);
        } catch (ConnectionException) {
            throw new RuntimeException('The analytics engine is unavailable. Forecasts and anomaly scans will resume when it is back.');
        }
        if ($response->failed()) {
            throw new RuntimeException('The analytics engine rejected the request: '.($response->json('detail') ?? $response->status()));
        }

        return $response->json();
    }
}
