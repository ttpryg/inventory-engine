<?php

namespace Ttpryg\InventoryEngine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ttpryg\InventoryEngine\Enums\ReservationStatus;
use Ttpryg\InventoryEngine\Repositories\MemoryInventoryLocationRepository;
use Ttpryg\InventoryEngine\Repositories\MemoryInventoryStockRepository;
use Ttpryg\InventoryEngine\Repositories\MemoryStockMovementRepository;
use Ttpryg\InventoryEngine\Repositories\MemoryStockReservationRepository;
use Ttpryg\InventoryEngine\Services\InventoryService;

class InventoryServiceTest extends TestCase
{
    private InventoryService $service;

    protected function setUp(): void
    {
        $locationRepo = new MemoryInventoryLocationRepository();
        $stockRepo = new MemoryInventoryStockRepository();
        $reservationRepo = new MemoryStockReservationRepository();
        $movementRepo = new MemoryStockMovementRepository();

        $this->service = new InventoryService(
            $locationRepo,
            $stockRepo,
            $reservationRepo,
            $movementRepo
        );
    }

    public function testReserveAndCommitStockFlow(): void
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

    public function testTransferStockBetweenLocations(): void
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
}
