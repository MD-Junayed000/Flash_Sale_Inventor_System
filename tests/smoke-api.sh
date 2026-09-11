#!/usr/bin/env bash
# ----------------------------------------------------------------------------
# tests/smoke-api.sh
#
# End-to-end smoke test that drives a running Flash Sale Inventory System
# through its public API. The script is intentionally non-interactive so it
# can be wired into CI just as easily as run by hand.
#
# Usage:
#   bash tests/smoke-api.sh
#
# Assumptions:
#   * Docker compose stack is up (`docker compose up -d`).
#   * Migrations + seeders have been run (`docker compose exec app php artisan
#     migrate --seed`).
#
# What it does:
#   1. Sanity-checks the health endpoint.
#   2. Logs in as the demo user `alice@test.com` and captures a Sanctum token.
#   3. Lists products and grabs the SKU of the first active item.
#   4. Places a purchase (qty=2).
#   5. Replays the same purchase with the same Idempotency-Key and asserts
#      the second response is identical (no duplicate order).
#   6. Lists orders for the authenticated user.
# ----------------------------------------------------------------------------
set -euo pipefail

BASE_URL="${BASE_URL:-http://localhost:8000/api/v1}"
EMAIL="${EMAIL:-alice@test.com}"
PASSWORD="${PASSWORD:-password}"
SKU="${SKU:-SKU-1001}"
QUANTITY="${QUANTITY:-2}"

# -- colour helpers ----------------------------------------------------------
green()  { printf '\033[32m%s\033[0m\n' "$*"; }
red()    { printf '\033[31m%s\033[0m\n' "$*"; }
header() { printf '\n\033[36m=== %s ===\033[0m\n' "$*"; }

# -- 1. health ---------------------------------------------------------------
header "1. Health check"
HEALTH=$(curl -fsS "$BASE_URL/health" | head -c 200)
echo "$HEALTH"
[[ "$HEALTH" == *'"status":"ok"'* ]] || { red "health endpoint did not return ok"; exit 1; }
green "health endpoint OK"

# -- 2. login ----------------------------------------------------------------
header "2. Sanctum login"
LOGIN=$(curl -fsS -X POST "$BASE_URL/auth/login" \
    -H "Content-Type: application/json" \
    -d "{\"email\":\"$EMAIL\",\"password\":\"$PASSWORD\"}")
TOKEN=$(echo "$LOGIN" | grep -oE '"token":"[^"]+' | cut -d'"' -f4)
[[ -n "${TOKEN:-}" ]] || { red "login did not return a token; response: $LOGIN"; exit 1; }
green "got Sanctum token"

AUTH="Authorization: Bearer $TOKEN"

# -- 3. list products --------------------------------------------------------
header "3. List products (page 1)"
PRODUCTS=$(curl -fsS "$BASE_URL/products?page=1&per_page=10" -H "$AUTH")
echo "$PRODUCTS" | head -c 400
echo "..."
[[ "$PRODUCTS" == *"\"sku\":\"$SKU\""* ]] || { red "expected SKU $SKU not present in product list"; exit 1; }
green "product $SKU is in the catalogue"

# -- 4. purchase -------------------------------------------------------------
header "4. Place purchase ($SKU x $QUANTITY)"
IDEMP="$(uuidgen 2>/dev/null || cat /proc/sys/kernel/random/uuid)"
PURCHASE=$(curl -fsS -X POST "$BASE_URL/purchase" \
    -H "$AUTH" \
    -H "Content-Type: application/json" \
    -H "Idempotency-Key: $IDEMP" \
    -d "{\"sku\":\"$SKU\",\"quantity\":$QUANTITY,\"payment_ref\":\"stripe_test_$(date +%s)\"}")
echo "$PURCHASE" | head -c 400
echo "..."
[[ "$PURCHASE" == *'"success":true'* ]] || { red "purchase did not succeed; response: $PURCHASE"; exit 1; }
green "purchase accepted"

# -- 5. idempotent replay ----------------------------------------------------
header "5. Replay with same Idempotency-Key (should be identical)"
REPLAY=$(curl -fsS -X POST "$BASE_URL/purchase" \
    -H "$AUTH" \
    -H "Content-Type: application/json" \
    -H "Idempotency-Key: $IDEMP" \
    -d "{\"sku\":\"$SKU\",\"quantity\":$QUANTITY,\"payment_ref\":\"stripe_test_$(date +%s)\"}")
[[ "$PURCHASE" == "$REPLAY" ]] || { red "idempotency replay returned a different body"; exit 1; }
green "replay returned the same body (idempotent)"

# -- 6. order list -----------------------------------------------------------
header "6. List my orders"
ORDERS=$(curl -fsS "$BASE_URL/orders" -H "$AUTH")
echo "$ORDERS" | head -c 400
echo "..."
[[ "$ORDERS" == *'"success":true'* ]] || { red "order list failed"; exit 1; }
green "order list OK"

green ""
green "ALL SMOKE CHECKS PASSED"
