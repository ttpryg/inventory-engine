# InventoryEngine Library (`ttpryg/inventory-engine`)

`ttpryg/inventory-engine` is a framework-agnostic standalone PHP 8.1+ library for Inventory Location management (Warehouses, Stores, Consignment, etc.), Polymorphic Stock Scoping, Stock Reservations (holding stock during checkout), Stock Movements, Stock Transfers, and Low Stock Alerts.

## 🌟 Key Features

- **Framework Agnostic**: Works out of the box with any PHP 8.1+ application or framework (Vanilla PHP, Slim 4, Laravel, Symfony, CodeIgniter).
- **Polymorphic Location Scoping**: Scoping stock levels flexibly via `location_type` (`warehouse`, `store`, `consignment`, etc.) and `location_id` without coupling to fixed database schemas.
- **Stock Reservation (Hold Stock)**: Reserve stock during checkout (`reserveStock`) for N minutes. Combined with database transactions and row locking (`SELECT ... FOR UPDATE`), this prevents overselling across concurrent checkout sessions — see **Concurrency & Transactions** below.
- **Commit / Release / Expire Pipeline**:
  - `commitReservation()`: Permanently deduct physical stock on hand when payment completes (rejects reservations past their TTL with `ReservationExpiredException`).
  - `releaseReservation()`: Restore reserved stock if cart/order is cancelled.
  - `expireReservation()` / `processExpiredReservations()`: Release held stock when the reservation TTL lapses — see **Reservation Expiry & Scheduler** below.
- **Transactional Integrity**: Every multi-step operation (init/reserve/commit/release/expire/adjust/transfer) runs inside a single database transaction via `TransactionManagerInterface`, rolling back on any failure.
- **Stock Transfers**: Transfer stock between locations (`transferStock()`) with symmetric audit logs.
- **Stock Movement Audit Log**: Immutable record of all stock transactions (`in`, `out`, `reserve`, `commit`, `release`, `transfer`, `adjustment`).
- **Low Stock & Reorder Point Alerts**: Dispatches `LowStockAlertEvent` when available stock drops to or below threshold.
- **Multiple Storage Drivers**: PDO Driver (MariaDB, MySQL, SQLite) & In-Memory Driver for fast unit testing.
- **PSR-14 Event Integration**: Integrated with `ttpryg/event-dispatcher`.
- **Typed Exceptions**: Catch precise errors (`InsufficientStockException`, `StockNotFoundException`, `ReservationNotFoundException`, `ReservationExpiredException`, `ReservationNotActiveException`, `InvalidQuantityException`) instead of generic `RuntimeException`s.

---

## 🗄️ Database Schema

Schema files live in `database/`, one per driver:

| Driver | Schema file |
|---|---|
| SQLite | `database/schema.sqlite.sql` |
| MySQL 8 / MariaDB | `database/schema.mysql.sql` |

Both scripts are idempotent (`CREATE TABLE IF NOT EXISTS`; on MySQL/MariaDB the indexes are defined inline so re-running is safe — MySQL does not support `CREATE INDEX IF NOT EXISTS`).

Tables: `inventory_locations`, `inventory_stocks`, `stock_reservations`, `stock_movements` (with indexes for stock lookup, reservation expiry scans, reference lookups, and movement audits).

---

## 🚀 Quick Usage Example

```php
use PDO;
use Ttpryg\InventoryEngine\Repositories\PdoInventoryLocationRepository;
use Ttpryg\InventoryEngine\Repositories\PdoInventoryStockRepository;
use Ttpryg\InventoryEngine\Repositories\PdoStockReservationRepository;
use Ttpryg\InventoryEngine\Repositories\PdoStockMovementRepository;
use Ttpryg\InventoryEngine\Repositories\PdoTransactionManager;
use Ttpryg\InventoryEngine\Services\InventoryService;

// 1. Initialize PDO & Repositories
$pdo = new PDO("mysql:host=localhost;dbname=my_db", "root", "secret");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$locationRepo    = new PdoInventoryLocationRepository($pdo);
$stockRepo       = new PdoInventoryStockRepository($pdo);
$reservationRepo = new PdoStockReservationRepository($pdo);
$movementRepo    = new PdoStockMovementRepository($pdo);

// Pass PdoTransactionManager so every operation runs inside a DB transaction —
// required for concurrency safety (anti-overselling). Without it the service
// falls back to NullTransactionManager and has no transactional guarantees.
$service = new InventoryService(
    $locationRepo,
    $stockRepo,
    $reservationRepo,
    $movementRepo,
    eventDispatcher: null, // optional: any ttpryg/event-dispatcher compatible dispatcher
    transactionManager: new PdoTransactionManager($pdo)
);

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
    productId: 'prod-100',
    fromLocationType: 'warehouse',
    fromLocationId: 'wh-jkt',
    toLocationType: 'store',
    toLocationId: 'store-a',
    quantity: 10,
    note: 'Restock Store A'
);
```

---

## ⏳ Reservation Expiry & Scheduler

Every reservation carries a TTL (`ttlMinutes`, default 15). Once past `expires_at`, the held stock must be released so it becomes sellable again. Two ways to do it:

**A. Sweep all expired reservations (recommended — wire this into your cron/scheduler):**

```php
// e.g. every minute via cron: * * * * * php /path/to/expire-reservations.php
$releasedCount = $service->processExpiredReservations();
// returns the number of reservations that were expired & released
```

**B. Expire a single reservation manually:**

```php
$service->expireReservation('res-001');
```

Both paths, per reservation:

1. Status flips to `ReservationStatus::EXPIRED`.
2. Held quantity returns to available stock (`quantity_reserved` decreases).
3. A `release` stock movement is recorded (note: `Reservation expired`).
4. A `StockExpiredEvent` is dispatched.

Additionally, `commitReservation()` **rejects** reservations that are already past `expires_at` by throwing `ReservationExpiredException` — expired stock can never be sold, even if the sweep hasn't run yet. The expiry query (`findExpiredActiveReservations()`) is indexed on `(status, expires_at)`, so frequent sweeps stay cheap.

> The library has no built-in timer; scheduling `processExpiredReservations()` (at least once per minute, or faster if you use shorter TTLs) is the host application's job — e.g. Laravel scheduler, Symfony Messenger periodic, or plain cron.

---

## 🛡️ Concurrency & Transactions

All stock-mutating operations run inside a single database transaction (`transactional()` helper) and read stock rows with `SELECT ... FOR UPDATE` (pessimistic locking), so two concurrent checkouts cannot both reserve the same last unit.

- Wire `PdoTransactionManager` (as in the quick start) when using the PDO driver — without it the library falls back to `NullTransactionManager` and provides no transactional guarantees.
- On SQLite, `FOR UPDATE` is a no-op (SQLite locks the whole database file on write anyway). The in-memory driver has no locking and is meant for unit tests only.
- Covered by `PdoConcurrencyTest` (integration, MariaDB).

---

## 🧪 Testing

Run PHPUnit test suite (SQLite in-memory by default):

```bash
vendor/bin/phpunit
```

Or using Docker Compose — this runs the same suite against the bundled MariaDB service:

```bash
docker compose up -d
docker compose exec app vendor/bin/phpunit
```

To run the integration tests against your own MySQL/MariaDB instance, point the `TEST_DB_*` environment variables at it:

```bash
TEST_DB_DSN='mysql:host=127.0.0.1;dbname=inventory_engine;charset=utf8mb4' \
TEST_DB_USER=root TEST_DB_PASS=secret \
vendor/bin/phpunit
```

---

## 📄 License

MIT License.
