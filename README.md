# InventoryEngine Library (`ttpryg/inventory-engine`)

`ttpryg/inventory-engine` is a framework-agnostic standalone PHP 8.1+ library for Inventory Location management (Warehouses, Stores, Consignment, etc.), Polymorphic Stock Scoping, Stock Reservations (holding stock during checkout), Stock Movements, Stock Transfers, and Low Stock Alerts.

## 🌟 Key Features

- **Framework Agnostic**: Works out of the box with any PHP 8.1+ application or framework (Vanilla PHP, Slim 4, Laravel, Symfony, CodeIgniter).
- **Polymorphic Location Scoping**: Scoping stock levels flexibly via `location_type` (`warehouse`, `store`, `consignment`, etc.) and `location_id` without coupling to fixed database schemas.
- **Stock Reservation (Hold Stock)**: Reserve stock during checkout (`reserveStock`) for $N$ minutes, preventing overselling across concurrent checkout sessions.
- **Commit & Release Pipeline**:
  - `commitReservation()`: Permanently deduct physical stock on hand when payment completes.
  - `releaseReservation()`: Restore reserved stock if cart/order is cancelled or expired.
- **Stock Transfers**: Transfer stock between locations (`transferStock()`) with symmetric audit logs.
- **Stock Movement Audit Log**: Immutable record of all stock transactions (`in`, `out`, `reserve`, `commit`, `release`, `transfer`, `adjustment`).
- **Low Stock & Reorder Point Alerts**: Dispatches `LowStockAlertEvent` when available stock drops to or below threshold.
- **Multiple Storage Drivers**: PDO Driver (MariaDB, MySQL, SQLite) & In-Memory Driver for fast unit testing.
- **PSR-14 Event Integration**: Integrated with `ttpryg/event-dispatcher`.

---

## 🗄️ Database Schema

Run the SQL script from `database/schema.sql`:

```sql
CREATE TABLE IF NOT EXISTS inventory_locations (
    id VARCHAR(36) PRIMARY KEY,
    location_type VARCHAR(50) NOT NULL, -- warehouse, store, consignment, supplier
    location_id VARCHAR(100) NOT NULL,
    name VARCHAR(150) NOT NULL,
    code VARCHAR(50) NOT NULL UNIQUE,
    address TEXT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE (location_type, location_id)
);

CREATE TABLE IF NOT EXISTS inventory_stocks (
    id VARCHAR(36) PRIMARY KEY,
    location_type VARCHAR(50) NOT NULL,
    location_id VARCHAR(100) NOT NULL,
    product_id VARCHAR(36) NOT NULL,
    variant_id VARCHAR(36) NULL,
    sku VARCHAR(100) NULL,
    quantity_on_hand INT NOT NULL DEFAULT 0,
    quantity_reserved INT NOT NULL DEFAULT 0,
    min_stock_alert INT NOT NULL DEFAULT 5,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_location_stock ON inventory_stocks (location_type, location_id, product_id, variant_id);

CREATE TABLE IF NOT EXISTS stock_reservations (
    id VARCHAR(36) PRIMARY KEY,
    inventory_stock_id VARCHAR(36) NOT NULL,
    reference_type VARCHAR(50) NOT NULL, -- cart, order
    reference_id VARCHAR(36) NOT NULL,
    quantity INT NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active', -- active, committed, released, expired
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    FOREIGN KEY (inventory_stock_id) REFERENCES inventory_stocks(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS stock_movements (
    id VARCHAR(36) PRIMARY KEY,
    inventory_stock_id VARCHAR(36) NOT NULL,
    location_type VARCHAR(50) NOT NULL,
    location_id VARCHAR(100) NOT NULL,
    type VARCHAR(30) NOT NULL, -- in, out, reserve, commit, release, transfer, adjustment
    quantity INT NOT NULL,
    reference_type VARCHAR(50) NULL,
    reference_id VARCHAR(36) NULL,
    note TEXT NULL,
    actor_id VARCHAR(36) NULL,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (inventory_stock_id) REFERENCES inventory_stocks(id) ON DELETE CASCADE
);
```

---

## 🚀 Quick Usage Example

```php
use PDO;
use Ttpryg\InventoryEngine\Repositories\PdoInventoryLocationRepository;
use Ttpryg\InventoryEngine\Repositories\PdoInventoryStockRepository;
use Ttpryg\InventoryEngine\Repositories\PdoStockReservationRepository;
use Ttpryg\InventoryEngine\Repositories\PdoStockMovementRepository;
use Ttpryg\InventoryEngine\Services\InventoryService;

// 1. Initialize PDO & Repositories
$pdo = new PDO("mysql:host=localhost;dbname=my_db", "root", "secret");

$locationRepo    = new PdoInventoryLocationRepository($pdo);
$stockRepo       = new PdoInventoryStockRepository($pdo);
$reservationRepo = new PdoStockReservationRepository($pdo);
$movementRepo    = new PdoStockMovementRepository($pdo);

$service = new InventoryService($locationRepo, $stockRepo, $reservationRepo, $movementRepo);

// 2. Create Locations (Warehouse / Store / Consignment)
$service->createLocation(
    id: 'loc-001',
    locationType: 'warehouse',
    locationId: 'wh-jkt',
    name: 'Gudang Utama Jakarta',
    code: 'WH-JKT'
);

// 3. Initialize Stock
$stock = $service->initializeStock(
    id: 'stk-001',
    locationType: 'warehouse',
    locationId: 'wh-jkt',
    productId: 'prod-100',
    initialQuantity: 100,
    minStockAlert: 10
);

// 4. Reserve Stock during Checkout (Expires in 15 minutes)
$reservation = $service->reserveStock(
    reservationId: 'res-001',
    locationType: 'warehouse',
    locationId: 'wh-jkt',
    productId: 'prod-100',
    quantity: 2,
    referenceType: 'order',
    referenceId: 'order-123',
    ttlMinutes: 15
);

// 5. Commit Stock on Payment Completion
$service->commitReservation('res-001');

// 6. Transfer Stock between Locations
$service->transferStock(
    sourceLocationType: 'warehouse',
    sourceLocationId: 'wh-jkt',
    targetLocationType: 'store',
    targetLocationId: 'store-a',
    productId: 'prod-100',
    quantity: 10,
    note: 'Restock Store A'
);
```

---

## 🧪 Testing

Run PHPUnit test suite:

```bash
vendor/bin/phpunit
```

Or using Docker Compose:

```bash
docker compose up -d
docker compose exec app vendor/bin/phpunit
```

---

## 📄 License

MIT License.
