<?php

namespace Ttpryg\InventoryEngine\Repositories;

use DateTimeImmutable;
use PDO;
use Ttpryg\InventoryEngine\Contracts\InventoryLocationRepositoryInterface;
use Ttpryg\InventoryEngine\Entities\InventoryLocation;

class PdoInventoryLocationRepository implements InventoryLocationRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function save(InventoryLocation $location): void
    {
        $existing = $this->findById($location->id);

        $sql = $existing instanceof \Ttpryg\InventoryEngine\Entities\InventoryLocation
            ? 'UPDATE inventory_locations SET location_type = :location_type, location_id = :location_id, name = :name, code = :code, address = :address, is_active = :is_active, updated_at = :updated_at WHERE id = :id'
            : 'INSERT INTO inventory_locations (id, location_type, location_id, name, code, address, is_active, created_at, updated_at) VALUES (:id, :location_type, :location_id, :name, :code, :address, :is_active, :created_at, :updated_at)';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'id' => $location->id,
            'location_type' => $location->locationType,
            'location_id' => $location->locationId,
            'name' => $location->name,
            'code' => $location->code,
            'address' => $location->address,
            'is_active' => $location->isActive ? 1 : 0,
            'created_at' => $location->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $location->updatedAt->format('Y-m-d H:i:s'),
        ]);
    }

    public function findById(string $id): ?InventoryLocation
    {
        $stmt = $this->pdo->prepare('SELECT * FROM inventory_locations WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->mapToEntity($row) : null;
    }

    public function findByLocation(string $locationType, string $locationId): ?InventoryLocation
    {
        $stmt = $this->pdo->prepare('SELECT * FROM inventory_locations WHERE location_type = :location_type AND location_id = :location_id');
        $stmt->execute([
            'location_type' => $locationType,
            'location_id' => $locationId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->mapToEntity($row) : null;
    }

    public function findByCode(string $code): ?InventoryLocation
    {
        $stmt = $this->pdo->prepare('SELECT * FROM inventory_locations WHERE LOWER(code) = LOWER(:code)');
        $stmt->execute(['code' => $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->mapToEntity($row) : null;
    }

    public function delete(string $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM inventory_locations WHERE id = :id');

        return $stmt->execute(['id' => $id]);
    }

    private function mapToEntity(array $row): InventoryLocation
    {
        return new InventoryLocation(
            id: (string) $row['id'],
            locationType: (string) $row['location_type'],
            locationId: (string) $row['location_id'],
            name: (string) $row['name'],
            code: (string) $row['code'],
            address: $row['address'] ?? null,
            isActive: (bool) $row['is_active'],
            createdAt: ! empty($row['created_at']) ? new DateTimeImmutable($row['created_at']) : null,
            updatedAt: ! empty($row['updated_at']) ? new DateTimeImmutable($row['updated_at']) : null
        );
    }
}
