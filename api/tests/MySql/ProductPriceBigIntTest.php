<?php

namespace Tests\MySql;

use App\Models\Product;
use App\Models\Seller;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('mysql')]
class ProductPriceBigIntTest extends TestCase
{
    protected function setUp(): void
    {
        // Skip before parent setup can bootstrap Laravel or touch a database.
        if (getenv('ECOMMERCE_MYSQL_PRICE_TEST_OPT_IN') !== '1') {
            $this->markTestSkipped('Isolated ephemeral MySQL harness is not provisioned; see docs/testing/mysql-product-price.md.');
        }

        $application = $this->createApplicationFromVerifiedEphemeralHarness();

        if ($application === null) {
            $this->markTestSkipped('Verified ephemeral MySQL harness adapter is not implemented; refusing Laravel bootstrap and database access.');
        }

        $this->app = $application;

        parent::setUp();
    }

    protected function createApplicationFromVerifiedEphemeralHarness(): ?Application
    {
        // Extension point: implement only after reviewing the isolation checks
        // and lifecycle in docs/testing/mysql-product-price.md. Never fall back
        // to the ordinary application bootstrap or shared database configuration.
        return null;
    }

    #[DataProvider('unsignedBigintPrices')]
    public function test_unsigned_bigint_prices_are_exact_json_strings(string $price): void
    {
        $seller = Seller::query()->create([
            'type' => 'store',
            'name' => 'Unsigned price test seller',
            'email' => 'unsigned-price-'.bin2hex(random_bytes(8)).'@example.test',
            'password' => 'test-password',
            'status' => 'suspended',
        ]);

        $product = Product::query()->create([
            'seller_id' => $seller->id,
            'name' => 'Unsigned price test product',
            'slug' => 'unsigned-price-'.$price,
            'price' => $price,
            'status' => 'published',
        ]);

        $this->getJson('/api/products')
            ->assertOk()
            ->assertJsonPath('data', [[
                'id' => $product->id,
                'name' => $product->name,
                'slug' => $product->slug,
                'price' => $price,
            ]]);
    }

    public static function unsignedBigintPrices(): array
    {
        return [
            'signed bigint maximum plus one' => ['9223372036854775808'],
            'unsigned bigint maximum' => ['18446744073709551615'],
        ];
    }
}
