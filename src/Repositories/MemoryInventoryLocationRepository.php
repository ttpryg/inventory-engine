<?php

namespace Ttpryg\InventoryEngine\Repositories;

use Ttpryg\InventoryEngine\Contracts\InventoryLocationRepositoryInterface;
use Ttpryg\InventoryEngine\Entities\InventoryLocation;

class MemoryInventoryLocationRepository implements InventoryLocationRepositoryInterface
{
    /** @var array<string, InventoryLocation> */
    private array $locations = [];

    public function save(InventoryLocation $location): void
    {
        $this->locations[$location->id] = $location;
    }

    public function findById(string $id): ?InventoryLocation
    {
        return $this->locations[$id] ?? null;
    }

    public function findByLocation(string $locationType, string $locationId): ?InventoryLocation
    {
        foreach ($this->locations as $loc) {
            if ($loc->locationType === $locationType && $loc->locationId === $locationId) {
                return $loc;
            }
        }

        return null;
    }

    public function findByCode(string $code): ?InventoryLocation
    {
        foreach ($this->locations as $loc) {
            if (strcasecmp($loc->code, $code) === 0) {
                return $loc;
            }
        }

        return null;
    }

    public function delete(string $id): bool
    {
        if (isset($this->locations[$id])) {
            unset($this->locations[$id]);

            return true;
        }

        return false;
    }
}
