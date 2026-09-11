<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('user_email');
            $table->string('sku');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 10, 2);
            $table->unsignedTinyInteger('discount_percentage')->default(0);
            $table->decimal('payable_amount', 10, 2);
            $table->string('invoice_number')->nullable()->unique();
            $table->enum('status', ['pending', 'completed', 'failed'])->default('pending');
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->index('user_email');
            $table->index('sku');
            $table->index('status');
            $table->index(['user_email', 'sku', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
