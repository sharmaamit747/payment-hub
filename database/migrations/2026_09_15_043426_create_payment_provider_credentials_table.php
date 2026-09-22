<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payment_provider_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_provider_id')
                ->constrained('payment_providers')
                ->cascadeOnDelete();
            /*
             * sandbox / production
             */
            $table->string('environment', 20);
            /*
             * Public/client key.
             *
             * Encrypted at application level.
             */
            $table->text('public_key')->nullable();
            /*
             * Secret/API key.
             *
             * NEVER store plaintext.
             */
            $table->text('secret_key')->nullable();
             /*
             * Provider webhook signing secret.
             *
             * NEVER store plaintext.
             */
            $table->text('webhook_secret')->nullable();
             /*
             * Additional encrypted provider credentials.
             *
             * Example:
             * merchant_id
             * salt
             * account_id
             */
            $table->text('additional_credentials')->nullable();
            $table->boolean('is_active')->default(false);
            /*
             * A provider can have only one credential
             * set per environment.
             */
            $table->timestamps();

            $table->unique(
                ['payment_provider_id', 'environment'],
                'provider_environment_unique'
            );

            $table->index([
                'payment_provider_id',
                'is_active'
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_provider_credentials');
    }
};
