<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\ProductController;
use App\Providers\AppServiceProvider;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use LogicException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ProductRateLimitTest extends TestCase
{
    private int $handledRequests = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', $this->app->environment());
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertEmpty(config('database.connections.sqlite.url'));
        $this->assertSame('array', config('cache.limiter') ?? config('cache.default'));
        $this->assertInstanceOf(ArrayStore::class, $this->app['cache']->store('array')->getStore());

        $this->freezeTime();
        config(['rate_limits.public_products_per_minute' => 2]);

        // Exercise the real Product routes and throttle middleware without DB I/O.
        // Existing Product Feature tests cover the real controllers and contracts.
        $controller = Mockery::mock(ProductController::class);
        $controller->shouldReceive('index')->andReturnUsing(function (Request $request) {
            $this->handledRequests++;
            $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);

            return response()->json(['data' => []]);
        });
        $controller->shouldReceive('show')->andReturnUsing(function (Request $request, string $id) {
            $this->handledRequests++;

            if ($id === '999999') {
                abort(404);
            }

            return response()->json(['data' => ['id' => (int) $id]]);
        });
        $this->app->instance(ProductController::class, $controller);
    }

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    public function test_default_quota_allows_sixty_requests_then_blocks_the_next(): void
    {
        $defaults = require base_path('config/rate_limits.php');
        $this->assertSame(60, $defaults['public_products_per_minute']);
        config(['rate_limits.public_products_per_minute' => 60]);

        for ($attempt = 0; $attempt < 60; $attempt++) {
            $this->requestProduct()->assertOk();
        }

        $this->requestProduct()->assertTooManyRequests();
        $this->assertSame(60, $this->handledRequests);
    }

    public function test_listing_and_details_share_one_budget_and_preserve_rate_headers(): void
    {
        $this->requestProduct()->assertOk()
            ->assertHeader('X-RateLimit-Limit', '2')
            ->assertHeader('X-RateLimit-Remaining', '1');
        $this->requestProduct('/api/products/1')->assertOk()
            ->assertHeader('X-RateLimit-Remaining', '0');

        $this->assertThrottled($this->requestProduct());
        $this->assertSame(2, $this->handledRequests);
    }

    public function test_distinct_client_ips_have_independent_budgets(): void
    {
        $this->requestProduct()->assertOk();
        $this->requestProduct()->assertOk();
        $this->requestProduct()->assertTooManyRequests();

        $this->requestProduct('/api/products/1', '192.0.2.11')->assertOk()
            ->assertHeader('X-RateLimit-Remaining', '1');
    }

    public function test_untrusted_forwarded_headers_cannot_create_new_buckets(): void
    {
        $this->requestProduct('/api/products', '192.0.2.10', [
            'X-Forwarded-For' => '198.51.100.1',
        ])->assertOk();
        $this->requestProduct('/api/products/1', '192.0.2.10', [
            'X-Forwarded-For' => '198.51.100.2',
            'Forwarded' => 'for=198.51.100.2',
        ])->assertOk();
        $this->requestProduct('/api/products/2', '192.0.2.10', [
            'X-Forwarded-For' => '198.51.100.3',
        ])->assertTooManyRequests();
    }

    public function test_query_parameters_and_product_ids_do_not_change_the_bucket(): void
    {
        $this->requestProduct('/api/products?per_page=1&ip=198.51.100.1')->assertOk();
        $this->requestProduct('/api/products/42?seller_status=active')->assertOk();
        $this->requestProduct('/api/products/43?status=published')->assertTooManyRequests();
    }

    public function test_unknown_ip_uses_a_finite_shared_fallback_bucket(): void
    {
        $this->requestProduct('/api/products', null)->assertOk();
        $this->requestProduct('/api/products/1', null)->assertOk();
        $this->requestProduct('/api/products/2', null)->assertTooManyRequests();
    }

    public function test_requests_recover_after_the_limit_window_without_sleeping(): void
    {
        $this->requestProduct()->assertOk();
        $this->requestProduct()->assertOk();
        $this->assertThrottled($this->requestProduct());

        $this->travel(61)->seconds();

        $this->requestProduct('/api/products/1')->assertOk()
            ->assertHeader('X-RateLimit-Remaining', '1');
    }

    public function test_404_and_422_requests_consume_the_shared_budget(): void
    {
        $this->requestProduct('/api/products/999999')->assertNotFound();
        $this->requestProduct('/api/products?per_page=0')->assertUnprocessable();
        $this->requestProduct()->assertTooManyRequests();
        $this->assertSame(2, $this->handledRequests);
    }

    public function test_head_requests_share_the_get_budget(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
            ->head('/api/products')->assertOk();
        $this->requestProduct()->assertOk();
        $this->requestProduct()->assertTooManyRequests();
    }

    public function test_429_is_json_without_an_accept_header(): void
    {
        $this->requestProduct()->assertOk();
        $this->requestProduct()->assertOk();

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
            ->get('/api/products/1')->assertTooManyRequests()
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['message' => 'Too many requests.']);
    }

    public function test_unrelated_routes_do_not_use_the_product_limiter(): void
    {
        $this->requestProduct()->assertOk();
        $this->requestProduct()->assertOk();
        $this->requestProduct()->assertTooManyRequests();

        $this->get('/')->assertOk();
        $this->get('/up')->assertOk();
        $this->assertNotContains('throttle:public-products', Route::getRoutes()
            ->getByName('api.auth.login')->gatherMiddleware());
    }

    #[DataProvider('invalidQuotas')]
    public function test_invalid_quota_is_an_error_and_never_unlimited(mixed $quota): void
    {
        config(['rate_limits.public_products_per_minute' => $quota]);
        $this->withoutExceptionHandling();
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Public Product rate limit must be an integer between 1 and 10000.');

        try {
            $this->requestProduct();
        } finally {
            $this->assertSame(0, $this->handledRequests);
        }
    }

    public static function invalidQuotas(): array
    {
        return [
            [0], [-1], ['0'], ['-1'], [''], ['invalid'], ['1.5'],
            [1.5], [true], [false], [null], [[]], ['1e2'],
            ['999999999999999999999999999999'],
            [10001], ['10001'], [999999999], ['999999999'],
        ];
    }

    #[DataProvider('validQuotaBoundaries')]
    public function test_valid_quota_boundaries_apply_to_the_shared_budget(int|string $quota): void
    {
        config(['rate_limits.public_products_per_minute' => $quota]);
        $limit = (int) $quota;

        $this->requestProduct()->assertOk()
            ->assertHeader('X-RateLimit-Limit', (string) $limit)
            ->assertHeader('X-RateLimit-Remaining', (string) ($limit - 1));

        if ($limit === 1) {
            $this->requestProduct('/api/products/1')->assertTooManyRequests();
            $this->assertSame(1, $this->handledRequests);
        } else {
            $this->requestProduct('/api/products/1')->assertOk()
                ->assertHeader('X-RateLimit-Limit', (string) $limit)
                ->assertHeader('X-RateLimit-Remaining', (string) ($limit - 2));
            $this->assertSame(2, $this->handledRequests);
        }
    }

    public static function validQuotaBoundaries(): array
    {
        return [
            'minimum integer' => [1],
            'default integer' => [60],
            'maximum integer' => [10000],
            'minimum environment string' => ['1'],
            'default environment string' => ['60'],
            'maximum environment string' => ['10000'],
        ];
    }

    public function test_positive_string_quota_from_environment_is_supported(): void
    {
        config(['rate_limits.public_products_per_minute' => '1']);
        $this->requestProduct()->assertOk()
            ->assertHeader('X-RateLimit-Limit', '1');
        $this->requestProduct()->assertTooManyRequests();
    }

    #[DataProvider('unsafeStores')]
    public function test_failover_and_null_stores_are_not_silent_bypasses(string $store): void
    {
        config(['cache.limiter' => $store, 'cache.stores.'.$store.'.driver' => $store]);
        $this->withoutExceptionHandling();
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Unsafe cache store for public Product rate limiting.');

        try {
            $this->requestProduct();
        } finally {
            $this->assertSame(0, $this->handledRequests);
        }
    }

    public static function unsafeStores(): array
    {
        return [['failover'], ['null']];
    }

    public function test_cache_exception_propagates_instead_of_becoming_429_or_allowing_access(): void
    {
        $cache = Mockery::mock(CacheRepository::class);
        $cache->shouldReceive('getStore')->andReturn(new ArrayStore);
        $cache->shouldReceive('get')->andThrow(new RuntimeException('Simulated cache outage.'));
        RateLimiter::swap(new CacheRateLimiter($cache));
        $this->app->getProvider(AppServiceProvider::class)->boot();

        $this->withoutExceptionHandling();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Simulated cache outage.');

        try {
            $this->requestProduct();
        } finally {
            $this->assertSame(0, $this->handledRequests);
        }
    }

    private function requestProduct(string $url = '/api/products', ?string $ip = '192.0.2.10', array $headers = []): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->getJson($url, $headers);
    }

    private function assertThrottled(TestResponse $response): void
    {
        $response->assertTooManyRequests()
            ->assertExactJson(['message' => 'Too many requests.'])
            ->assertHeader('X-RateLimit-Limit', '2')
            ->assertHeader('X-RateLimit-Remaining', '0')
            ->assertHeader('Retry-After', '60')
            ->assertHeader('X-RateLimit-Reset', (string) (now()->timestamp + 60));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }
}
