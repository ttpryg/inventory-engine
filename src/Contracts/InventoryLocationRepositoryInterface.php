<?php

declare(strict_types=1);

namespace Ttpryg\InventoryEngine\Contracts;

use Ttpryg\InventoryEngine\Entities\InventoryLocation;

interface InventoryLocationRepositoryInterface
{
    public function save(InventoryLocation $location): void;

    public function findById(string $id): ?InventoryLocation;

    public function findByLocation(string $locationType, string $locationId): ?InventoryLocation;

    public function findByCode(string $code): ?InventoryLocation;

    public function delete(string $id): bool;
}
