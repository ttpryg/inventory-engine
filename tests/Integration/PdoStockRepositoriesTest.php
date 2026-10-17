<?php

namespace Ttpryg\InventoryEngine\Tests\Integration;

use DateTimeImmutable;
use Ttpryg\InventoryEngine\Entities\InventoryStock;
use Ttpryg\InventoryEngine\Entities\StockMovement;
use Ttpryg\InventoryEngine\Entities\StockReservation;
use Ttpryg\InventoryEngine\Enums\MovementType;
use Ttpryg\InventoryEngine\Enums\ReservationStatus;
use Ttpryg\InventoryEngine\Repositories\PdoInventoryStockRepository;
use Ttpryg\InventoryEngine\Repositories\PdoStockMovementRepository;
use Ttpryg\InventoryEngine\Repositories\PdoStockReservationRepository;

class PdoStockRepositoriesTest extends PdoTestCase
{
    private PdoInventoryStockRepository $stockRepo;

    private PdoStockReservationRepository $reservationRepo;

    private PdoStockMovementRepository $movementRepo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stockRepo = new PdoInventoryStockRepository($this->pdo);
        $this->reservationRepo = new PdoStockReservationRepository($this->pdo);
        $this->movementRepo = new PdoStockMovementRepository($this->pdo);

        $this->stockRepo->save(new InventoryStock(
            id: 'stk-1',
            locationType: 'warehouse',
            locationId: 'wh-jkt',
            productId: 'prod-100',
            quantityOnHand: 50
        ));
        $this->stockRepo->save(new InventoryStock(
            id: 'stk-other',
            locationType: 'store',
            locationId: 'store-a',
            productId: 'prod-200',
            quantityOnHand: 5
        ));
    }

    private function makeReservation(
        string $id,
        string $referenceId = 'order-99',
        ReservationStatus $status = ReservationStatus::ACTIVE,
        DateTimeImmutable $expiresAt = new DateTimeImmutable('+15 minutes')
    ): StockReservation {
        return new StockReservation(
            id: $id,
            inventoryStockId: 'stk-1',
            referenceType: 'order',
            referenceId: $referenceId,
            quantity: 10,
            status: $status,
            expiresAt: $expiresAt
        );
    }

    public function test_reservation_save_insert_then_update(): void
    {
        $reservation = $this->makeReservation('res-1');
        $this->reservationRepo->save($reservation);

        $fetched = $this->reservationRepo->findById('res-1');
        $this->assertNotNull($fetched);
        $this->assertEquals(ReservationStatus::ACTIVE, $fetched->status);
        $this->assertEquals(10, $fetched->quantity);

        $fetched->status = ReservationStatus::COMMITTED;
        $fetched->updatedAt = new DateTimeImmutable;
        $this->reservationRepo->save($fetched);

        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM stock_reservations WHERE id = 'res-1'")->fetchColumn();
        $this->assertEquals(1, $count);
        $this->assertEquals(ReservationStatus::COMMITTED, $this->reservationRepo->findById('res-1')?->status);
    }

    public function test_find_reservation_by_reference(): void
    {
        $this->reservationRepo->save($this->makeReservation('res-1', 'order-99'));
        $this->reservationRepo->save($this->makeReservation('res-2', 'order-100'));
        $this->reservationRepo->save($this->makeReservation('res-3', 'order-99'));

        $found = $this->reservationRepo->findByReference('order', 'order-99');

        $this->assertCount(2, $found);
        $this->assertEquals('res-1', $found[0]->id);
        $this->assertEquals('res-3', $found[1]->id);
    }

    public function test_find_expired_active_reservations_filters_by_status_and_time(): void
    {
        $past = new DateTimeImmutable('-1 minute');
        $future = new DateTimeImmutable('+15 minutes');

        $this->reservationRepo->save($this->makeReservation('res-expired-active', expiresAt: $past));
        $this->reservationRepo->save($this->makeReservation('res-active-future', expiresAt: $future));
        $this->reservationRepo->save($this->makeReservation('res-expired-committed', status: ReservationStatus::COMMITTED, expiresAt: $past));
        $this->reservationRepo->save($this->makeReservation('res-expired-released', status: ReservationStatus::RELEASED, expiresAt: $past));

        $expired = $this->reservationRepo->findExpiredActiveReservations();

        $this->assertCount(1, $expired);
        $this->assertEquals('res-expired-active', $expired[0]->id);
    }

    public function test_movement_record_and_find_by_stock(): void
    {
        $this->movementRepo->record(new StockMovement(
            id: 'mov-1',
            inventoryStockId: 'stk-1',
            locationType: 'warehouse',
            locationId: 'wh-jkt',
            type: MovementType::IN,
            quantity: 50
        ));
        $this->movementRepo->record(new StockMovement(
            id: 'mov-2',
            inventoryStockId: 'stk-1',
            locationType: 'warehouse',
            locationId: 'wh-jkt',
            type: MovementType::RESERVE,
            quantity: 10
        ));
        $this->movementRepo->record(new StockMovement(
            id: 'mov-3',
            inventoryStockId: 'stk-1',
            locationType: 'warehouse',
            locationId: 'wh-jkt',
            type: MovementType::COMMIT,
            quantity: 10
        ));
        $this->movementRepo->record(new StockMovement(
            id: 'mov-4',
            inventoryStockId: 'stk-other',
            locationType: 'store',
            locationId: 'store-a',
            type: MovementType::IN,
            quantity: 5
        ));

        $all = $this->movementRepo->findByStock('stk-1');
        $this->assertCount(3, $all);

        $types = array_map(static fn (StockMovement $movement): string => $movement->type->value, $all);
        sort($types);
        $this->assertEquals(['commit', 'in', 'reserve'], $types);

        $limited = $this->movementRepo->findByStock('stk-1', limit: 2);
        $this->assertCount(2, $limited);

        $paged = $this->movementRepo->findByStock('stk-1', limit: 50, offset: 2);
        $this->assertCount(1, $paged);
    }

    public function test_movement_find_by_location(): void
    {
        $this->movementRepo->record(new StockMovement(
            id: 'mov-1',
            inventoryStockId: 'stk-1',
            locationType: 'warehouse',
            locationId: 'wh-jkt',
            type: MovementType::IN,
            quantity: 50
        ));
        $this->movementRepo->record(new StockMovement(
            id: 'mov-2',
            inventoryStockId: 'stk-1',
            locationType: 'warehouse',
            locationId: 'wh-jkt',
            type: MovementType::ADJUSTMENT,
            quantity: -5,
            note: 'Write off'
        ));
        $this->movementRepo->record(new StockMovement(
            id: 'mov-3',
            inventoryStockId: 'stk-other',
            locationType: 'store',
            locationId: 'store-a',
            type: MovementType::IN,
            quantity: 5
        ));

        $found = $this->movementRepo->findByLocation('warehouse', 'wh-jkt');

        $this->assertCount(2, $found);
        $this->assertEquals('warehouse', $found[0]->locationType);

        $none = $this->movementRepo->findByLocation('consignment', 'none');
        $this->assertCount(0, $none);
    }
}
