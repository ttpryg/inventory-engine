<?php

namespace Ttpryg\InventoryEngine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ttpryg\InventoryEngine\Enums\ReservationStatus;
use Ttpryg\InventoryEngine\Events\LowStockAlertEvent;
use Ttpryg\InventoryEngine\Events\StockAdjustedEvent;
use Ttpryg\InventoryEngine\Events\StockCommittedEvent;
use Ttpryg\InventoryEngine\Events\StockReleasedEvent;
use Ttpryg\InventoryEngine\Events\StockReservedEvent;
use Ttpryg\InventoryEngine\Exceptions\InsufficientStockException;
use Ttpryg\InventoryEngine\Exceptions\InvalidQuantityException;
use Ttpryg\InventoryEngine\Exceptions\ReservationExpiredException;
use Ttpryg\InventoryEngine\Exceptions\ReservationNotActiveException;
use Ttpryg\InventoryEngine\Exceptions\ReservationNotFoundException;
use Ttpryg\InventoryEngine\Exceptions\StockNotFoundException;
use Ttpryg\InventoryEngine\Repositories\MemoryInventoryLocationRepository;
use Ttpryg\InventoryEngine\Repositories\MemoryInventoryStockRepository;
use Ttpryg\InventoryEngine\Repositories\MemoryStockMovementRepository;
use Ttpryg\InventoryEngine\Repositories\MemoryStockReservationRepository;
use Ttpryg\InventoryEngine\Services\InventoryService;
use Ttpryg\InventoryEngine\Tests\Support\SpyEventDispatcher;

class InventoryServiceTest extends TestCase
{
    private InventoryService $service;

    private MemoryInventoryStockRepository $stockRepo;

    private MemoryStockReservationRepository $reservationRepo;

    private MemoryStockMovementRepository $movementRepo;

    private SpyEventDispatcher $eventDispatcher;

    protected function setUp(): void
    {
        $locationRepo = new MemoryInventoryLocationRepository;
        $this->stockRepo = new MemoryInventoryStockRepository;
        $this->reservationRepo = new MemoryStockReservationRepository;
        $this->movementRepo = new MemoryStockMovementRepository;
        $this->eventDispatcher = new SpyEventDispatcher;

        $this->service = new InventoryService(
            $locationRepo,
            $this->stockRepo,
            $this->reservationRepo,
            $this->movementRepo,
            $this->eventDispatcher
        );
    }

    public function test_reserve_and_commit_stock_flow(): void
    {
        $this->service->createLocation(id: 'loc-1', locationType: 'warehouse', locationId: 'wh-jkt', name: 'Gudang Jakarta', code: 'WH-JKT');
        $stock = $this->service->initializeStock(
            id: 'stk-1',
            locationType: 'warehouse',
            locationId: 'wh-jkt',
            productId: 'prod-100',
            initialQuantity: 50
        );

        $this->assertEquals(50, $stock->getQuantityAvailable());

        // Reserve 10 items
        $reservation = $this->service->reserveStock(
            reservationId: 'res-1',
            locationType: 'warehouse',
            locationId: 'wh-jkt',
            productId: 'prod-100',
            quantity: 10,
            referenceType: 'order',
            referenceId: 'order-99'
        );

        $this->assertEquals(40, $stock->getQuantityAvailable());
        $this->assertEquals(10, $stock->quantityReserved);
        $this->assertEquals(ReservationStatus::ACTIVE, $reservation->status);

        // Commit Reservation (payment completed)
        $this->service->commitReservation('res-1');

        $this->assertEquals(40, $stock->quantityOnHand);
        $this->assertEquals(0, $stock->quantityReserved);
        $this->assertEquals(ReservationStatus::COMMITTED, $reservation->status);
    }

    public function test_transfer_stock_between_locations(): void
    {
        $this->service->createLocation(id: 'loc-1', locationType: 'warehouse', locationId: 'wh-jkt', name: 'Gudang Jakarta', code: 'WH-JKT');
        $this->service->createLocation(id: 'loc-2', locationType: 'store', locationId: 'store-a', name: 'Toko A', code: 'STORE-A');

        $sourceStock = $this->service->initializeStock(
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

        $this->assertEquals(70, $sourceStock->quantityOnHand);
    }

    public function test_reserve_stock_rejects_non_positive_quantity(): void
    {
        $this->expectException(InvalidQuantityException::class);

        $this->service->reserveStock(
            reservationId: 'res-1',
            locationType: 'warehouse',
            locationId: 'wh-jkt',
            productId: 'prod-100',
            quantity: 0,
            referenceType: 'order',
            referenceId: 'order-99'
        );
    }

    public function test_reserve_stock_rejects_negative_quantity(): void
    {
        $this->expectException(InvalidQuantityException::class);

        $this->service->reserveStock(
            reservationId: 'res-1',
            locationType: 'warehouse',
            locationId: 'wh-jkt',
            productId: 'prod-100',
            quantity: -5,
            referenceType: 'order',
            referenceId: 'order-99'
        );
    }

    public function test_expire_reservation_releases_reserved_stock(): void
    {
        $stock = $this->service->initializeStock(
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
            ttlMinutes: -1
        );

        $this->service->expireReservation('res-1');

        $this->assertEquals(ReservationStatus::EXPIRED, $reservation->status);
        $this->assertEquals(0, $stock->quantityReserved);
        $this->assertEquals(50, $stock->getQuantityAvailable());

        $movements = $this->movementRepo->findByStock('stk-1');
        $this->assertCount(3, $movements);
        $this->assertEquals('in', $movements[0]->type->value);
        $this->assertEquals('reserve', $movements[1]->type->value);
        $this->assertEquals('release', $movements[2]->type->value);
        $this->assertEquals('Reservation expired', $movements[2]->note);
    }

    public function test_commit_reservation_rejected_when_expired(): void
    {
        $stock = $this->service->initializeStock(
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
            ttlMinutes: -1
        );

        try {
            $this->service->commitReservation('res-1');
            $this->fail('Expected ReservationExpiredException');
        } catch (ReservationExpiredException $exception) {
            $this->assertStringContainsString('has expired', $exception->getMessage());
        }

        $this->assertEquals(ReservationStatus::ACTIVE, $reservation->status);
        $this->assertEquals(10, $stock->quantityReserved);
        $this->assertEquals(50, $stock->quantityOnHand);
    }

    public function test_process_expired_reservations_expires_only_expired(): void
    {
        $stock = $this->service->initializeStock(
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
        $this->service->reserveStock(
            reservationId: 'res-2',
            locationType: 'warehouse',
            locationId: 'wh-jkt',
            productId: 'prod-100',
            quantity: 5,
            referenceType: 'order',
            referenceId: 'order-100',
            ttlMinutes: -1
        );
        $this->service->reserveStock(
            reservationId: 'res-3',
            locationType: 'warehouse',
            locationId: 'wh-jkt',
            productId: 'prod-100',
            quantity: 3,
            referenceType: 'order',
            referenceId: 'order-101',
            ttlMinutes: 15
        );

        $count = $this->service->processExpiredReservations();

        $this->assertEquals(2, $count);
        $this->assertEquals(3, $stock->quantityReserved);
        $this->assertEquals(47, $stock->getQuantityAvailable());
        $this->assertEquals(ReservationStatus::EXPIRED, $this->reservationRepo->findById('res-1')?->status);
        $this->assertEquals(ReservationStatus::EXPIRED, $this->reservationRepo->findById('res-2')?->status);
        $this->assertEquals(ReservationStatus::ACTIVE, $this->reservationRepo->findById('res-3')?->status);
    }

    public function test_expire_nonexistent_reservation_throws(): void
    {
        $this->expectException(ReservationNotFoundException::class);

        $this->service->expireReservation('res-missing');
    }

    public function test_expire_already_released_reservation_is_noop(): void
    {
        $stock = $this->service->initializeStock(
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

        $this->service->releaseReservation('res-1');
        $this->service->expireReservation('res-1');

        $this->assertEquals(ReservationStatus::RELEASED, $reservation->status);
        $this->assertEquals(0, $stock->quantityReserved);
        $this->assertEquals(50, $stock->getQuantityAvailable());
    }

    public function test_reserve_missing_stock_throws_stock_not_found(): void
    {
        $this->expectException(StockNotFoundException::class);

        $this->service->reserveStock(
            reservationId: 'res-1',
            locationType: 'warehouse',
            locationId: 'wh-jkt',
            productId: 'prod-missing',
            quantity: 1,
            referenceType: 'order',
            referenceId: 'order-99'
        );
    }

    public function test_reserve_insufficient_stock_throws_with_payload(): void
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
            $this->assertEquals(10, $exception->requested);
            $this->assertEquals(5, $exception->available);
        }
    }

    public function test_commit_missing_reservation_throws_not_found(): void
    {
        $this->expectException(ReservationNotFoundException::class);

        $this->service->commitReservation('res-missing');
    }

    public function test_commit_released_reservation_throws_not_active(): void
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
            referenceId: 'order-99'
        );
        $this->service->releaseReservation('res-1');

        $this->expectException(ReservationNotActiveException::class);
        $this->service->commitReservation('res-1');
    }

    public function test_adjust_missing_stock_throws_stock_not_found(): void
    {
        $this->expectException(StockNotFoundException::class);

        $this->service->adjustStock(
            locationType: 'warehouse',
            locationId: 'wh-jkt',
            productId: 'prod-missing',
            quantity: 5
        );
    }

    public function test_transfer_missing_source_stock_throws_stock_not_found(): void
    {
        $this->expectException(StockNotFoundException::class);

        $this->service->transferStock(
            productId: 'prod-missing',
            fromLocationType: 'warehouse',
            fromLocationId: 'wh-jkt',
            toLocationType: 'store',
            toLocationId: 'store-a',
            quantity: 10
        );
    }

    public function test_transfer_insufficient_source_stock_throws_insufficient(): void
    {
        $this->service->initializeStock(
            id: 'stk-src',
            locationType: 'warehouse',
            locationId: 'wh-jkt',
            productId: 'prod-300',
            initialQuantity: 5
        );

        try {
            $this->service->transferStock(
                productId: 'prod-300',
                fromLocationType: 'warehouse',
                fromLocationId: 'wh-jkt',
                toLocationType: 'store',
                toLocationId: 'store-a',
                quantity: 10
            );
            $this->fail('Expected InsufficientStockException');
        } catch (InsufficientStockException $exception) {
            $this->assertEquals(10, $exception->requested);
            $this->assertEquals(5, $exception->available);
            $this->assertStringContainsString('source location', $exception->getMessage());
        }
    }

    public function test_release_reservation_happy_path(): void
    {
        $stock = $this->service->initializeStock(
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

        $this->service->releaseReservation('res-1', 'agent-1');

        $this->assertEquals(ReservationStatus::RELEASED, $reservation->status);
        $this->assertEquals(0, $stock->quantityReserved);
        $this->assertEquals(50, $stock->getQuantityAvailable());

        $movements = $this->movementRepo->findByStock('stk-1');
        $this->assertCount(3, $movements);
        $this->assertEquals('release', $movements[2]->type->value);
        $this->assertEquals('agent-1', $movements[2]->actorId);

        $this->assertTrue($this->eventDispatcher->dispatched(StockReleasedEvent::class));
    }

    public function test_release_committed_reservation_is_silent_noop(): void
    {
        $stock = $this->service->initializeStock(
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
        $this->service->commitReservation('res-1');
        $this->eventDispatcher->reset();

        $this->service->releaseReservation('res-1');

        $this->assertEquals(ReservationStatus::COMMITTED, $reservation->status);
        $this->assertEquals(40, $stock->quantityOnHand);
        $this->assertEquals(0, $stock->quantityReserved);
        $this->assertFalse($this->eventDispatcher->dispatched(StockReleasedEvent::class));
    }

    public function test_release_missing_reservation_is_silent_noop(): void
    {
        $this->service->releaseReservation('res-missing');

        $this->assertFalse($this->eventDispatcher->dispatched(StockReleasedEvent::class));
    }

    public function test_adjust_stock_positive_negative_and_zero_boundary(): void
    {
        $this->service->initializeStock(
            id: 'stk-1',
            locationType: 'warehouse',
            locationId: 'wh-jkt',
            productId: 'prod-100',
            initialQuantity: 50
        );

        $result = $this->service->adjustStock(
            locationType: 'warehouse',
            locationId: 'wh-jkt',
            productId: 'prod-100',
            quantity: 20,
            note: 'Restock'
        );
        $this->assertEquals(70, $result->quantityOnHand);

        $result = $this->service->adjustStock(
            locationType: 'warehouse',
            locationId: 'wh-jkt',
            productId: 'prod-100',
            quantity: -70,
            note: 'Write off'
        );
        $this->assertEquals(0, $result->quantityOnHand);
        $this->assertTrue($this->eventDispatcher->dispatched(StockAdjustedEvent::class));

        $this->expectException(InsufficientStockException::class);
        $this->service->adjustStock(
            locationType: 'warehouse',
            locationId: 'wh-jkt',
            productId: 'prod-100',
            quantity: -1
        );
    }

    public function test_low_stock_alert_dispatched_when_available_at_or_below_threshold(): void
    {
        $this->service->initializeStock(
            id: 'stk-1',
            locationType: 'warehouse',
            locationId: 'wh-jkt',
            productId: 'prod-100',
            initialQuantity: 6,
            minStockAlert: 5
        );

        $this->assertFalse($this->eventDispatcher->dispatched(LowStockAlertEvent::class));

        $this->service->adjustStock(
            locationType: 'warehouse',
            locationId: 'wh-jkt',
            productId: 'prod-100',
            quantity: -4
        );

        $this->assertTrue($this->eventDispatcher->dispatched(LowStockAlertEvent::class));
    }

    public function test_low_stock_alert_dispatched_after_commit(): void
    {
        $this->service->initializeStock(
            id: 'stk-1',
            locationType: 'warehouse',
            locationId: 'wh-jkt',
            productId: 'prod-100',
            initialQuantity: 10,
            minStockAlert: 5
        );

        $this->service->reserveStock(
            reservationId: 'res-1',
            locationType: 'warehouse',
            locationId: 'wh-jkt',
            productId: 'prod-100',
            quantity: 8,
            referenceType: 'order',
            referenceId: 'order-99'
        );

        $this->assertFalse($this->eventDispatcher->dispatched(LowStockAlertEvent::class));

        $this->service->commitReservation('res-1');

        $this->assertTrue($this->eventDispatcher->dispatched(StockCommittedEvent::class));
        $this->assertTrue($this->eventDispatcher->dispatched(LowStockAlertEvent::class));
        $this->assertTrue($this->eventDispatcher->dispatched(StockReservedEvent::class));
    }

    public function test_transfer_auto_initializes_target_stock(): void
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
            quantity: 30
        );

        $sourceStock = $this->stockRepo->findByLocationAndProduct('warehouse', 'wh-jkt', 'prod-300');
        $this->assertNotNull($sourceStock);
        $this->assertEquals(70, $sourceStock->quantityOnHand);

        $targetStock = $this->stockRepo->findByLocationAndProduct('store', 'store-a', 'prod-300');
        $this->assertNotNull($targetStock);
        $this->assertEquals(30, $targetStock->quantityOnHand);
        $this->assertEquals(30, $targetStock->getQuantityAvailable());
    }
}
