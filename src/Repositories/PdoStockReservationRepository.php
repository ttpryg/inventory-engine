<?php

declare(strict_types=1);

namespace Ttpryg\InventoryEngine\Repositories;

use DateTimeImmutable;
use PDO;
use Ttpryg\InventoryEngine\Contracts\StockReservationRepositoryInterface;
use Ttpryg\InventoryEngine\Entities\StockReservation;
use Ttpryg\InventoryEngine\Enums\ReservationStatus;
use Ttpryg\InventoryEngine\Support\PdoRow;

class PdoStockReservationRepository implements StockReservationRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function save(StockReservation $reservation): void
    {
        $existing = $this->findById($reservation->id);

        $params = [
            'id' => $reservation->id,
            'inventory_stock_id' => $reservation->inventoryStockId,
            'reference_type' => $reservation->referenceType,
            'reference_id' => $reservation->referenceId,
            'quantity' => $reservation->quantity,
            'status' => $reservation->status->value,
            'expires_at' => $reservation->expiresAt->format('Y-m-d H:i:s'),
            'updated_at' => $reservation->updatedAt->format('Y-m-d H:i:s'),
        ];

        if ($existing instanceof StockReservation) {
            $sql = 'UPDATE stock_reservations SET inventory_stock_id = :inventory_stock_id, reference_type = :reference_type, reference_id = :reference_id, quantity = :quantity, status = :status, expires_at = :expires_at, updated_at = :updated_at WHERE id = :id';
        } else {
            $sql = 'INSERT INTO stock_reservations (id, inventory_stock_id, reference_type, reference_id, quantity, status, expires_at, created_at, updated_at) VALUES (:id, :inventory_stock_id, :reference_type, :reference_id, :quantity, :status, :expires_at, :created_at, :updated_at)';
            $params['created_at'] = $reservation->createdAt->format('Y-m-d H:i:s');
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    public function findById(string $id): ?StockReservation
    {
        $stmt = $this->pdo->prepare('SELECT * FROM stock_reservations WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->mapToEntity($row) : null;
    }

    public function findByReference(string $referenceType, string $referenceId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM stock_reservations WHERE reference_type = :reference_type AND reference_id = :reference_id');
        $stmt->execute([
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $row) {
            $result[] = $this->mapToEntity($row);
        }

        return $result;
    }

    public function findExpiredActiveReservations(): array
    {
        $now = (new DateTimeImmutable)->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare("SELECT * FROM stock_reservations WHERE status = 'active' AND expires_at < :now");
        $stmt->execute(['now' => $now]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $row) {
            $result[] = $this->mapToEntity($row);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function mapToEntity(array $row): StockReservation
    {
        return new StockReservation(
            id: PdoRow::string($row, 'id'),
            inventoryStockId: PdoRow::string($row, 'inventory_stock_id'),
            referenceType: PdoRow::string($row, 'reference_type'),
            referenceId: PdoRow::string($row, 'reference_id'),
            quantity: PdoRow::int($row, 'quantity'),
            status: ReservationStatus::from(PdoRow::string($row, 'status')),
            expiresAt: PdoRow::dateTimeNotNull($row, 'expires_at'),
            createdAt: PdoRow::dateTime($row, 'created_at'),
            updatedAt: PdoRow::dateTime($row, 'updated_at')
        );
    }
}
