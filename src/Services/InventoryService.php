<?php

namespace Ttpryg\InventoryEngine\Services;

use DateTimeImmutable;
use RuntimeException;
use Ttpryg\EventDispatcher\Contracts\EventDispatcherInterface;
use Ttpryg\InventoryEngine\Contracts\InventoryLocationRepositoryInterface;
use Ttpryg\InventoryEngine\Contracts\InventoryStockRepositoryInterface;
use Ttpryg\InventoryEngine\Contracts\StockMovementRepositoryInterface;
use Ttpryg\InventoryEngine\Contracts\StockReservationRepositoryInterface;
use Ttpryg\InventoryEngine\Entities\InventoryLocation;
use Ttpryg\InventoryEngine\Entities\InventoryStock;
use Ttpryg\InventoryEngine\Entities\StockMovement;
use Ttpryg\InventoryEngine\Entities\StockReservation;
use Ttpryg\InventoryEngine\Enums\MovementType;
use Ttpryg\InventoryEngine\Enums\ReservationStatus;
use Ttpryg\InventoryEngine\Events\LowStockAlertEvent;
use Ttpryg\InventoryEngine\Events\StockAdjustedEvent;
use Ttpryg\InventoryEngine\Events\StockCommittedEvent;
use Ttpryg\InventoryEngine\Events\StockReleasedEvent;
use Ttpryg\InventoryEngine\Events\StockReservedEvent;

class InventoryService
{
    public function __construct(
        private readonly InventoryLocationRepositoryInterface $locationRepo,
        private readonly InventoryStockRepositoryInterface $stockRepo,
        private readonly StockReservationRepositoryInterface $reservationRepo,
        private readonly StockMovementRepositoryInterface $movementRepo,
        private readonly ?EventDispatcherInterface $eventDispatcher = null
    ) {}

    public function createLocation(
        string $id,
        string $locationType,
        string $locationId,
        string $name,
        string $code,
        ?string $address = null
    ): InventoryLocation {
        $location = new InventoryLocation(
            id: $id,
            locationType: $locationType,
            locationId: $locationId,
            name: $name,
            code: $code,
            address: $address
        );

        $this->locationRepo->save($location);
        return $location;
    }

    public function initializeStock(
        string $id,
        string $locationType,
        string $locationId,
        string $productId,
        int $initialQuantity = 0,
        ?string $variantId = null,
        ?string $sku = null,
        int $minStockAlert = 5,
        ?string $actorId = null
    ): InventoryStock {
        $stock = new InventoryStock(
            id: $id,
            locationType: $locationType,
            locationId: $locationId,
            productId: $productId,
            variantId: $variantId,
            sku: $sku,
            quantityOnHand: $initialQuantity,
            quantityReserved: 0,
            minStockAlert: $minStockAlert
        );

        $this->stockRepo->save($stock);

        if ($initialQuantity > 0) {
            $movement = new StockMovement(
                id: 'mov-' . uniqid(),
                inventoryStockId: $stock->id,
                locationType: $locationType,
                locationId: $locationId,
                type: MovementType::IN,
                quantity: $initialQuantity,
                note: 'Initial Stock Initialization',
                actorId: $actorId
            );
            $this->movementRepo->record($movement);
        }

        return $stock;
    }

    public function reserveStock(
        string $reservationId,
        string $locationType,
        string $locationId,
        string $productId,
        int $quantity,
        string $referenceType,
        string $referenceId,
        ?string $variantId = null,
        int $ttlMinutes = 15
    ): StockReservation {
        $stock = $this->stockRepo->findByLocationAndProduct($locationType, $locationId, $productId, $variantId);
        if (!$stock instanceof \Ttpryg\InventoryEngine\Entities\InventoryStock) {
            throw new RuntimeException("Stock record not found for product {$productId} at location {$locationType}:{$locationId}");
        }

        if ($stock->getQuantityAvailable() < $quantity) {
            throw new RuntimeException("Insufficient available stock (Requested: {$quantity}, Available: {$stock->getQuantityAvailable()})");
        }

        $stock->reserve($quantity);
        $this->stockRepo->save($stock);

        $reservation = new StockReservation(
            id: $reservationId,
            inventoryStockId: $stock->id,
            referenceType: $referenceType,
            referenceId: $referenceId,
            quantity: $quantity,
            status: ReservationStatus::ACTIVE,
            expiresAt: (new DateTimeImmutable())->modify("+{$ttlMinutes} minutes")
        );

        $this->reservationRepo->save($reservation);

        $movement = new StockMovement(
            id: 'mov-' . uniqid(),
            inventoryStockId: $stock->id,
            locationType: $locationType,
            locationId: $locationId,
            type: MovementType::RESERVE,
            quantity: $quantity,
            referenceType: $referenceType,
            referenceId: $referenceId
        );
        $this->movementRepo->record($movement);

        $this->eventDispatcher?->dispatch(new StockReservedEvent($reservation));

        return $reservation;
    }

    public function commitReservation(string $reservationId, ?string $actorId = null): void
    {
        $reservation = $this->reservationRepo->findById($reservationId);
        if (!$reservation instanceof \Ttpryg\InventoryEngine\Entities\StockReservation || $reservation->status !== ReservationStatus::ACTIVE) {
            throw new RuntimeException("Reservation {$reservationId} is not active or not found");
        }

        $stock = $this->stockRepo->findById($reservation->inventoryStockId);
        if (!$stock instanceof \Ttpryg\InventoryEngine\Entities\InventoryStock) {
            throw new RuntimeException("Inventory stock record not found");
        }

        $stock->commit($reservation->quantity);
        $this->stockRepo->save($stock);

        $reservation->status = ReservationStatus::COMMITTED;
        $reservation->updatedAt = new DateTimeImmutable();
        $this->reservationRepo->save($reservation);

        $movement = new StockMovement(
            id: 'mov-' . uniqid(),
            inventoryStockId: $stock->id,
            locationType: $stock->locationType,
            locationId: $stock->locationId,
            type: MovementType::COMMIT,
            quantity: $reservation->quantity,
            referenceType: $reservation->referenceType,
            referenceId: $reservation->referenceId,
            actorId: $actorId
        );
        $this->movementRepo->record($movement);

        $this->eventDispatcher?->dispatch(new StockCommittedEvent($reservation));

        if ($stock->isLowStock()) {
            $this->eventDispatcher?->dispatch(new LowStockAlertEvent($stock));
        }
    }

    public function releaseReservation(string $reservationId, ?string $actorId = null): void
    {
        $reservation = $this->reservationRepo->findById($reservationId);
        if (!$reservation instanceof \Ttpryg\InventoryEngine\Entities\StockReservation || $reservation->status !== ReservationStatus::ACTIVE) {
            return;
        }

        $stock = $this->stockRepo->findById($reservation->inventoryStockId);
        if ($stock instanceof \Ttpryg\InventoryEngine\Entities\InventoryStock) {
            $stock->release($reservation->quantity);
            $this->stockRepo->save($stock);

            $movement = new StockMovement(
                id: 'mov-' . uniqid(),
                inventoryStockId: $stock->id,
                locationType: $stock->locationType,
                locationId: $stock->locationId,
                type: MovementType::RELEASE,
                quantity: $reservation->quantity,
                referenceType: $reservation->referenceType,
                referenceId: $reservation->referenceId,
                actorId: $actorId
            );
            $this->movementRepo->record($movement);
        }

        $reservation->status = ReservationStatus::RELEASED;
        $reservation->updatedAt = new DateTimeImmutable();
        $this->reservationRepo->save($reservation);

        $this->eventDispatcher?->dispatch(new StockReleasedEvent($reservation));
    }

    public function adjustStock(
        string $locationType,
        string $locationId,
        string $productId,
        int $deltaQuantity,
        ?string $variantId = null,
        ?string $note = null,
        ?string $actorId = null
    ): InventoryStock {
        $stock = $this->stockRepo->findByLocationAndProduct($locationType, $locationId, $productId, $variantId);
        if (!$stock instanceof \Ttpryg\InventoryEngine\Entities\InventoryStock) {
            throw new RuntimeException("Stock record not found for adjustment");
        }

        $stock->adjust($deltaQuantity);
        $this->stockRepo->save($stock);

        $movement = new StockMovement(
            id: 'mov-' . uniqid(),
            inventoryStockId: $stock->id,
            locationType: $locationType,
            locationId: $locationId,
            type: MovementType::ADJUSTMENT,
            quantity: $deltaQuantity,
            note: $note,
            actorId: $actorId
        );
        $this->movementRepo->record($movement);

        $this->eventDispatcher?->dispatch(new StockAdjustedEvent($stock, $movement));

        if ($stock->isLowStock()) {
            $this->eventDispatcher?->dispatch(new LowStockAlertEvent($stock));
        }

        return $stock;
    }

    public function transferStock(
        string $productId,
        string $fromLocationType,
        string $fromLocationId,
        string $toLocationType,
        string $toLocationId,
        int $quantity,
        ?string $variantId = null,
        ?string $note = null,
        ?string $actorId = null
    ): void {
        $sourceStock = $this->stockRepo->findByLocationAndProduct($fromLocationType, $fromLocationId, $productId, $variantId);
        if (!$sourceStock instanceof \Ttpryg\InventoryEngine\Entities\InventoryStock || $sourceStock->getQuantityAvailable() < $quantity) {
            throw new RuntimeException("Insufficient available stock at source location to transfer");
        }

        $targetStock = $this->stockRepo->findByLocationAndProduct($toLocationType, $toLocationId, $productId, $variantId);
        if (!$targetStock instanceof \Ttpryg\InventoryEngine\Entities\InventoryStock) {
            $targetStock = $this->initializeStock(
                id: 'stk-' . uniqid(),
                locationType: $toLocationType,
                locationId: $toLocationId,
                productId: $productId,
                initialQuantity: 0,
                variantId: $variantId,
                actorId: $actorId
            );
        }

        $sourceStock->adjust(-$quantity);
        $this->stockRepo->save($sourceStock);

        $targetStock->adjust($quantity);
        $this->stockRepo->save($targetStock);

        // Record movements for source and target
        $this->movementRepo->record(new StockMovement(
            id: 'mov-' . uniqid(),
            inventoryStockId: $sourceStock->id,
            locationType: $fromLocationType,
            locationId: $fromLocationId,
            type: MovementType::TRANSFER,
            quantity: -$quantity,
            note: "Transfer to {$toLocationType}:{$toLocationId} - " . ($note ?? ''),
            actorId: $actorId
        ));

        $this->movementRepo->record(new StockMovement(
            id: 'mov-' . uniqid(),
            inventoryStockId: $targetStock->id,
            locationType: $toLocationType,
            locationId: $toLocationId,
            type: MovementType::TRANSFER,
            quantity: $quantity,
            note: "Transfer from {$fromLocationType}:{$fromLocationId} - " . ($note ?? ''),
            actorId: $actorId
        ));
    }
}
