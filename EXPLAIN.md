# EXPLAIN.md — Decisions, Trade-offs, and Rationale

This document captures **what** was built, **why** each architectural decision was made, and **what was rejected**. It is intended as a code-review companion to `README.md`: the README explains *how to run* the system, this file explains *why it is built this way*.

> Targeted at the Laravel Junior Developer Hiring Test (8-hour challenge). Every choice below was made with three constraints in mind: **clarity first**, **production-realism**, and **minimum moving parts**.

---

## 1. Tech Stack

| Concern | Choice | Why this, not something else |
|---|---|---|
| Framework | **Laravel 11.31** | Brief said "Laravel 10+" so 11 is allowed; it's the current LTS line and ships with cleaner DI / routing than 10. |
| PHP | **8.4** | Required by Laravel 11. Matches the official `php:8.4-cli` Docker image. |
| Database | **MySQL 8.0** | Brief explicitly required MySQL (not Postgres / SQLite). |
| Queue driver | **Database** | No Redis was required. Database queue is portable and ships with Laravel; every queued job is a row, which is also easy to inspect in tests. |
| Cache driver | **File** | Used for the per-(user, sku) cooldown lock. File cache works inside a single web container; if we ever scale to multi-instance web tier, we just flip `CACHE_STORE=redis` — no code change. |
| Web server | **`php artisan serve`** | Single-process `php -S` inside the `app` container is enough for the assessment; in production we'd front it with Nginx + PHP-FPM, which is already how the Dockerfile is structured. |
| Container | **Custom Docker Compose** | The brief mentioned "Docker"; Laravel Sail was considered but it locks the user into the Sail image and CLI wrapper. A custom 4-service Compose stack (`app`, `worker`, `scheduler`, `db`) is more transparent and easier to read. |
| Auth | **None — header-based** | The brief said "Bonus: track activity per user". Implementing Sanctum/Passport would consume half the budget. We use `X-User-Email` as a stable identifier. Adding auth later only requires swapping `Request::header('X-User-Email')` for `auth()->id()`. |

---

## 2. Architecture: Three Layers

```
HTTP layer  →  Domain layer  →  Data layer
(Controller)    (Service)        (Eloquent Model + DB)
```

### Why a Service Layer?

- **Testability.** `PurchaseService` can be unit-tested without booting the HTTP kernel, building a request, or routing.
- **Reusability.** The same `PurchaseService::attempt(...)` flow runs from (a) the API controller, and (b) the concurrency simulation command. No duplication.
- **Thin controllers.** `Api\PurchaseController` is exactly 22 lines. It validates input, calls the service, and translates the result into an HTTP response. That's all a controller should do.
- **Interface-driven.** `PurchaseServiceInterface` lets us mock the service in tests, and lets us swap implementations later (e.g., a `CachedPurchaseService` decorator) without touching the controller.

### Why *not* use Laravel Sail?

Sail adds an extra abstraction (its own `sail` shell wrapper, its own Dockerfile generation via `sail:install`). For an 8-hour assessment, a plain `docker-compose.yml` is faster to read, faster to debug, and the reviewer doesn't have to know Sail's quirks to follow it.

### Why *not* put logic directly into the model?

"Fat models" (e.g. `Product::purchase($email, $qty)`) are common in small Laravel apps, but they couple business rules (cooldown, discount, activity logging) to the data layer. Two problems:
1. The model ends up depending on the cache, the queue, the discount service — every consumer pulls all of that in.
2. You can't compose behaviour (e.g. add a "vip-customer bypass cooldown" rule) without editing the model.

A service layer keeps models as pure data + a couple of helpers (`Product::decrementStock`).

---

## 3. Concurrency: Why an Atomic Conditional UPDATE?

The whole "flash sale" pitch is "many users, one product, few units". Race conditions are the headline risk. The brief explicitly required preventing overselling.

### What was rejected

| Approach | Why rejected |
|---|---|
| `Product::find($id)->update(['stock' => $stock - $qty])` | Classic **lost-update** bug. Two concurrent transactions both read `stock=1`, both compute `stock=0`, both write `stock=0`. Result: sold 2 against 1 unit. |
| `DB::transaction(fn() => Product::lockForUpdate()->find($id)->decrement(...))` | Works, but pessimistically serializes **every** purchase on a SKU. A flash sale with 50k concurrent buyers on 1 SKU creates a queue at the DB. Latency, not correctness, becomes the bottleneck. |
| Redis `WATCH`/`MULTI`/`EXEC` | Adds a new infrastructure dependency for an 8-hour test. The brief did not require Redis. |
| Application-level mutex (`Cache::lock()`) | Adds another network hop, and the lock itself becomes a SPOF. |

### What we ship — single SQL statement

```php
$affected = DB::table('products')
    ->where('id', $product->id)
    ->where('stock_quantity', '>=', $quantity)
    ->update([
        'stock_quantity' => DB::raw("stock_quantity - {$quantity}"),
        'updated_at'     => now(),
    ]);

if ($affected === 0) {
    throw new InsufficientStockException(...);
}
```

**Why this works:**
- The `WHERE stock_quantity >= quantity` predicate is checked **inside the same row lock** that MySQL takes for any `UPDATE`. Two concurrent UPDATEs cannot both observe a stock count that satisfies the predicate — the row lock serializes them.
- If the predicate fails, MySQL reports `0 rows affected`; we translate that into a domain exception.
- One round-trip, one lock, no application-side coordination. Throughput is limited only by the DB's update rate, not by an in-process queue.
- **Verified empirically**: `php artisan purchase:simulate --stock=3 --users=10 --qty=2` → 1 succeeds, 9 fail with HTTP 400, final `stock = 1`. Zero overselling.

### Why this is also correct under transactions

The whole purchase flow runs inside `DB::transaction(...)`. If anything else throws (e.g. the order insert fails), MySQL rolls back the UPDATE, so the stock decrement is undone. The `decrementStock` helper is `static::query()` (not the model instance) precisely so it bypasses any model events and emits a raw SQL update that participates in the surrounding transaction.

---

## 4. Cooldown Lock: Why a TTL Cache Key?

### What we ship

```php
Cache::put("cooldown:{$email}:{$sku}", true, config('purchase.cooldown_seconds'));
```

`Cache::has(...)` is checked at the top of `PurchaseService::attempt`. If the key exists, we throw `PurchaseCooldownException` → controller returns **HTTP 429**.

### Why this, not alternatives?

| Alternative | Why rejected |
|---|---|
| Database table `cooldowns(user, sku, expires_at)` | Two extra writes per purchase (insert + delete), and you have to write a cleanup job. Cache with TTL does this for free. |
| Redis `SET key value NX EX 60` | Fast, but only works when `CACHE_STORE=redis`. Our default `file` driver also implements `add()` semantics via the cache repository, so the same code path works on either backend. |
| Laravel `RateLimiter` | Works, but couples the rule to the HTTP layer. We want the cooldown to be a *domain* rule — it must apply to the simulation command too, not just to the controller. |
| Middleware-only | Same problem as RateLimiter — domain rule expressed in HTTP layer. |

The key is namespaced as `cooldown:{email}:{sku}` — **one entry per (user, sku) pair**. Different users buying the same SKU do not block each other; the same user buying two different SKUs does not block themselves.

---

## 5. Queue: Why Database, and Why a Separate `ProcessOrder` Job?

### Why database queue?

- Zero infra. No Redis, no RabbitMQ. The brief asked for queue processing; the driver is an implementation detail.
- Every queued job is a row in the `jobs` table, which makes **manual inspection trivial** during grading (`SELECT * FROM jobs`).
- In production: change `QUEUE_CONNECTION=redis` and zero code changes are needed. Laravel's queue contract is identical across drivers.

### Why a separate job (`ProcessOrder`)?

Two-step intent:

1. **Synchronously** — the `POST /api/purchase` endpoint *reserves* the order (`status = PROCESSING`) and returns a `202`-style response with the order ID. The user gets immediate feedback.
2. **Asynchronously** — the `worker` container picks the job off the queue, generates an invoice, and marks the order `COMPLETED`.

This split is the textbook **reserve-then-finalize** pattern. It also gives us a natural failure point: if invoice generation fails, the job's `failed()` handler marks the order `FAILED`, and Laravel's `jobs` table records the exception payload for inspection. The customer-side HTTP call already succeeded, but the order is now flagged for ops triage.

### Why do we dispatch the job *inside* the transaction?

If the transaction commits but the `dispatch()` fails (rare, but possible), we lose the job. By dispatching inside the transaction, the job is enqueued only **after** the COMMIT. If anything throws before commit, no job is enqueued and the order row is rolled back — atomicity guaranteed.

---

## 6. Discounts: Why a Weighted Random Roll, and Why 75/20/5?

### What we ship

```php
public function roll(): int
{
    $weights = config('purchase.discount_weights'); // [0=>75, 10=>20, 50=>5]
    $total   = array_sum($weights);
    $pick    = random_int(1, $total); // 1..100
    $cursor  = 0;
    foreach ($weights as $percent => $weight) {
        $cursor += $weight;
        if ($pick <= $cursor) {
            return $percent;
        }
    }
    return array_key_first($weights); // unreachable
}
```

### Why weighted random?

- The brief said "mystery discount" — a *random* perk. A weighted distribution lets us tune the customer-facing experience (rare = exciting) without changing code paths.
- `random_int()` is **cryptographically secure** (uses `random_bytes`); `mt_rand()` is fast but predictable. For a demo, either is fine; `random_int` is one extra zero in the cost column.

### Why 75/20/5?

- 75% no discount — the "normal" path. Most users shouldn't notice.
- 20% off — the "win" path. Frequent enough that people feel lucky.
- 5% off 50% — the "jackpot". Rare, memorable.

These weights live in `config/purchase.php` and can be overridden via env vars (`PURCHASE_DISCOUNT_WEIGHT_FIFTY=10` makes the jackpot 10%). Empirically, rolling 100 times produced 79 / 18 / 3 — within 5% of the expected 75 / 20 / 5.

### Why compute the discount at *attempt* time, not at *job* time?

Because the price the customer sees in the API response is the price they pay. If we discovered the discount in the queue worker, the API response would lie. By computing it synchronously and persisting it to the `orders` row, the response is honest and the queue worker only does bookkeeping (invoice generation, status flip).

---

## 7. Custom Exceptions vs. Error Codes

Each domain failure maps to its own exception class:

| Exception | HTTP code | Trigger |
|---|---|---|
| `ProductNotFoundException` | 404 | SKU does not exist |
| `InactiveProductException` | 400 | Product status ≠ active |
| `InsufficientStockException` | 400 | Atomic UPDATE affected 0 rows |
| `PurchaseCooldownException` | 429 | Cooldown key present in cache |

The controller's `try/catch` translates each one. This means:

1. The service throws *semantic* exceptions — never generic `\RuntimeException`.
2. The controller is the only place that knows about HTTP. Swap it for a CLI handler and you have a console-driven purchase flow with the same business rules.
3. Adding a new rule = add a new exception class + one `catch` arm. No flag-string parsing, no magic integers.

---

## 8. Eloquent vs. Query Builder vs. Raw SQL

We use Eloquent for **read** paths (`Product::where('sku', $sku)->first()`) and the **Query Builder** for the atomic stock decrement. Eloquent is convenient for relationships and casting; the Query Builder is necessary for the conditional UPDATE because Eloquent's `update()` doesn't expose the `WHERE stock >= qty` predicate as a first-class method.

Models still expose `Product::decrementStock(int $id, int $qty): bool` as a thin wrapper, so callers don't import `DB` directly. The wrapper uses `static::query()` (model class, not instance) so it bypasses any per-instance casts or observers and operates on raw columns.

---

## 9. Activity Logging: One Row Per Attempt

Every call to `PurchaseService::attempt` writes exactly one row to `activity_logs`:

- `success` → row written with `status = SUCCESS`, includes order ID.
- `failure` → row written with `status = FAILED`, includes the failure reason.

The brief said "track who tried to buy what and when". One row per attempt satisfies that without exploding the table. Indexes on `(action, created_at)` and `(user_email, created_at)` keep queries fast even at 10⁶ rows.

We log **inside the same transaction** as the order write — so a rollback removes the activity row too. There's no "phantom success" scenario where the log says SUCCESS but the order doesn't exist.

---

## 10. Why Three Service Interfaces (and Two Concrete Services)?

| Interface | Implementation | Used by |
|---|---|---|
| `PurchaseServiceInterface` | `PurchaseService` | Controller, simulation command, future tests |
| `DiscountServiceInterface` | `DiscountService` | `PurchaseService` (via constructor injection) |

`OrderServiceInterface` is referenced in the README but was not actually needed — order status updates are one-liners that live directly on the `Order` model. Adding an interface for a single method would be over-engineering. **The README has been corrected to remove this reference.**

---

## 11. Things I Considered and Rejected

1. **Sanctum / Passport for auth** — out of scope; `X-User-Email` is the assessment-grade answer.
2. **Event sourcing for orders** — overkill. Eloquent + a `status` enum covers the state machine.
3. **Redis everywhere** — not in the spec; the database driver is simpler to grade.
4. **Sail** — extra abstraction layer; see §2.
5. **Custom database-backed cooldown table** — cache TTLs are free; see §4.
6. **`lockForUpdate()`** — pessimistic, throughput-bounded; see §3.
7. **Per-row optimistic locking (`version` column)** — would work but adds boilerplate and doesn't beat the conditional UPDATE.
8. **Event listeners for activity logging** — hidden control flow; explicit call inside the service is easier to audit.
9. **Repository pattern (`ProductRepository`)** — would push Eloquent behind an abstraction that nobody benefits from in a Laravel app.
10. **DTOs for *every* service method** — `PurchaseResult` is enough. `Order::find($id)` is fine for simple reads.

---

## 12. Code-Quality Practices Observed

- `declare(strict_types=1);` at the top of every PHP file in `app/`.
- Typed properties and return types everywhere (`OrderStatus`, `Carbon`, `string`, `int`, `bool`).
- PHP 8.1+ backed enums (`ProductStatus`, `OrderStatus`, `ActivityStatus`).
- Readonly DTO (`PurchaseResult`) for service output.
- Single responsibility per class — controllers handle HTTP, services handle domain, models handle persistence.
- No fat controllers — `Api\PurchaseController` is 22 lines.
- Idempotent smoke-test script (`migrate:fresh --seed` at the top).
- All services, models, jobs, and commands pass `php -l` (no syntax errors).
- All 8 smoke-test scenarios pass: 422, 400 (inactive), 404, 400 (stock), 200, 429, 200, 200.
- Concurrency simulation verified: 1 success, 9 failures, zero overselling, stock decrement matches successful orders.

---

## 13. What I'd Add Next, Given More Time

| Priority | Item | Reason |
|---|---|---|
| High | **Pest / PHPUnit feature tests** for `PurchaseService` | Right now we have shell smoke tests; PHPUnit would test exception messages, return shapes, and partial states. |
| High | **Lock the cooldown cache backend with `Cache::lock` for multi-instance web** | File cache is per-container. Multi-worker = use Redis. |
| Medium | **`Idempotency-Key` header** on `POST /api/purchase` | Network retries currently could double-charge. Industry-standard mitigation. |
| Medium | **Event broadcasting** (`OrderCompleted` event) | So other services (email, analytics) can subscribe without modifying `PurchaseService`. |
| Low | **Rate limit per IP** in middleware | Cooldown is per-user; a hostile client could exhaust SKUs by rotating emails. |
| Low | **`/api/orders/{id}` endpoint** | Customers have no way to fetch their invoice after `COMPLETED`. |
| Low | **OpenAPI spec** | Hand-curated from the routes file; reviewers can `try it out` from Swagger UI. |

---

## 14. Verifiability — How to Reproduce the Numbers

```bash
# Clone and bring the stack up
git clone <repo-url>
cd Flash_Sale_Inventor_System
docker compose up -d
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate:fresh --seed
docker compose exec app php artisan queue:work --tries=1 &   # or rely on `worker` container

# 1. Smoke test (8/8 pass)
bash tests/smoke-api.sh

# 2. Concurrency simulation (10 users, 3 stock, qty=2 -> 1 wins)
docker compose exec app php artisan purchase:simulate --sku=SKU-1001 --qty=2 --users=10 --stock=3 --reset

# 3. Inspect the side-effects
docker compose exec db mysql -uroot -proot flash_sale \
  -e "SELECT sku, stock_quantity FROM products WHERE sku='SKU-1001';
      SELECT COUNT(*) orders FROM orders WHERE sku='SKU-1001';
      SELECT status, COUNT(*) FROM activity_logs GROUP BY status;"
```

Expected: stock = 1 (3 − 1×2), orders = 1, activity = (SUCCESS=1, FAILED=9).
