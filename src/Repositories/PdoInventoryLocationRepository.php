<?php

declare(strict_types=1);

namespace Ttpryg\InventoryEngine\Repositories;

use PDO;
use Ttpryg\InventoryEngine\Contracts\InventoryLocationRepositoryInterface;
use Ttpryg\InventoryEngine\Entities\InventoryLocation;
use Ttpryg\InventoryEngine\Support\PdoRow;

class PdoInventoryLocationRepository implements InventoryLocationRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function save(InventoryLocation $location): void
    {
        $existing = $this->findById($location->id);

        $params = [
            'id' => $location->id,
            'location_type' => $location->locationType,
            'location_id' => $location->locationId,
            'name' => $location->name,
            'code' => $location->code,
            'address' => $location->address,
            'is_active' => $location->isActive ? 1 : 0,
            'updated_at' => $location->updatedAt->format('Y-m-d H:i:s'),
        ];

        if ($existing instanceof InventoryLocation) {
            $sql = 'UPDATE inventory_locations SET location_type = :location_type, location_id = :location_id, name = :name, code = :code, address = :address, is_active = :is_active, updated_at = :updated_at WHERE id = :id';
        } else {
            $sql = 'INSERT INTO inventory_locations (id, location_type, location_id, name, code, address, is_active, created_at, updated_at) VALUES (:id, :location_type, :location_id, :name, :code, :address, :is_active, :created_at, :updated_at)';
            $params['created_at'] = $location->createdAt->format('Y-m-d H:i:s');
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
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

    /**
     * @param  array<string, mixed>  $row
     */
    private function mapToEntity(array $row): InventoryLocation
    {
        return new InventoryLocation(
            id: PdoRow::string($row, 'id'),
            locationType: PdoRow::string($row, 'location_type'),
            locationId: PdoRow::string($row, 'location_id'),
            name: PdoRow::string($row, 'name'),
            code: PdoRow::string($row, 'code'),
            address: PdoRow::nullableString($row, 'address'),
            isActive: PdoRow::bool($row, 'is_active'),
            createdAt: PdoRow::dateTime($row, 'created_at'),
            updatedAt: PdoRow::dateTime($row, 'updated_at')
        );
    }
}
