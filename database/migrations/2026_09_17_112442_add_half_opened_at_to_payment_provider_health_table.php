<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_provider_health', function (Blueprint $table) {
            $table->timestamp('half_opened_at')
                ->nullable()
                ->after('opened_at');

            $table->index(
                ['state', 'half_opened_at'],
                'provider_health_half_open_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('payment_provider_health', function (Blueprint $table) {
            $table->dropIndex('provider_health_half_open_idx');
            $table->dropColumn('half_opened_at');
        });
    }
};