<?php

declare(strict_types=1);

namespace Ttpryg\InventoryEngine\Tests\Integration;

use PDO;
use Ttpryg\InventoryEngine\Exceptions\InsufficientStockException;
use Ttpryg\InventoryEngine\Repositories\PdoInventoryLocationRepository;
use Ttpryg\InventoryEngine\Repositories\PdoInventoryStockRepository;
use Ttpryg\InventoryEngine\Repositories\PdoStockMovementRepository;
use Ttpryg\InventoryEngine\Repositories\PdoStockReservationRepository;
use Ttpryg\InventoryEngine\Repositories\PdoTransactionManager;
use Ttpryg\InventoryEngine\Services\InventoryService;

class PdoConcurrencyTest extends PdoTestCase
{
    private PDO $pdo2;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Concurrency test requires MySQL/MariaDB (SELECT ... FOR UPDATE row locking)');
        }

        $this->pdo2 = $this->createPdo();
    }

    private function makeService(PDO $pdo): InventoryService
    {
        return new InventoryService(
            new PdoInventoryLocationRepository($pdo),
            new PdoInventoryStockRepository($pdo),
            new PdoStockReservationRepository($pdo),
            new PdoStockMovementRepository($pdo),
            null,
            new PdoTransactionManager($pdo)
        );
    }

    public function test_concurrent_reserves_do_not_oversell(): void
    {
        $service1 = $this->makeService($this->pdo);
        $service2 = $this->makeService($this->pdo2);

        $service1->initializeStock(
            id: 'stk-1',
            locationType: 'warehouse',
            locationId: 'wh-jkt',
            productId: 'prod-100',
            initialQuantity: 10
        );

        $this->pdo->beginTransaction();
        $service1->reserveStock(
            reservationId: 'res-1',
            locationType: 'warehouse',
            locationId: 'wh-jkt',
            productId: 'prod-100',
            quantity: 8,
            referenceType: 'order',
            referenceId: 'order-99'
        );

        $this->pdo2->exec('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            $service2->reserveStock(
                reservationId: 'res-2',
                locationType: 'warehouse',
                locationId: 'wh-jkt',
                productId: 'prod-100',
                quantity: 5,
                referenceType: 'order',
                referenceId: 'order-100'
            );
            $this->fail('Expected lock wait timeout while first transaction holds the row lock');
        } catch (\PDOException $exception) {
            $this->assertStringContainsString('1205', $exception->getMessage());
        }

        $this->pdo->commit();

        try {
            $service2->reserveStock(
                reservationId: 'res-3',
                locationType: 'warehouse',
                locationId: 'wh-jkt',
                productId: 'prod-100',
                quantity: 5,
                referenceType: 'order',
                referenceId: 'order-101'
            );
            $this->fail('Expected InsufficientStockException — oversell prevented');
        } catch (InsufficientStockException $exception) {
            $this->assertEquals(5, $exception->requested);
            $this->assertEquals(2, $exception->available);
        }

        $service2->reserveStock(
            reservationId: 'res-4',
            locationType: 'warehouse',
            locationId: 'wh-jkt',
            productId: 'prod-100',
            quantity: 2,
            referenceType: 'order',
            referenceId: 'order-102'
        );

        $stock = (new PdoInventoryStockRepository($this->pdo2))->findById('stk-1');
        $this->assertNotNull($stock);
        $this->assertEquals(10, $stock->quantityReserved);
        $this->assertEquals(0, $stock->getQuantityAvailable());
    }
}
