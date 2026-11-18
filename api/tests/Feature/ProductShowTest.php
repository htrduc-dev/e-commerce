<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductShowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', $this->app->environment());
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame('sqlite', config('database.connections.sqlite.driver'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertEmpty(config('database.connections.sqlite.url'));
        $this->assertSame('sqlite', (new Product)->getConnection()->getDriverName());
        $this->assertSame(':memory:', (new Product)->getConnection()->getDatabaseName());
        $this->assertSame('', DB::selectOne('PRAGMA database_list')->file);

        // Create only an in-memory fixture; never invoke application migrations.
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->unsignedBigInteger('price');
            $table->string('status');
            $table->text('description')->nullable();
            $table->unsignedInteger('stock')->default(0);
            $table->timestamps();
        });
    }

    public function test_guest_receives_only_public_fields_for_a_published_product(): void
    {
        $product = $this->createProduct(['description' => 'Private details', 'stock' => 42]);

        $this->getJson('/api/products/'.$product->id)
            ->assertOk()
            ->assertExactJson(['data' => [
                'id' => $product->id,
                'name' => $product->name,
                'slug' => $product->slug,
                'price' => '150000',
            ]]);
    }

    #[DataProvider('prices')]
    public function test_price_is_preserved_as_an_exact_json_string(string $price): void
    {
        $product = $this->createProduct(['price' => $price]);

        $this->getJson('/api/products/'.$product->id)
            ->assertOk()
            ->assertJsonPath('data.price', $price);
    }

    public static function prices(): array
    {
        return [
            'zero' => ['0'],
            'above JavaScript safe integer' => ['9007199254740993'],
            'signed bigint maximum' => ['9223372036854775807'],
        ];
    }

    #[DataProvider('unpublishedStatuses')]
    public function test_unpublished_products_return_404(string $status): void
    {
        $product = $this->createProduct(['status' => $status]);

        $this->getJson('/api/products/'.$product->id.'?status=published')
            ->assertNotFound()
            ->assertJsonMissingPath('data');
    }

    public static function unpublishedStatuses(): array
    {
        return [
            'draft' => ['draft'],
            'archived' => ['archived'],
        ];
    }

    public function test_missing_product_returns_404(): void
    {
        $this->getJson('/api/products/999')->assertNotFound();
    }

    public function test_non_numeric_product_id_returns_404(): void
    {
        $this->getJson('/api/products/not-an-id')->assertNotFound();
    }

    public function test_missing_product_returns_json_without_an_accept_header(): void
    {
        $this->get('/api/products/999')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json');
    }

    public function test_listing_preserves_its_public_fields_filter_order_and_pagination(): void
    {
        $older = $this->createProduct();
        $newer = $this->createProduct(['price' => '9007199254740993']);
        $this->createProduct(['status' => 'draft']);
        $this->createProduct(['status' => 'archived']);

        $this->getJson('/api/products?per_page=1&page=1')
            ->assertOk()
            ->assertExactJson([
                'data' => [[
                    'id' => $newer->id,
                    'name' => $newer->name,
                    'slug' => $newer->slug,
                    'price' => '9007199254740993',
                ]],
                'pagination' => [
                    'current_page' => 1,
                    'per_page' => 1,
                    'total' => 2,
                    'last_page' => 2,
                    'from' => 1,
                    'to' => 1,
                ],
            ]);

        $this->getJson('/api/products?per_page=1&page=2')
            ->assertOk()
            ->assertJsonPath('data.0.id', $older->id);

        $this->getJson('/api/products?per_page=0')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }

    private function createProduct(array $attributes = []): Product
    {
        $product = new Product;
        $product->forceFill(array_merge([
            'name' => 'Test product',
            'slug' => 'product-'.Product::query()->count(),
            'price' => '150000',
            'status' => 'published',
            'created_at' => '2026-01-01 00:00:00',
        ], $attributes))->save();

        return $product;
    }
}
