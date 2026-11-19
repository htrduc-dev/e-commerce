<?php

namespace Tests\MySql;

use App\Models\Product;
use App\Models\Seller;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\MySqlSafety;

#[Group('mysql')]
class ProductPriceBigIntTest extends MySqlTestCase
{
    #[DataProvider('unsignedBigintPrices')]
    public function test_listing_preserves_exact_price_strings(string $price): void
    {
        $product = $this->createProduct(['price' => $price]);
        $this->assertStoredPrice($product, $price);
        $response = $this->getJson('/api/products')->assertOk();
        $this->assertSame([$this->publicFields($product, $price)], $response->json('data'));
    }

    #[DataProvider('unsignedBigintPrices')]
    public function test_details_preserve_exact_price_strings(string $price): void
    {
        $product = $this->createProduct(['price' => $price]);
        $this->assertStoredPrice($product, $price);
        $response = $this->getJson('/api/products/'.$product->id)->assertOk();
        $this->assertSame(['data' => $this->publicFields($product, $price)], $response->json());
    }

    public static function unsignedBigintPrices(): array
    {
        return [
            'zero' => ['0'],
            'above JavaScript safe integer' => ['9007199254740993'],
            'signed maximum' => ['9223372036854775807'],
            'signed maximum plus one' => ['9223372036854775808'],
            'unsigned maximum' => ['18446744073709551615'],
        ];
    }

    public function test_guest_listing_only_exposes_published_public_fields(): void
    {
        $published = $this->createProduct(['description' => 'Private description', 'stock' => 42]);
        $this->createProduct(['status' => 'draft']);
        $this->createProduct(['status' => 'archived']);
        $response = $this->getJson('/api/products?status=draft')->assertOk();
        $this->assertSame([$this->publicFields($published)], $response->json('data'));
        $response->assertJsonPath('pagination.total', 1);
    }

    public function test_guest_details_only_expose_public_fields(): void
    {
        $product = $this->createProduct(['description' => 'Private description', 'stock' => 42]);
        $response = $this->getJson('/api/products/'.$product->id)->assertOk();
        $this->assertSame(['data' => $this->publicFields($product)], $response->json());
    }

    #[DataProvider('unpublishedStatuses')]
    public function test_unpublished_details_are_not_found(string $status): void
    {
        $product = $this->createProduct(['status' => $status]);
        $this->getJson('/api/products/'.$product->id.'?status=published')
            ->assertNotFound()->assertJsonMissingPath('data');
    }

    public static function unpublishedStatuses(): array
    {
        return [['draft'], ['archived']];
    }

    public function test_missing_details_are_not_found(): void
    {
        $this->getJson('/api/products/999999')->assertNotFound();
    }

    public function test_non_numeric_details_are_not_found(): void
    {
        $this->getJson('/api/products/not-an-id')->assertNotFound();
    }

    public function test_missing_details_are_json_without_accept_header(): void
    {
        $this->get('/api/products/999999')->assertNotFound()
            ->assertHeader('Content-Type', 'application/json');
    }

    public function test_listing_pagination_and_sorting_preserve_unsigned_maximum(): void
    {
        // ID order deliberately conflicts with creation-time order.
        $newest = $this->createProduct(['created_at' => '2026-01-03 00:00:00']);
        $newer = $this->createProduct(['created_at' => '2026-01-02 00:00:00']);
        $tied = $this->createProduct(['created_at' => '2026-01-02 00:00:00']);
        $maximum = $this->createProduct(['price' => '18446744073709551615', 'created_at' => '2026-01-01 00:00:00']);
        $this->createProduct(['status' => 'draft']);
        $this->createProduct(['status' => 'archived']);
        $this->createProduct(['created_at' => '2026-01-04 00:00:00'], 'pending');
        $this->createProduct(['created_at' => '2026-01-05 00:00:00'], 'suspended');
        $first = $this->getJson('/api/products?per_page=2')->assertOk();
        $this->assertSame([$newest->id, $tied->id], array_column($first->json('data'), 'id'));
        $first->assertJsonPath('pagination', ['current_page' => 1, 'per_page' => 2, 'total' => 4, 'last_page' => 2, 'from' => 1, 'to' => 2]);
        $second = $this->getJson('/api/products?per_page=2&page=2')->assertOk();
        $this->assertSame([$this->publicFields($newer), $this->publicFields($maximum, '18446744073709551615')], $second->json('data'));
        $second->assertJsonPath('pagination', ['current_page' => 2, 'per_page' => 2, 'total' => 4, 'last_page' => 2, 'from' => 3, 'to' => 4]);
    }

    public function test_listing_without_published_products_is_empty(): void
    {
        $this->createProduct(['status' => 'draft']);
        $this->createProduct(['status' => 'archived']);
        $this->getJson('/api/products')->assertOk()->assertExactJson([
            'data' => [],
            'pagination' => ['current_page' => 1, 'per_page' => 15, 'total' => 0, 'last_page' => 1, 'from' => null, 'to' => null],
        ]);
    }

    #[DataProvider('invalidPagination')]
    public function test_invalid_pagination_is_rejected(string $query, string $field): void
    {
        $this->getJson('/api/products?'.$query)->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public static function invalidPagination(): array
    {
        return [['per_page=0', 'per_page'], ['per_page=101', 'per_page'], ['page=0', 'page']];
    }

    #[DataProvider('visibilityCases')]
    public function test_both_endpoints_require_a_published_product_and_an_active_seller(string $productStatus, string $sellerStatus, bool $visible): void
    {
        $price = '18446744073709551615';
        $product = $this->createProduct(['status' => $productStatus, 'price' => $price], $sellerStatus);
        $this->assertStoredPrice($product, $price);

        $listing = $this->getJson('/api/products?status='.$productStatus.'&seller_status='.$sellerStatus)->assertOk();
        $this->assertSame($visible ? [$this->publicFields($product, $price)] : [], $listing->json('data'));
        $listing->assertJsonPath('pagination', [
            'current_page' => 1, 'per_page' => 15, 'total' => $visible ? 1 : 0,
            'last_page' => 1, 'from' => $visible ? 1 : null, 'to' => $visible ? 1 : null,
        ]);
        $details = $this->getJson('/api/products/'.$product->id.'?status='.$productStatus.'&seller_status='.$sellerStatus);

        if ($visible) {
            $details->assertOk()->assertExactJson(['data' => $this->publicFields($product, $price)]);
        } else {
            $details->assertNotFound()->assertJsonMissingPath('data');
        }
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

    private function assertStoredPrice(Product $product, string $price): void
    {
        $stored = $this->app['db']->connection('mysql')->table('products')
            ->where('id', $product->id)->selectRaw('CAST(price AS CHAR) AS exact_price')->first();
        $this->assertSame($price, $stored->exact_price);
    }

    private function publicFields(Product $product, string $price = '150000'): array
    {
        return ['id' => $product->id, 'name' => $product->name, 'slug' => $product->slug, 'price' => $price];
    }

    private function createProduct(array $attributes = [], string $sellerStatus = 'active'): Product
    {
        $seller = Seller::query()->firstOrCreate(['email' => 'mysql-products-'.$sellerStatus.'@example.test'], [
            'type' => 'store', 'name' => 'MySQL test seller', 'password' => 'test-password', 'status' => $sellerStatus,
        ]);

        $createdAt = $attributes['created_at'] ?? '2026-01-01 00:00:00';
        $updatedAt = $attributes['updated_at'] ?? $createdAt;
        unset($attributes['created_at'], $attributes['updated_at']);
        $product = new Product(array_merge([
            'seller_id' => $seller->id,
            'category_id' => null,
            'name' => 'MySQL test product',
            'slug' => 'mysql-product-'.bin2hex(random_bytes(8)),
            'description' => null,
            'price' => '150000',
            'stock' => 0,
            'status' => 'published',
        ], $attributes));
        $product->created_at = $createdAt;
        $product->updated_at = $updatedAt;
        $product->save();
        $stored = MySqlSafety::connection($this->app)->table('products')->where('id', $product->id)->first(['created_at', 'updated_at']);
        $this->assertSame($createdAt, $stored->created_at);
        $this->assertSame($updatedAt, $stored->updated_at);

        return $product;
    }
}
