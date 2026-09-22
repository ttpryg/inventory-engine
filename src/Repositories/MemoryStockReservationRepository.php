<?php

namespace Ttpryg\InventoryEngine\Repositories;

use DateTimeImmutable;
use Ttpryg\InventoryEngine\Contracts\StockReservationRepositoryInterface;
use Ttpryg\InventoryEngine\Entities\StockReservation;
use Ttpryg\InventoryEngine\Enums\ReservationStatus;

class MemoryStockReservationRepository implements StockReservationRepositoryInterface
{
    /** @var array<string, StockReservation> */
    private array $reservations = [];

    public function save(StockReservation $reservation): void
    {
        $this->reservations[$reservation->id] = $reservation;
    }

    public function findById(string $id): ?StockReservation
    {
        return $this->reservations[$id] ?? null;
    }

    public function findByReference(string $referenceType, string $referenceId): array
    {
        $result = [];
        foreach ($this->reservations as $r) {
            if ($r->referenceType === $referenceType && $r->referenceId === $referenceId) {
                $result[] = $r;
            }
        }

        return $result;
    }

    public function findExpiredActiveReservations(): array
    {
        $now = new DateTimeImmutable;
        $result = [];
        foreach ($this->reservations as $r) {
            if ($r->status === ReservationStatus::ACTIVE && $now > $r->expiresAt) {
                $result[] = $r;
            }
        }

        return $result;
    }
}
