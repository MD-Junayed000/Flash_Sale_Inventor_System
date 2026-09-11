<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->string('email')->nullable();
            $table->string('sku')->nullable();
            $table->unsignedInteger('quantity')->nullable();
            $table->enum('status', ['success', 'failed']);
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->index('email');
            $table->index('sku');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
