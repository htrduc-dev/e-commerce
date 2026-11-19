<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Seller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductIndexTest extends TestCase
{
    use RefreshDatabase {
        refreshDatabase as private refreshInMemoryDatabase;
    }

    public function refreshDatabase(): void
    {
        $this->assertSame('testing', $this->app->environment());
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame('sqlite', config('database.connections.sqlite.driver'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertEmpty(config('database.connections.sqlite.url'));

        $this->refreshInMemoryDatabase();
    }

    public function test_guests_only_receive_published_products_and_public_fields(): void
    {
        $published = $this->createProduct(['price' => '1234567890123', 'stock' => 0]);
        $this->createProduct(['status' => 'draft']);
        $this->createProduct(['status' => 'archived']);

        $this->getJson('/api/products?status=draft')
            ->assertOk()
            ->assertExactJson([
                'data' => [[
                    'id' => $published->id,
                    'name' => $published->name,
                    'slug' => $published->slug,
                    'price' => '1234567890123',
                ]],
                'pagination' => [
                    'current_page' => 1,
                    'per_page' => 15,
                    'total' => 1,
                    'last_page' => 1,
                    'from' => 1,
                    'to' => 1,
                ],
            ]);
    }

    public function test_price_preserves_the_signed_bigint_boundary_as_a_json_string(): void
    {
        $this->createProduct(['price' => '9223372036854775807']);

        $this->getJson('/api/products')
            ->assertOk()
            ->assertJsonPath('data.0.price', '9223372036854775807');
    }

    public function test_price_above_javascript_safe_integer_is_an_exact_json_string(): void
    {
        $this->createProduct(['price' => '9007199254740993']);

        $this->getJson('/api/products')
            ->assertOk()
            ->assertJsonPath('data.0.price', '9007199254740993');
    }

    public function test_zero_price_is_a_json_string(): void
    {
        $this->createProduct(['price' => '0']);

        $this->getJson('/api/products')
            ->assertOk()
            ->assertJsonPath('data.0.price', '0');
    }

    public function test_default_pagination_counts_only_published_products(): void
    {
        $this->createProducts(16);
        $this->createProduct(['status' => 'draft']);

        $this->getJson('/api/products')
            ->assertOk()
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('pagination', [
                'current_page' => 1,
                'per_page' => 15,
                'total' => 16,
                'last_page' => 2,
                'from' => 1,
                'to' => 15,
            ]);
    }

    public function test_custom_pagination_returns_the_correct_second_page(): void
    {
        $products = $this->createProducts(5);
        $first = $this->getJson('/api/products?per_page=2')->assertOk();
        $second = $this->getJson('/api/products?per_page=2&page=2')->assertOk();

        $this->assertSame([$products[4]->id, $products[3]->id], array_column($first->json('data'), 'id'));
        $this->assertSame([$products[2]->id, $products[1]->id], array_column($second->json('data'), 'id'));
        $second->assertJsonPath('pagination', [
            'current_page' => 2,
            'per_page' => 2,
            'total' => 5,
            'last_page' => 3,
            'from' => 3,
            'to' => 4,
        ]);
    }

    public function test_one_hundred_products_per_page_is_allowed(): void
    {
        $this->createProducts(101);

        $this->getJson('/api/products?per_page=100')
            ->assertOk()
            ->assertJsonCount(100, 'data')
            ->assertJsonPath('pagination.per_page', 100)
            ->assertJsonPath('pagination.total', 101)
            ->assertJsonPath('pagination.last_page', 2);
    }

    public function test_products_are_sorted_by_creation_time_then_id_descending(): void
    {
        $older = $this->createProduct(['created_at' => '2026-01-01 00:00:00']);
        $newer = $this->createProduct(['created_at' => '2026-01-02 00:00:00']);
        $tied = $this->createProduct(['created_at' => '2026-01-02 00:00:00']);
        $highestIdButOldest = $this->createProduct(['created_at' => '2025-12-31 00:00:00']);

        $response = $this->getJson('/api/products')->assertOk();

        $this->assertSame(
            [$tied->id, $newer->id, $older->id, $highestIdButOldest->id],
            array_column($response->json('data'), 'id'),
        );
    }

    #[DataProvider('invalidPagination')]
    public function test_invalid_pagination_returns_json_validation_errors(string $query, string $field): void
    {
        $this->getJson('/api/products?'.$query)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    }

    public static function invalidPagination(): array
    {
        return [
            ['per_page=0', 'per_page'],
            ['per_page=-1', 'per_page'],
            ['per_page=101', 'per_page'],
            ['per_page=1.5', 'per_page'],
            ['per_page=abc', 'per_page'],
            ['per_page=', 'per_page'],
            ['per_page[]=1', 'per_page'],
            ['page=0', 'page'],
            ['page=-1', 'page'],
            ['page=100001', 'page'],
            ['page=9223372036854775807', 'page'],
            ['page=1.5', 'page'],
            ['page=abc', 'page'],
            ['page=', 'page'],
            ['page[]=1', 'page'],
        ];
    }

    public function test_validation_errors_are_json_without_an_accept_header(): void
    {
        $this->get('/api/products?page=0')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('page');
    }

    public function test_no_published_products_returns_empty_data_and_metadata(): void
    {
        $this->createProduct(['status' => 'draft']);
        $this->createProduct(['status' => 'archived']);

        $this->getJson('/api/products')
            ->assertOk()
            ->assertExactJson([
                'data' => [],
                'pagination' => [
                    'current_page' => 1,
                    'per_page' => 15,
                    'total' => 0,
                    'last_page' => 1,
                    'from' => null,
                    'to' => null,
                ],
            ]);
    }

    public function test_a_page_beyond_the_last_page_returns_empty_data(): void
    {
        $this->createProduct();

        $this->getJson('/api/products?page=2')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('pagination', [
                'current_page' => 2,
                'per_page' => 15,
                'total' => 1,
                'last_page' => 1,
                'from' => null,
                'to' => null,
            ]);
    }

    #[DataProvider('visibilityCases')]
    public function test_listing_requires_a_published_product_and_an_active_seller(string $productStatus, string $sellerStatus, bool $visible): void
    {
        $product = $this->createProduct(['status' => $productStatus], $sellerStatus);

        $response = $this->getJson('/api/products?status='.$productStatus.'&seller_status='.$sellerStatus)->assertOk();

        $this->assertSame($visible ? [[
            'id' => $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'price' => '150000',
        ]] : [], $response->json('data'));
        $response->assertJsonPath('pagination', [
            'current_page' => 1, 'per_page' => 15, 'total' => $visible ? 1 : 0,
            'last_page' => 1, 'from' => $visible ? 1 : null, 'to' => $visible ? 1 : null,
        ]);
    }

    public function test_mixed_seller_statuses_do_not_affect_visible_sorting_or_pagination(): void
    {
        $oldest = $this->createProduct(['created_at' => '2026-01-01 00:00:00']);
        $newest = $this->createProduct(['created_at' => '2026-01-03 00:00:00']);
        $middle = $this->createProduct(['created_at' => '2026-01-02 00:00:00']);
        $tied = $this->createProduct(['created_at' => '2026-01-02 00:00:00']);
        $this->createProduct(['created_at' => '2026-01-04 00:00:00'], 'pending');
        $this->createProduct(['created_at' => '2026-01-05 00:00:00'], 'suspended');
        $this->createProduct(['status' => 'draft', 'created_at' => '2026-01-06 00:00:00']);
        $this->createProduct(['status' => 'archived', 'created_at' => '2026-01-07 00:00:00']);

        $first = $this->getJson('/api/products?per_page=2')->assertOk();
        $second = $this->getJson('/api/products?per_page=2&page=2')->assertOk();
        $this->assertSame([$newest->id, $tied->id], array_column($first->json('data'), 'id'));
        $this->assertSame([$middle->id, $oldest->id], array_column($second->json('data'), 'id'));
        $first->assertJsonPath('pagination', [
            'current_page' => 1, 'per_page' => 2, 'total' => 4,
            'last_page' => 2, 'from' => 1, 'to' => 2,
        ]);
        $second->assertJsonPath('pagination', [
            'current_page' => 2, 'per_page' => 2, 'total' => 4,
            'last_page' => 2, 'from' => 3, 'to' => 4,
        ]);
        $this->getJson('/api/products?per_page=2&page=3')->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('pagination', [
                'current_page' => 3, 'per_page' => 2, 'total' => 4,
                'last_page' => 2, 'from' => null, 'to' => null,
            ]);
    }

    public static function visibilityCases(): array
    {
        $cases = [];

        foreach (['published', 'draft', 'archived'] as $productStatus) {
            foreach (['active', 'pending', 'suspended'] as $sellerStatus) {
                $cases[$productStatus.' / '.$sellerStatus] = [
                    $productStatus, $sellerStatus,
                    $productStatus === 'published' && $sellerStatus === 'active',
                ];
            }
        }

        return $cases;
    }

    private function createProduct(array $attributes = [], string $sellerStatus = 'active'): Product
    {
        $seller = Seller::query()->firstOrCreate(['email' => 'products-test-'.$sellerStatus.'@example.test'], [
            'type' => 'store',
            'name' => 'Test seller',
            'password' => 'test-password',
            'status' => $sellerStatus,
        ]);

        $product = new Product;
        $product->forceFill(array_merge([
            'seller_id' => $seller->id,
            'category_id' => null,
            'name' => 'Test product',
            'slug' => 'product-'.Product::query()->count(),
            'description' => null,
            'price' => 150000,
            'stock' => 0,
            'is_verified_factory' => false,
            'status' => 'published',
            'created_at' => '2026-01-01 00:00:00',
        ], $attributes))->save();

        return $product;
    }

    private function createProducts(int $count): array
    {
        $products = [];

        for ($index = 0; $index < $count; $index++) {
            $products[] = $this->createProduct();
        }

        return $products;
    }
}
