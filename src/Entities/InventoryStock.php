<?php

declare(strict_types=1);

namespace Ttpryg\InventoryEngine\Entities;

use DateTimeImmutable;

class InventoryStock
{
    public function __construct(
        public readonly string $id,
        public readonly string $locationType,
        public readonly string $locationId,
        public readonly string $productId,
        public ?string $variantId = null,
        public ?string $sku = null,
        public int $quantityOnHand = 0,
        public int $quantityReserved = 0,
        public int $minStockAlert = 5,
        public ?DateTimeImmutable $createdAt = null,
        public ?DateTimeImmutable $updatedAt = null
    ) {
        $this->createdAt = $createdAt ?? new DateTimeImmutable;
        $this->updatedAt = $updatedAt ?? new DateTimeImmutable;
    }

    public function getQuantityAvailable(): int
    {
        return max(0, $this->quantityOnHand - $this->quantityReserved);
    }

    public function isLowStock(): bool
    {
        return $this->getQuantityAvailable() <= $this->minStockAlert;
    }

    public function reserve(int $quantity): void
    {
        $this->quantityReserved += $quantity;
        $this->updatedAt = new DateTimeImmutable;
    }

    public function commit(int $quantity): void
    {
        $this->quantityReserved = max(0, $this->quantityReserved - $quantity);
        $this->quantityOnHand = max(0, $this->quantityOnHand - $quantity);
        $this->updatedAt = new DateTimeImmutable;
    }

    public function release(int $quantity): void
    {
        $this->quantityReserved = max(0, $this->quantityReserved - $quantity);
        $this->updatedAt = new DateTimeImmutable;
    }

    public function adjust(int $delta): void
    {
        $this->quantityOnHand += $delta;
        $this->updatedAt = new DateTimeImmutable;
    }
}
