<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds columns required by the v1 purchase flow that were not present in
 * the original orders migration:
 *
 *  - user_id          : FK so events can broadcast on a private channel and
 *                       OrderController can filter by ownership via the
 *                       authenticated user id.
 *  - payment_ref      : external payment provider reference (idempotent at
 *                       the gateway level too).
 *  - idempotency_key  : client-supplied request idempotency key (also
 *                       enforced by IdempotencyKeyMiddleware, but stored
 *                       here for forensic / support queries).
 *
 * Schema is additive and back-filled safely: existing rows get NULL.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', static function (Blueprint $table): void {
            if (! Schema::hasColumn('orders', 'user_id')) {
                $table->foreignId('user_id')
                      ->nullable()
                      ->after('user_email')
                      ->constrained('users')
                      ->nullOnDelete();
            }

            if (! Schema::hasColumn('orders', 'payment_ref')) {
                $table->string('payment_ref', 128)
                      ->nullable()
                      ->after('unit_price')
                      ->index();
            }

            if (! Schema::hasColumn('orders', 'idempotency_key')) {
                $table->string('idempotency_key', 128)
                      ->nullable()
                      ->after('payment_ref')
                      ->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', static function (Blueprint $table): void {
            if (Schema::hasColumn('orders', 'idempotency_key')) {
                $table->dropColumn('idempotency_key');
            }
            if (Schema::hasColumn('orders', 'payment_ref')) {
                $table->dropColumn('payment_ref');
            }
            if (Schema::hasColumn('orders', 'user_id')) {
                $table->dropForeign(['user_id']);
                $table->dropColumn('user_id');
            }
        });
    }
};
