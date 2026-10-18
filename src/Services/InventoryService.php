<?php

declare(strict_types=1);

namespace Ttpryg\InventoryEngine\Services;

use DateTimeImmutable;
use Throwable;
use Ttpryg\EventDispatcher\Contracts\EventDispatcherInterface;
use Ttpryg\InventoryEngine\Contracts\InventoryLocationRepositoryInterface;
use Ttpryg\InventoryEngine\Contracts\InventoryStockRepositoryInterface;
use Ttpryg\InventoryEngine\Contracts\StockMovementRepositoryInterface;
use Ttpryg\InventoryEngine\Contracts\StockReservationRepositoryInterface;
use Ttpryg\InventoryEngine\Contracts\TransactionManagerInterface;
use Ttpryg\InventoryEngine\Entities\InventoryLocation;
use Ttpryg\InventoryEngine\Entities\InventoryStock;
use Ttpryg\InventoryEngine\Entities\StockMovement;
use Ttpryg\InventoryEngine\Entities\StockReservation;
use Ttpryg\InventoryEngine\Enums\MovementType;
use Ttpryg\InventoryEngine\Enums\ReservationStatus;
use Ttpryg\InventoryEngine\Events\LowStockAlertEvent;
use Ttpryg\InventoryEngine\Events\StockAdjustedEvent;
use Ttpryg\InventoryEngine\Events\StockCommittedEvent;
use Ttpryg\InventoryEngine\Events\StockExpiredEvent;
use Ttpryg\InventoryEngine\Events\StockReleasedEvent;
use Ttpryg\InventoryEngine\Events\StockReservedEvent;
use Ttpryg\InventoryEngine\Exceptions\InsufficientStockException;
use Ttpryg\InventoryEngine\Exceptions\InvalidQuantityException;
use Ttpryg\InventoryEngine\Exceptions\ReservationExpiredException;
use Ttpryg\InventoryEngine\Exceptions\ReservationNotActiveException;
use Ttpryg\InventoryEngine\Exceptions\ReservationNotFoundException;
use Ttpryg\InventoryEngine\Exceptions\StockNotFoundException;
use Ttpryg\InventoryEngine\Repositories\NullTransactionManager;
use Ttpryg\InventoryEngine\Support\Uuid;

class InventoryService
{
    private readonly TransactionManagerInterface $transactionManager;

    public function __construct(
        private readonly InventoryLocationRepositoryInterface $locationRepo,
        private readonly InventoryStockRepositoryInterface $stockRepo,
        private readonly StockReservationRepositoryInterface $reservationRepo,
        private readonly StockMovementRepositoryInterface $movementRepo,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        ?TransactionManagerInterface $transactionManager = null
    ) {
        $this->transactionManager = $transactionManager ?? new NullTransactionManager;
    }

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
        return $this->transactional(function () use ($id, $locationType, $locationId, $productId, $initialQuantity, $variantId, $sku, $minStockAlert, $actorId): InventoryStock {
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
                    id: Uuid::uuid4(),
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
        });
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
        return $this->transactional(function () use ($reservationId, $locationType, $locationId, $productId, $quantity, $referenceType, $referenceId, $variantId, $ttlMinutes): StockReservation {
            if ($quantity <= 0) {
                throw new InvalidQuantityException("Reservation quantity must be greater than 0 (got {$quantity})");
            }

            $stock = $this->stockRepo->findByLocationAndProductForUpdate($locationType, $locationId, $productId, $variantId);
            if (! $stock instanceof InventoryStock) {
                throw new StockNotFoundException("Stock record not found for product {$productId} at location {$locationType}:{$locationId}");
            }

            if ($stock->getQuantityAvailable() < $quantity) {
                throw new InsufficientStockException($quantity, $stock->getQuantityAvailable());
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
                expiresAt: (new DateTimeImmutable)->modify("+{$ttlMinutes} minutes")
            );

            $this->reservationRepo->save($reservation);

            $movement = new StockMovement(
                id: Uuid::uuid4(),
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
        });
    }

    public function commitReservation(string $reservationId, ?string $actorId = null): void
    {
        $this->transactional(function () use ($reservationId, $actorId): void {
            $reservation = $this->reservationRepo->findById($reservationId);
            if (! $reservation instanceof StockReservation) {
                throw new ReservationNotFoundException("Reservation {$reservationId} not found");
            }

            if ($reservation->status !== ReservationStatus::ACTIVE) {
                throw new ReservationNotActiveException("Reservation {$reservationId} is not active (status: {$reservation->status->value})");
            }

            if ($reservation->isExpired()) {
                throw new ReservationExpiredException("Reservation {$reservationId} has expired");
            }

            $stock = $this->stockRepo->findByIdForUpdate($reservation->inventoryStockId);
            if (! $stock instanceof InventoryStock) {
                throw new StockNotFoundException("Inventory stock record {$reservation->inventoryStockId} not found");
            }

            $stock->commit($reservation->quantity);
            $this->stockRepo->save($stock);

            $reservation->status = ReservationStatus::COMMITTED;
            $reservation->updatedAt = new DateTimeImmutable;
            $this->reservationRepo->save($reservation);

            $movement = new StockMovement(
                id: Uuid::uuid4(),
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
        });
    }

    public function releaseReservation(string $reservationId, ?string $actorId = null): void
    {
        $this->transactional(function () use ($reservationId, $actorId): void {
            $reservation = $this->reservationRepo->findById($reservationId);
            if (! $reservation instanceof StockReservation || $reservation->status !== ReservationStatus::ACTIVE) {
                return;
            }

            $stock = $this->stockRepo->findByIdForUpdate($reservation->inventoryStockId);
            if ($stock instanceof InventoryStock) {
                $stock->release($reservation->quantity);
                $this->stockRepo->save($stock);

                $movement = new StockMovement(
                    id: Uuid::uuid4(),
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
            $reservation->updatedAt = new DateTimeImmutable;
            $this->reservationRepo->save($reservation);

            $this->eventDispatcher?->dispatch(new StockReleasedEvent($reservation));
        });
    }

    public function expireReservation(string $reservationId, ?string $actorId = null): void
    {
        $this->transactional(function () use ($reservationId, $actorId): void {
            $reservation = $this->reservationRepo->findById($reservationId);
            if (! $reservation instanceof StockReservation) {
                throw new ReservationNotFoundException("Reservation {$reservationId} not found");
            }

            if ($reservation->status !== ReservationStatus::ACTIVE) {
                return;
            }

            $stock = $this->stockRepo->findByIdForUpdate($reservation->inventoryStockId);
            if ($stock instanceof InventoryStock) {
                $stock->release($reservation->quantity);
                $this->stockRepo->save($stock);

                $movement = new StockMovement(
                    id: Uuid::uuid4(),
                    inventoryStockId: $stock->id,
                    locationType: $stock->locationType,
                    locationId: $stock->locationId,
                    type: MovementType::RELEASE,
                    quantity: $reservation->quantity,
                    referenceType: $reservation->referenceType,
                    referenceId: $reservation->referenceId,
                    note: 'Reservation expired',
                    actorId: $actorId
                );
                $this->movementRepo->record($movement);
            }

            $reservation->status = ReservationStatus::EXPIRED;
            $reservation->updatedAt = new DateTimeImmutable;
            $this->reservationRepo->save($reservation);

            $this->eventDispatcher?->dispatch(new StockExpiredEvent($reservation));
        });
    }

    public function processExpiredReservations(?string $actorId = null): int
    {
        $expired = 0;

        foreach ($this->reservationRepo->findExpiredActiveReservations() as $reservation) {
            $this->expireReservation($reservation->id, $actorId);
            $expired++;
        }

        return $expired;
    }

    public function adjustStock(
        string $locationType,
        string $locationId,
        string $productId,
        int $quantity,
        ?string $variantId = null,
        ?string $note = null,
        ?string $actorId = null
    ): InventoryStock {
        return $this->transactional(function () use ($locationType, $locationId, $productId, $quantity, $variantId, $note, $actorId): InventoryStock {
            $stock = $this->stockRepo->findByLocationAndProductForUpdate($locationType, $locationId, $productId, $variantId);
            if (! $stock instanceof InventoryStock) {
                throw new StockNotFoundException('Stock record not found for adjustment');
            }

            $stock->adjust($quantity);
            $this->stockRepo->save($stock);

            $movement = new StockMovement(
                id: Uuid::uuid4(),
                inventoryStockId: $stock->id,
                locationType: $locationType,
                locationId: $locationId,
                type: MovementType::ADJUSTMENT,
                quantity: $quantity,
                note: $note,
                actorId: $actorId
            );
            $this->movementRepo->record($movement);

            $this->eventDispatcher?->dispatch(new StockAdjustedEvent($stock, $movement));

            if ($stock->isLowStock()) {
                $this->eventDispatcher?->dispatch(new LowStockAlertEvent($stock));
            }

            return $stock;
        });
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
        $this->transactional(function () use ($productId, $fromLocationType, $fromLocationId, $toLocationType, $toLocationId, $quantity, $variantId, $note, $actorId): void {
            $sourceStock = $this->stockRepo->findByLocationAndProductForUpdate($fromLocationType, $fromLocationId, $productId, $variantId);
            if (! $sourceStock instanceof InventoryStock) {
                throw new StockNotFoundException("Stock record not found at source location {$fromLocationType}:{$fromLocationId} for product {$productId}");
            }

            if ($sourceStock->getQuantityAvailable() < $quantity) {
                $available = $sourceStock->getQuantityAvailable();
                throw new InsufficientStockException($quantity, $available, "Insufficient available stock at source location to transfer (Requested: {$quantity}, Available: {$available})");
            }

            $targetStock = $this->stockRepo->findByLocationAndProductForUpdate($toLocationType, $toLocationId, $productId, $variantId);
            if (! $targetStock instanceof InventoryStock) {
                $targetStock = $this->initializeStock(
                    id: Uuid::uuid4(),
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

            $this->movementRepo->record(new StockMovement(
                id: Uuid::uuid4(),
                inventoryStockId: $sourceStock->id,
                locationType: $fromLocationType,
                locationId: $fromLocationId,
                type: MovementType::TRANSFER,
                quantity: -$quantity,
                note: "Transfer to {$toLocationType}:{$toLocationId} - ".($note ?? ''),
                actorId: $actorId
            ));

            $this->movementRepo->record(new StockMovement(
                id: Uuid::uuid4(),
                inventoryStockId: $targetStock->id,
                locationType: $toLocationType,
                locationId: $toLocationId,
                type: MovementType::TRANSFER,
                quantity: $quantity,
                note: "Transfer from {$fromLocationType}:{$fromLocationId} - ".($note ?? ''),
                actorId: $actorId
            ));
        });
    }

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function transactional(callable $operation): mixed
    {
        if ($this->transactionManager->isInTransaction()) {
            return $operation();
        }

        $this->transactionManager->beginTransaction();

        try {
            $result = $operation();
            $this->transactionManager->commit();

            return $result;
        } catch (Throwable $exception) {
            $this->transactionManager->rollBack();

            throw $exception;
        }
    }
}
