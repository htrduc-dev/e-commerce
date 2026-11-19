<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use LogicException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('public-products', function (Request $request): Limit {
            $configuredQuota = config('rate_limits.public_products_per_minute');
            $quota = is_int($configuredQuota) || is_string($configuredQuota)
                ? filter_var($configuredQuota, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]])
                : false;

            if ($quota === false) {
                throw new LogicException('Public Product rate limit must be an integer between 1 and 10000.');
            }

            // Do not allow configured failover to silently lose shared counters.
            // The actual shared-store topology still requires deployment review.
            $store = config('cache.limiter') ?? config('cache.default');
            $driver = is_string($store) ? config('cache.stores.'.$store.'.driver') : null;

            if ($driver === null || in_array($driver, ['failover', 'null'], true)
                || ($driver === 'array' && ! $this->app->environment('testing'))) {
                throw new LogicException('Unsafe cache store for public Product rate limiting.');
            }

            return Limit::perMinute($quota)
                ->by('ecommerce-api:public-products:v1:'.hash('sha256', $request->ip() ?? 'unknown'))
                ->response(static fn (Request $request, array $headers) => response()
                    ->json(['message' => 'Too many requests.'], 429, $headers)
                    ->header('Cache-Control', 'no-store'));
        });
    }
}
