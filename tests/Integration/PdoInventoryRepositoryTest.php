<?php

namespace Ttpryg\InventoryEngine\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Ttpryg\InventoryEngine\Entities\InventoryLocation;
use Ttpryg\InventoryEngine\Entities\InventoryStock;
use Ttpryg\InventoryEngine\Repositories\PdoInventoryLocationRepository;
use Ttpryg\InventoryEngine\Repositories\PdoInventoryStockRepository;

class PdoInventoryRepositoryTest extends TestCase
{
    private PDO $pdo;

    private PdoInventoryLocationRepository $locationRepo;

    private PdoInventoryStockRepository $stockRepo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $schema = file_get_contents(__DIR__.'/../../database/schema.sql');
        $this->pdo->exec($schema);

        $this->locationRepo = new PdoInventoryLocationRepository($this->pdo);
        $this->stockRepo = new PdoInventoryStockRepository($this->pdo);
    }

    public function test_save_and_find_location(): void
    {
        $location = new InventoryLocation(
            id: 'loc-sqlite-1',
            locationType: 'warehouse',
            locationId: 'wh-main',
            name: 'Gudang Pusat',
            code: 'WH-MAIN',
            address: 'Jl. Sudirman No. 1'
        );

        $this->locationRepo->save($location);

        $fetchedByCode = $this->locationRepo->findByCode('WH-MAIN');
        $this->assertNotNull($fetchedByCode);
        $this->assertEquals('loc-sqlite-1', $fetchedByCode->id);
        $this->assertEquals('Gudang Pusat', $fetchedByCode->name);

        $fetchedByLocation = $this->locationRepo->findByLocation('warehouse', 'wh-main');
        $this->assertNotNull($fetchedByLocation);
        $this->assertEquals('WH-MAIN', $fetchedByLocation->code);
    }

    public function test_save_and_find_stock(): void
    {
        $stock = new InventoryStock(
            id: 'stock-sqlite-1',
            locationType: 'store',
            locationId: 'store-a',
            productId: 'prod-100',
            quantityOnHand: 50,
            quantityReserved: 5,
            minStockAlert: 10
        );

        $this->stockRepo->save($stock);

        $fetched = $this->stockRepo->findByLocationAndProduct('store', 'store-a', 'prod-100');
        $this->assertNotNull($fetched);
        $this->assertEquals(50, $fetched->quantityOnHand);
        $this->assertEquals(5, $fetched->quantityReserved);
        $this->assertEquals(45, $fetched->getQuantityAvailable());
    }
}
