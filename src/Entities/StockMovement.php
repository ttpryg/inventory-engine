<?php

declare(strict_types=1);

namespace Ttpryg\InventoryEngine\Entities;

use DateTimeImmutable;
use Ttpryg\InventoryEngine\Enums\MovementType;

class StockMovement
{
    public function __construct(
        public readonly string $id,
        public readonly string $inventoryStockId,
        public readonly string $locationType,
        public readonly string $locationId,
        public readonly MovementType $type,
        public readonly int $quantity,
        public readonly ?string $referenceType = null,
        public readonly ?string $referenceId = null,
        public readonly ?string $note = null,
        public readonly ?string $actorId = null,
        public ?DateTimeImmutable $createdAt = null
    ) {
        $this->createdAt = $createdAt ?? new DateTimeImmutable();
    }
}
