<?php

declare(strict_types=1);

namespace Ttpryg\InventoryEngine\Entities;

use DateTimeImmutable;
use Ttpryg\InventoryEngine\Enums\ReservationStatus;

class StockReservation
{
    public function __construct(
        public readonly string $id,
        public readonly string $inventoryStockId,
        public readonly string $referenceType,
        public readonly string $referenceId,
        public readonly int $quantity,
        public ReservationStatus $status,
        public DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $createdAt = null,
        public ?DateTimeImmutable $updatedAt = null
    ) {
        $this->createdAt = $createdAt ?? new DateTimeImmutable;
        $this->updatedAt = $updatedAt ?? new DateTimeImmutable;
    }

    public function isExpired(?DateTimeImmutable $now = null): bool
    {
        $now ??= new DateTimeImmutable;

        return $this->status === ReservationStatus::ACTIVE && $now > $this->expiresAt;
    }
}
