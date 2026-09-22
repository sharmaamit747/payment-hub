<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();

            $table->string('key', 150)->unique();

            $table->string('request_hash', 64);

            $table->string('status', 30)->default('processing');

            $table->unsignedBigInteger('payment_id')->nullable();

            $table->unsignedSmallInteger('response_status')->nullable();

            $table->json('response_body')->nullable();

            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            $table->index(
                ['status', 'created_at'],
                'idempotency_status_created_idx'
            );

            $table->index(
                ['expires_at'],
                'idempotency_expires_idx'
            );

            $table->foreign('payment_id')
                ->references('id')
                ->on('payments')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};