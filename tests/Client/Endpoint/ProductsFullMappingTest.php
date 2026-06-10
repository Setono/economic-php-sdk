<?php

declare(strict_types=1);

namespace Setono\Economic\Client\Endpoint;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Setono\Economic\Client\Client;
use Setono\Economic\Response\Product\Inventory;
use Setono\Economic\Response\Product\Product;
use Setono\Economic\Response\Product\ProductGroup;
use Setono\Economic\Response\Reference\DepartmentalDistribution;
use Setono\Economic\Response\Reference\Unit;
use Setono\Economic\TestDouble\ScriptedHttpClient;
use Webmozart\Assert\Assert;

#[CoversClass(ProductsEndpoint::class)]
#[CoversClass(Product::class)]
#[CoversClass(Inventory::class)]
#[CoversClass(ProductGroup::class)]
#[CoversClass(Unit::class)]
#[CoversClass(DepartmentalDistribution::class)]
final class ProductsFullMappingTest extends TestCase
{
    #[Test]
    public function every_first_level_product_field_maps_to_a_typed_property(): void
    {
        $body = json_encode([
            'productNumber' => 'SKU-5',
            'name' => 'Widget',
            'description' => 'A widget of the finest kind',
            'salesPrice' => 99.95,
            'costPrice' => 12.34,
            'recommendedPrice' => 129.0,
            'barCode' => '5710000000017',
            'barred' => true,
            'lastUpdated' => '2020-02-19T09:18:09Z',
            'inventory' => [
                'available' => 5.0,
                'inStock' => 7.0,
                'orderedByCustomers' => 2.0,
                'orderedFromSuppliers' => 1.0,
                'grossWeight' => 1.25,
                'netWeight' => 1.0,
                'packageVolume' => 0.5,
                'recommendedCostPrice' => 11.0,
                'inventoryLastUpdated' => '2020-02-18T08:00:00Z',
            ],
            'unit' => ['unitNumber' => 1, 'name' => 'kg', 'self' => 'https://restapi.e-conomic.com/units/1'],
            'productGroup' => [
                'productGroupNumber' => 2,
                'name' => 'Widgets',
                'inventoryEnabled' => true,
                'accrual' => ['accountNumber' => 4000, 'accountType' => 'profitAndLoss'],
                'salesAccounts' => 'https://restapi.e-conomic.com/product-groups/2/sales-accounts',
                'self' => 'https://restapi.e-conomic.com/product-groups/2',
            ],
            'departmentalDistribution' => [
                'departmentalDistributionNumber' => 9,
                'distributionType' => 'department',
                'self' => 'https://restapi.e-conomic.com/departmental-distributions/9',
            ],
        ], \JSON_THROW_ON_ERROR);

        $http = new ScriptedHttpClient()
            ->on('https://restapi.e-conomic.com/products/SKU-5', $body)
        ;

        $client = new Client('app', 'agreement', httpClient: $http);
        $product = $client->products()->getByNumber('SKU-5');

        self::assertInstanceOf(Product::class, $product);

        self::assertSame('A widget of the finest kind', $product->description);
        self::assertSame(12.34, $product->costPrice);
        self::assertSame(129.0, $product->recommendedPrice);
        self::assertSame('5710000000017', $product->barCode);
        self::assertTrue($product->barred);
        self::assertNotNull($product->lastUpdated);
        self::assertSame('2020-02-19T09:18:09+00:00', $product->lastUpdated->format(\DateTimeInterface::RFC3339));

        self::assertNotNull($product->inventory);
        self::assertSame(5.0, $product->inventory->available);
        self::assertSame(7.0, $product->inventory->inStock);
        self::assertSame(2.0, $product->inventory->orderedByCustomers);
        self::assertSame(1.0, $product->inventory->orderedFromSuppliers);
        self::assertSame(1.25, $product->inventory->grossWeight);
        self::assertSame(1.0, $product->inventory->netWeight);
        self::assertSame(0.5, $product->inventory->packageVolume);
        self::assertSame(11.0, $product->inventory->recommendedCostPrice);
        self::assertNotNull($product->inventory->inventoryLastUpdated);
        self::assertSame('2020-02-18T08:00:00+00:00', $product->inventory->inventoryLastUpdated->format(\DateTimeInterface::RFC3339));

        self::assertNotNull($product->unit);
        self::assertSame(1, $product->unit->unitNumber);
        self::assertSame('kg', $product->unit->name);
        self::assertSame('https://restapi.e-conomic.com/units/1', $product->unit->self);

        self::assertNotNull($product->productGroup);
        self::assertSame(2, $product->productGroup->productGroupNumber);
        self::assertSame('Widgets', $product->productGroup->name);
        self::assertTrue($product->productGroup->inventoryEnabled);
        self::assertSame('https://restapi.e-conomic.com/product-groups/2', $product->productGroup->self);

        self::assertNotNull($product->departmentalDistribution);
        self::assertSame(9, $product->departmentalDistribution->departmentalDistributionNumber);
        self::assertSame('department', $product->departmentalDistribution->distributionType);

        // `productGroup.accrual` is deliberately untyped — reachable through raw only.
        $productGroupRaw = $product->raw['productGroup'];
        Assert::isArray($productGroupRaw);
        self::assertSame(['accountNumber' => 4000, 'accountType' => 'profitAndLoss'], $productGroupRaw['accrual']);
    }

    #[Test]
    public function absent_inventory_fields_keep_zero_defaults_for_counts_and_null_for_master_data(): void
    {
        $http = new ScriptedHttpClient()
            ->on(
                'https://restapi.e-conomic.com/products/SKU-5',
                '{"productNumber":"SKU-5","inventory":{}}',
            )
        ;

        $client = new Client('app', 'agreement', httpClient: $http);
        $product = $client->products()->getByNumber('SKU-5');

        self::assertInstanceOf(Product::class, $product);
        self::assertNotNull($product->inventory);

        // The historical stock counts default to 0 when absent…
        self::assertSame(0.0, $product->inventory->available);
        self::assertSame(0.0, $product->inventory->inStock);
        self::assertSame(0.0, $product->inventory->orderedByCustomers);
        self::assertSame(0.0, $product->inventory->orderedFromSuppliers);

        // …while master-data fields default to null, because 0 is a meaningful weight/volume/price.
        self::assertNull($product->inventory->grossWeight);
        self::assertNull($product->inventory->netWeight);
        self::assertNull($product->inventory->packageVolume);
        self::assertNull($product->inventory->recommendedCostPrice);
        self::assertNull($product->inventory->inventoryLastUpdated);
    }
}
