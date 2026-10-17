# Changelog

All notable changes to `ttpryg/inventory-engine` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-10-17

Production-readiness hardening: concurrency safety, reservation expiry, typed
exceptions, schema portability, and a much larger test suite.

### Added

- **Transactional integrity** (`TransactionManagerInterface`):
  - `PdoTransactionManager` and `NullTransactionManager` implementations.
  - All stock-mutating operations (`initializeStock`, `reserveStock`,
    `commitReservation`, `releaseReservation`, `expireReservation`,
    `adjustStock`, `transferStock`) now run inside a single DB transaction and
    roll back on failure.
  - `PdoInventoryStockRepository`: `findByIdForUpdate()` /
    `findByLocationAndProductForUpdate()` using `SELECT ... FOR UPDATE`
    (pessimistic locking; no-op on SQLite so behaviour stays uniform).
- **Reservation expiry**:
  - `InventoryService::expireReservation()` — release held stock, record a
    `release` movement, set status `EXPIRED`, dispatch `StockExpiredEvent`.
  - `InventoryService::processExpiredReservations()` — sweep all expired
    active reservations (intended to be run from a scheduler/cron); returns
    the number of reservations released.
  - `commitReservation()` now rejects reservations past `expires_at` with
    `ReservationExpiredException`.
- **Exception hierarchy** (`src/Exceptions/`), all extending
  `InventoryException extends RuntimeException`:
  `InsufficientStockException` (with `requested`/`available`),
  `StockNotFoundException`, `ReservationNotFoundException`,
  `ReservationNotActiveException`, `ReservationExpiredException`,
  `InvalidQuantityException`. `RuntimeException` throws in `InventoryService`
  were replaced with these typed exceptions; `transferStock()` now
  distinguishes "stock not found at source" from "insufficient stock".
- **Schema split for portability**:
  - `database/schema.sqlite.sql` and `database/schema.mysql.sql` (replaces
    `database/schema.sql`, which used `CREATE INDEX IF NOT EXISTS` — not
    supported by MySQL; on MySQL/MariaDB indexes are now inline so scripts
    stay idempotent).
  - Indexes for reservation expiry scans `(status, expires_at)`, reference
    lookups `(reference_type, reference_id)`, and movement audits
    `(inventory_stock_id, created_at)`.
- **UUID support**: `Support\Uuid::uuid4()` (version/variant-correct UUIDv4
  via `random_bytes`, no new dependency) replaces `uniqid()` for internally
  generated movement/stock IDs.
- `Support\PdoRow` helper for type-safe PDO column extraction.
- Docker Compose: `mariadb:11.4` service for integration testing; tests can
  target it via `TEST_DB_DSN`/`TEST_DB_USER`/`TEST_DB_PASS` (default remains
  SQLite in-memory).
- Test suite expansion: unit tests for `releaseReservation`, `adjustStock`,
  expiry flow, low-stock events (via `SpyEventDispatcher`), error paths, and
  transfer auto-initialization; integration tests
  `PdoStockRepositoriesTest`, `PdoInventoryServiceTest` (rollback via
  `FailingStockMovementRepository`), and `PdoConcurrencyTest` (row-lock
  proof against MariaDB).
- CI: PHPStan job (level 9, clean), code-style job (`composer fix:check` =
  Pint `--test` + Rector `--dry-run`), and coverage reporting
  (`pcov` + `coverage.xml` artifact) on PHP 8.1/8.2/8.3.
- `composer analyse` script (PHPStan) for local use; `phpstan/phpstan`
  added to `require-dev`.

### Changed

- `InventoryStock` domain guards: `reserve()`/`commit()`/`release()` require
  `$quantity > 0`, `adjust()` rejects negative resulting on-hand, and the
  `max(0, …)` clamp in `getQuantityAvailable()` was removed — invariants are
  now enforced by throwing instead of silently masking corruption.
- Entity date properties (`createdAt`/`updatedAt`) are non-nullable
  (constructor already guaranteed this).
- Repository contracts gained `@return list<>` docblocks; `transactional()`
  helper is `@template`-typed.

### Fixed

- `PdoStockReservationRepository::save()` and
  `PdoInventoryLocationRepository::save()` sent a surplus `created_at`
  parameter on the UPDATE path, causing
  `PDOException: column index out of range` (the UPDATE path had never been
  exercised by tests).

## [1.0.0] - 2026-09-23

Initial release.

- Framework-agnostic inventory engine: polymorphic location scoping, stock
  reservations (reserve/commit/release), stock transfers, adjustments, and
  an immutable stock-movement audit log.
- PDO driver (MariaDB, MySQL, SQLite) and in-memory driver for unit tests.
- Low-stock alerts and domain events via `ttpryg/event-dispatcher`.

[Unreleased]: https://github.com/ttpryg/inventory-engine/compare/1.0.0...develop
[1.0.0]: https://github.com/ttpryg/inventory-engine/releases/tag/1.0.0
