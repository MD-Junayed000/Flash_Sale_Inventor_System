# Implementation Notes

This document explains the important decisions in the Flash Sale Inventory System in plain language. The goal is to make the code easy to review and easy for a junior developer to explain during the hiring test.

## 1. What the system guarantees

A purchase is accepted only when:

1. The caller has a valid Sanctum token.
2. The request contains a valid SKU and a quantity from 1 to 10.
3. The product exists and is active.
4. Enough stock can be reserved inside a database transaction.
5. An order can be written successfully.

After acceptance, the customer receives an order ID immediately. A queue worker later creates the invoice and completes the order.

The main invariant is:

```text
successful order quantity <= starting stock
```

That invariant is more important than the response speed or the discount feature.

## 2. Why the project uses these components

### Laravel

Laravel supplies routing, validation, authentication, database transactions, queues, migrations, and testing conventions. Using those framework features keeps the implementation understandable and avoids custom infrastructure for common problems.

### MySQL

The challenge specifically requires MySQL. InnoDB row locks and transactions make it suitable for reserving scarce inventory safely.

### Redis

Redis is used for three fast, shared operations:

- Cooldown keys with expiry
- Short-lived distributed locks
- The order queue

The queue and cache are separate logical uses even though they share one Redis service. In production they can be separated if capacity or failure isolation requires it.

### Sanctum

The API uses bearer tokens instead of trusting an email header. A client cannot simply change an `X-User-Email` value to act as another customer. The authenticated user's email is copied into the activity log and order for auditability.

### Blade

The challenge excludes Livewire and Inertia. Blade provides a simple server-rendered product CRUD interface without adding a second frontend application.

## 3. Purchase service design

`PurchaseService` is the business boundary. The controller does not decide stock rules, discount rules, or queue behavior.

The service performs these operations:

1. Acquire a short Redis lock for the customer/SKU pair.
2. Reject an active cooldown.
3. Start a database transaction.
4. Find the product with `SELECT ... FOR UPDATE`.
5. Reject missing or inactive products.
6. Decrement stock with a conditional update.
7. Calculate and store the mystery discount.
8. Insert a pending order.
9. Commit the transaction.
10. Write the cooldown key, dispatch `ProcessOrder`, and record the successful activity attempt.

Known domain failures are converted into typed exceptions. The controller maps those exceptions to consistent HTTP responses.

## 4. Why stock cannot be oversold

The product row is locked while the transaction is active, and the stock update includes its own guard:

```sql
UPDATE products
SET stock_quantity = stock_quantity - :quantity
WHERE id = :product_id
  AND stock_quantity >= :quantity;
```

If the update affects zero rows, the service throws `InsufficientStockException`. If the order insert or another transaction step fails, the database rolls back the decrement.

The row lock protects the read/validate/write sequence. The conditional update is a second defense and makes the invariant explicit at the database boundary.

A real production load test should use multiple HTTP workers and MySQL, not only sequential test calls. The application logic is designed for that race: all competing buyers update the same product row through the database lock.

## 5. Cooldown and idempotency

These are different protections:

- **Cooldown:** the same customer cannot buy the same SKU again for 60 seconds.
- **Idempotency:** a retried request with the same `Idempotency-Key` returns the original successful response instead of creating another order.

Both use Redis locks so the check and write cannot be separated by a competing request. Idempotency also includes the request body in its cache key; reusing a key for a different body does not replay the previous body.

Only successful HTTP responses are cached for idempotency. Validation and business failures remain retryable.

## 6. Transactions and queue timing

Stock and the pending order are written in one database transaction. The queue dispatch occurs after the transaction callback completes, so the worker cannot normally see an uncommitted order.

The order deliberately starts as `pending`. `ProcessOrder` then:

- Generates an invoice number from the order ID and current date.
- Changes the order to `completed`.
- Dispatches `OrderCompleted`.

If all retries fail, Laravel writes the job to `failed_jobs`; the job also changes the order to `failed` and stores the exception message in `failure_reason`.

This is a reserve-then-finalize workflow. It is appropriate for a hiring exercise because the expensive or unreliable work is outside the HTTP request. A real payment integration would add payment authorization and compensation rules before using this pattern in production.

## 7. Mystery discount

The configured default distribution is:

| Result       | Probability |
| ------------ | ----------: |
| No discount  |         75% |
| 10% discount |         20% |
| 50% discount |          5% |

`DiscountService` reads the weights from `config/purchase.php`, selects a bucket with `random_int()`, and calculates the payable amount before the order is stored. The order keeps both the percentage and final amount, so the value cannot change later when the queue runs.

Money is stored in MySQL decimal columns and returned to clients as integer cents. This avoids asking JavaScript clients to perform floating-point currency arithmetic.

## 8. Activity logging

Every service-level purchase attempt produces one activity row:

- email
- SKU
- quantity
- success or failed status
- failure reason when applicable
- Laravel timestamps

Successful logs are written after the order is accepted. Known product, stock, cooldown, and unexpected failures are logged before the exception reaches the controller.

The activity log is intentionally explicit rather than hidden in a model observer. A reviewer can follow the complete purchase path in one service class.

## 9. API and Blade boundaries

The API is versioned under `/api/v1` so future changes can be introduced without silently changing an existing client contract.

Public API endpoints expose the active catalogue and health probes. Sanctum protects orders and purchases. Blade routes handle product administration because the challenge asks for CRUD but does not require an admin API.

In a larger product, the Blade CRUD routes should be placed behind an administrator role or policy. The current exercise keeps the CRUD surface intentionally small.

## 10. Queue and operational choices

The queue uses Redis in Docker because it provides fast worker throughput and is already needed for distributed locks. The job has explicit connection and queue settings, retry attempts, backoff, timeout, and a retry deadline.

The Compose stack separates the HTTP app, worker, scheduler, MySQL, and Redis services. The PHP image is built from `docker/php/Dockerfile`, so a fresh clone does not depend on a pre-existing local image.

The readiness endpoint checks database and cache access before reporting the application as ready. Structured logs include correlation IDs so one request can be followed through the API and worker logs.

## 11. Test strategy

The test suite covers:

- Authentication and validation
- Unknown products
- Insufficient stock
- Successful order creation
- Queue completion in the synchronous test environment
- Cooldown responses
- Idempotent replay
- Stock invariants under repeated contention
- Discount distribution

The Docker smoke script covers the deployed HTTP path: health, login, catalogue, purchase, idempotent replay, and order history.

For a production readiness review, we need to add a multi-process load test, database deadlock retry metrics, queue latency metrics and administrator authorization.
