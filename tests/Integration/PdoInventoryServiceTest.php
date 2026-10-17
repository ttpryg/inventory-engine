<?php

namespace Ttpryg\InventoryEngine\Tests\Integration;

use DateTimeImmutable;
use RuntimeException;
use Ttpryg\InventoryEngine\Entities\InventoryStock;
use Ttpryg\InventoryEngine\Enums\ReservationStatus;
use Ttpryg\InventoryEngine\Exceptions\InsufficientStockException;
use Ttpryg\InventoryEngine\Exceptions\ReservationExpiredException;
use Ttpryg\InventoryEngine\Repositories\PdoInventoryLocationRepository;
use Ttpryg\InventoryEngine\Repositories\PdoInventoryStockRepository;
use Ttpryg\InventoryEngine\Repositories\PdoStockMovementRepository;
use Ttpryg\InventoryEngine\Repositories\PdoStockReservationRepository;
use Ttpryg\InventoryEngine\Repositories\PdoTransactionManager;
use Ttpryg\InventoryEngine\Services\InventoryService;
use Ttpryg\InventoryEngine\Tests\Support\FailingStockMovementRepository;

class PdoInventoryServiceTest extends PdoTestCase
{
    private InventoryService $service;

    private PdoInventoryStockRepository $stockRepo;

    private PdoStockReservationRepository $reservationRepo;

    private PdoStockMovementRepository $movementRepo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stockRepo = new PdoInventoryStockRepository($this->pdo);
        $this->reservationRepo = new PdoStockReservationRepository($this->pdo);
        $this->movementRepo = new PdoStockMovementRepository($this->pdo);

        $this->service = new InventoryService(
            new PdoInventoryLocationRepository($this->pdo),
            $this->stockRepo,
            $this->reservationRepo,
            $this->movementRepo,
            null,
            new PdoTransactionManager($this->pdo)
        );
    }

    public function test_reserve_and_commit_flow_with_transactions(): void
    {
        $this->service->initializeStock(
            id: 'stk-1',
            locationType: 'warehouse',
            locationId: 'wh-jkt',
            productId: 'prod-100',
            initialQuantity: 50
        );

        $reservation = $this->service->reserveStock(
            reservationId: 'res-1',
            locationType: 'warehouse',
            locationId: 'wh-jkt',
            productId: 'prod-100',
            quantity: 10,
            referenceType: 'order',
            referenceId: 'order-99'
        );

        $stock = $this->stockRepo->findById('stk-1');
        $this->assertNotNull($stock);
        $this->assertEquals(40, $stock->getQuantityAvailable());
        $this->assertEquals(10, $stock->quantityReserved);
        $this->assertEquals(ReservationStatus::ACTIVE, $reservation->status);

        $this->service->commitReservation('res-1');

        $stock = $this->stockRepo->findById('stk-1');
        $this->assertNotNull($stock);
        $this->assertEquals(40, $stock->quantityOnHand);
        $this->assertEquals(0, $stock->quantityReserved);
        $this->assertEquals(ReservationStatus::COMMITTED, $this->reservationRepo->findById('res-1')?->status);
        $this->assertCount(3, $this->movementRepo->findByStock('stk-1'));
    }

    public function test_reserve_insufficient_stock_throws(): void
    {
        $this->service->initializeStock(
            id: 'stk-1',
            locationType: 'warehouse',
            locationId: 'wh-jkt',
            productId: 'prod-100',
            initialQuantity: 5
        );

        try {
            $this->service->reserveStock(
                reservationId: 'res-1',
                locationType: 'warehouse',
                locationId: 'wh-jkt',
                productId: 'prod-100',
                quantity: 10,
                referenceType: 'order',
                referenceId: 'order-99'
            );
            $this->fail('Expected InsufficientStockException');
        } catch (InsufficientStockException $exception) {
            $this->assertStringContainsString('Insufficient available stock', $exception->getMessage());
        }

        $stock = $this->stockRepo->findById('stk-1');
        $this->assertNotNull($stock);
        $this->assertEquals(5, $stock->quantityOnHand);
        $this->assertEquals(0, $stock->quantityReserved);
        $this->assertNull($this->reservationRepo->findById('res-1'));
        $this->assertFalse($this->pdo->inTransaction());
    }

    public function test_transfer_auto_initializes_target(): void
    {
        $this->service->initializeStock(
            id: 'stk-src',
            locationType: 'warehouse',
            locationId: 'wh-jkt',
            productId: 'prod-300',
            initialQuantity: 100
        );

        $this->service->transferStock(
            productId: 'prod-300',
            fromLocationType: 'warehouse',
            fromLocationId: 'wh-jkt',
            toLocationType: 'store',
            toLocationId: 'store-a',
            quantity: 30,
            note: 'Stocking up Store A'
        );

        $sourceStock = $this->stockRepo->findByLocationAndProduct('warehouse', 'wh-jkt', 'prod-300');
        $this->assertNotNull($sourceStock);
        $this->assertEquals(70, $sourceStock->quantityOnHand);

        $targetStock = $this->stockRepo->findByLocationAndProduct('store', 'store-a', 'prod-300');
        $this->assertNotNull($targetStock);
        $this->assertEquals(30, $targetStock->quantityOnHand);
    }

    public function test_save_is_upsert_for_existing_stock(): void
    {
        $stock = new InventoryStock(
            id: 'stk-upsert',
            locationType: 'store',
            locationId: 'store-a',
            productId: 'prod-100',
            quantityOnHand: 50,
            quantityReserved: 5,
            minStockAlert: 10
        );

        $this->stockRepo->save($stock);

        $stock->quantityOnHand = 80;
        $stock->quantityReserved = 0;
        $this->stockRepo->save($stock);

        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM inventory_stocks WHERE id = 'stk-upsert'")->fetchColumn();
        $this->assertEquals(1, $count);

        $fetched = $this->stockRepo->findById('stk-upsert');
        $this->assertNotNull($fetched);
        $this->assertEquals(80, $fetched->quantityOnHand);
        $this->assertEquals(0, $fetched->quantityReserved);
    }

    public function test_expire_reservation_flow(): void
    {
        $this->service->initializeStock(
            id: 'stk-1',
            locationType: 'warehouse',
            locationId: 'wh-jkt',
            productId: 'prod-100',
            initialQuantity: 50
        );

        $this->service->reserveStock(
            reservationId: 'res-1',
            locationType: 'warehouse',
            locationId: 'wh-jkt',
            productId: 'prod-100',
            quantity: 10,
            referenceType: 'order',
            referenceId: 'order-99',
            ttlMinutes: -1
        );

        $count = $this->service->processExpiredReservations();

        $this->assertEquals(1, $count);
        $this->assertEquals(ReservationStatus::EXPIRED, $this->reservationRepo->findById('res-1')?->status);

        $stock = $this->stockRepo->findById('stk-1');
        $this->assertNotNull($stock);
        $this->assertEquals(0, $stock->quantityReserved);
        $this->assertEquals(50, $stock->getQuantityAvailable());
    }

    public function test_commit_expired_reservation_throws_and_keeps_stock(): void
    {
        $this->service->initializeStock(
            id: 'stk-1',
            locationType: 'warehouse',
            locationId: 'wh-jkt',
            productId: 'prod-100',
            initialQuantity: 50
        );

        $this->service->reserveStock(
            reservationId: 'res-1',
            locationType: 'warehouse',
            locationId: 'wh-jkt',
            productId: 'prod-100',
            quantity: 10,
            referenceType: 'order',
            referenceId: 'order-99',
            ttlMinutes: -1
        );

        try {
            $this->service->commitReservation('res-1');
            $this->fail('Expected ReservationExpiredException');
        } catch (ReservationExpiredException $exception) {
            $this->assertStringContainsString('has expired', $exception->getMessage());
        }

        $stock = $this->stockRepo->findById('stk-1');
        $this->assertNotNull($stock);
        $this->assertEquals(50, $stock->quantityOnHand);
        $this->assertEquals(10, $stock->quantityReserved);
        $this->assertEquals(ReservationStatus::ACTIVE, $this->reservationRepo->findById('res-1')?->status);
        $this->assertCount(2, $this->movementRepo->findByStock('stk-1'));
    }

    public function test_reservation_expires_at_is_in_the_future_for_normal_ttl(): void
    {
        $this->service->initializeStock(
            id: 'stk-1',
            locationType: 'warehouse',
            locationId: 'wh-jkt',
            productId: 'prod-100',
            initialQuantity: 50
        );

        $reservation = $this->service->reserveStock(
            reservationId: 'res-1',
            locationType: 'warehouse',
            locationId: 'wh-jkt',
            productId: 'prod-100',
            quantity: 10,
            referenceType: 'order',
            referenceId: 'order-99',
            ttlMinutes: 15
        );

        $this->assertFalse($reservation->isExpired());
        $this->assertGreaterThan(new DateTimeImmutable, $reservation->expiresAt);
    }

    public function test_transfer_rolls_back_when_recording_fails_midway(): void
    {
        $this->stockRepo->save(new InventoryStock(
            id: 'stk-src',
            locationType: 'warehouse',
            locationId: 'wh-jkt',
            productId: 'prod-300',
            quantityOnHand: 100
        ));

        $service = new InventoryService(
            new PdoInventoryLocationRepository($this->pdo),
            $this->stockRepo,
            $this->reservationRepo,
            new FailingStockMovementRepository,
            null,
            new PdoTransactionManager($this->pdo)
        );

        try {
            $service->transferStock(
                productId: 'prod-300',
                fromLocationType: 'warehouse',
                fromLocationId: 'wh-jkt',
                toLocationType: 'store',
                toLocationId: 'store-a',
                quantity: 30
            );
            $this->fail('Expected RuntimeException from failing movement repository');
        } catch (RuntimeException $exception) {
            $this->assertEquals('Simulated stock movement failure', $exception->getMessage());
        }

        $sourceStock = $this->stockRepo->findByLocationAndProduct('warehouse', 'wh-jkt', 'prod-300');
        $this->assertNotNull($sourceStock);
        $this->assertEquals(100, $sourceStock->quantityOnHand);

        $targetStock = $this->stockRepo->findByLocationAndProduct('store', 'store-a', 'prod-300');
        $this->assertNull($targetStock);

        $this->assertFalse($this->pdo->inTransaction());
    }

    public function test_reserve_rolls_back_when_recording_fails(): void
    {
        $this->stockRepo->save(new InventoryStock(
            id: 'stk-1',
            locationType: 'warehouse',
            locationId: 'wh-jkt',
            productId: 'prod-100',
            quantityOnHand: 50
        ));

        $service = new InventoryService(
            new PdoInventoryLocationRepository($this->pdo),
            $this->stockRepo,
            $this->reservationRepo,
            new FailingStockMovementRepository,
            null,
            new PdoTransactionManager($this->pdo)
        );

        try {
            $service->reserveStock(
                reservationId: 'res-1',
                locationType: 'warehouse',
                locationId: 'wh-jkt',
                productId: 'prod-100',
                quantity: 10,
                referenceType: 'order',
                referenceId: 'order-99'
            );
            $this->fail('Expected RuntimeException from failing movement repository');
        } catch (RuntimeException $exception) {
            $this->assertEquals('Simulated stock movement failure', $exception->getMessage());
        }

        $stock = $this->stockRepo->findById('stk-1');
        $this->assertNotNull($stock);
        $this->assertEquals(50, $stock->quantityOnHand);
        $this->assertEquals(0, $stock->quantityReserved);
        $this->assertNull($this->reservationRepo->findById('res-1'));
        $this->assertFalse($this->pdo->inTransaction());
    }
}
