<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class RecordResponseMetrics
{
    /**
     * Cache key for sampled APM response durations.
     */
    public const SAMPLES_CACHE_KEY = 'apm_response_samples';

    /**
     * Handle an incoming request and sample 1 in 20 web requests to measure response time.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);

        $response = $next($request);

        try {
            // Only sample GET web requests, skip assets, health checks, etc.
            if ($this->shouldSample($request)) {
                $durationMs = round((microtime(true) - $start) * 1000, 2);
                $this->recordSample($request, $durationMs, $response->getStatusCode());
            }
        } catch (Throwable) {
            // Metrics collection must never disrupt user requests
        }

        return $response;
    }

    /**
     * Determine if this request should be sampled (1 in 20 chance).
     */
    protected function shouldSample(Request $request): bool
    {
        if (! $request->isMethod('GET')) {
            return false;
        }

        $path = $request->path();
        if ($path === 'up' || str_starts_with($path, 'api/') || str_starts_with($path, '_')) {
            return false;
        }

        // 1 in 20 sampling (5% sample rate)
        return random_int(1, 20) === 1;
    }

    /**
     * Store sample in cache with sliding window.
     */
    protected function recordSample(Request $request, float $durationMs, int $statusCode): void
    {
        $samples = Cache::get(self::SAMPLES_CACHE_KEY, []);
        if (! is_array($samples)) {
            $samples = [];
        }

        $samples[] = [
            'time' => now()->timestamp,
            'duration' => $durationMs,
            'path' => '/'.ltrim($request->path(), '/'),
            'status' => $statusCode,
        ];

        // Keep last 100 samples
        if (count($samples) > 100) {
            $samples = array_slice($samples, -100);
        }

        Cache::put(self::SAMPLES_CACHE_KEY, $samples, now()->addHours(24));
    }
}
