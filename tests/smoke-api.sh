#!/usr/bin/env bash
# Smoke test suite for the Flash Sale Inventory API.
# Idempotent: resets DB + cache on every run.

set -u

BASE="http://localhost:8000/api"
PASS=0
FAIL=0

# Reset DB + cache so the suite is idempotent across re-runs.
echo "Resetting database (migrate:fresh --seed) and cache..."
docker compose exec -T app php artisan migrate:fresh --seed --force >/dev/null 2>&1
docker compose exec -T app php artisan cache:clear            >/dev/null 2>&1
sleep 2

run() {
  local label="$1"; shift
  local expected="$1"; shift
  local response
  response=$(curl -s -w "\n%{http_code}" "$@")
  local body=$(printf '%s' "$response" | head -n -1)
  local code=$(printf '%s' "$response" | tail -n 1)
  if [ "$code" = "$expected" ]; then
    PASS=$((PASS+1))
    printf '  [PASS] %-50s -> %s\n' "$label" "$code"
  else
    FAIL=$((FAIL+1))
    printf '  [FAIL] %-50s -> got %s, expected %s\n' "$label" "$code" "$expected"
  fi
  printf '         %s\n' "$body"
}

echo "============================================================"
echo " Flash Sale Inventory - API Smoke Test"
echo "============================================================"

echo
echo "[1] Validation: missing fields (422)"
run "POST /purchase (no body)" 422 -X POST "$BASE/purchase" \
  -H "Accept: application/json" -H "X-User-Email: alice@test.com"

echo
echo "[2] Inactive product (400)"
run "POST /purchase inactive SKU-1003" 400 -X POST "$BASE/purchase" \
  -H "Accept: application/json" -H "X-User-Email: alice@test.com" \
  -H "Content-Type: application/json" -d '{"sku":"SKU-1003","quantity":1}'

echo
echo "[3] Product not found (404)"
run "POST /purchase unknown SKU" 404 -X POST "$BASE/purchase" \
  -H "Accept: application/json" -H "X-User-Email: alice@test.com" \
  -H "Content-Type: application/json" -d '{"sku":"SKU-9999","quantity":1}'

echo
echo "[4] Insufficient stock (400)"
run "POST /purchase SKU-1001 qty=99" 400 -X POST "$BASE/purchase" \
  -H "Accept: application/json" -H "X-User-Email: alice@test.com" \
  -H "Content-Type: application/json" -d '{"sku":"SKU-1001","quantity":99}'

echo
echo "[5] Successful purchase (200)"
run "POST /purchase SKU-1002 qty=1" 200 -X POST "$BASE/purchase" \
  -H "Accept: application/json" -H "X-User-Email: alice@test.com" \
  -H "Content-Type: application/json" -d '{"sku":"SKU-1002","quantity":1}'

echo
echo "[6] Cooldown active (429)"
run "POST /purchase SKU-1002 qty=1" 429 -X POST "$BASE/purchase" \
  -H "Accept: application/json" -H "X-User-Email: alice@test.com" \
  -H "Content-Type: application/json" -d '{"sku":"SKU-1002","quantity":1}'

echo
echo "[7] Different email bypasses cooldown (200)"
run "POST /purchase SKU-1002 (bob)" 200 -X POST "$BASE/purchase" \
  -H "Accept: application/json" -H "X-User-Email: bob@test.com" \
  -H "Content-Type: application/json" -d '{"sku":"SKU-1002","quantity":1}'

echo
echo "[8] Different sku bypasses cooldown (200)"
run "POST /purchase SKU-1001 (alice)" 200 -X POST "$BASE/purchase" \
  -H "Accept: application/json" -H "X-User-Email: alice@test.com" \
  -H "Content-Type: application/json" -d '{"sku":"SKU-1001","quantity":1}'

echo
echo "============================================================"
printf " RESULTS: %d passed, %d failed\n" "$PASS" "$FAIL"
echo "============================================================"
exit $FAIL
