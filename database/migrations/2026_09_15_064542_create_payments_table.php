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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
              /*
             * Public-safe internal identifier.
             *
             * Never expose the auto-increment ID as the
             * primary payment reference to external clients.
             */
            $table->uuid('uuid')->unique();
            /*
             * Business/merchant reference.
             *
             * Example:
             * PAY-20260915-000001
             *
             * Must be unique.
             */
            $table->string('merchant_reference', 100)
                ->unique();
                 /*
             * Optional order reference.
             *
             * We intentionally don't add a foreign key yet
             * because your actual order/business table may
             * differ.
             */
            $table->string('order_reference', 100)
                ->nullable();
            /*
             * Optional application user.
             *
             * We won't add a foreign key yet because the
             * payment module should remain decoupled from
             * the application's user implementation.
             */
            $table->unsignedBigInteger('user_id')
                ->nullable();
            /*
             * Amount in the smallest currency unit.
             *
             * INR:
             *
             * ₹100.00 = 10000
             *
             * USD:
             *
             * $100.00 = 10000
             *
             * NEVER use FLOAT/DOUBLE for money.
             */
            $table->unsignedBigInteger('amount');
            /*
             * Amount successfully paid/captured.
             *
             * Normally:
             *
             * pending payment = 0
             * fully paid       = amount
             *
             * Useful for partial capture scenarios.
             */
            $table->unsignedBigInteger('amount_paid')
                ->default(0);
            /*
             * Amount refunded.
             *
             * This allows us to calculate:
             *
             * refundable =
             * amount_paid - amount_refunded
             */
            $table->unsignedBigInteger('amount_refunded')
                ->default(0);
            /*
             * ISO-4217 currency.
             *
             * Examples:
             * INR
             * USD
             * EUR
             */
            $table->char('currency', 3);
            /*
             * Current payment state.
             *
             * We will later replace magic strings with
             * PaymentStatus enum handling.
             */
            $table->string('status', 40)
                ->default('created');
                 /*
             * Requested payment method.
             *
             * Examples:
             * card
             * upi
             * netbanking
             * wallet
             */
            $table->string('payment_method', 50)
                ->nullable();
             /*
             * Short business description.
             */
            $table->string('description', 500)
                ->nullable();
            /*
             * Additional non-sensitive business metadata.
             *
             * NEVER put secrets/card data here.
             */
            $table->json('metadata')
                ->nullable();
            /*
             * When payment was successfully completed.
             */
            $table->timestamp('paid_at')
                ->nullable();
            /*
             * Payment expiration.
             */
            $table->timestamp('expires_at')
                ->nullable();
            /*
             * Optimistic concurrency/version field.
             *
             * Used later to protect against concurrent
             * state modifications.
             */
            $table->unsignedBigInteger('version')
                ->default(1);
            $table->timestamps();
             /*
             * Frequently used admin/API filters.
             */
            $table->index(
                ['status', 'created_at'],
                'payments_status_created_idx'
            );
            /*
             * User payment history.
             */
            $table->index(
                ['user_id', 'created_at'],
                'payments_user_created_idx'
            );
            /*
             * Order lookup.
             */
            $table->index(
                ['order_reference'],
                'payments_order_ref_idx'
            );
            /*
             * Currency + status reporting.
             */
            $table->index(
                ['currency', 'status'],
                'payments_currency_status_idx'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
