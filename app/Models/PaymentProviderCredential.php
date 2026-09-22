<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentProviderCredential extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_provider_id',
        'environment',
        'public_key',
        'secret_key',
        'webhook_secret',
        'additional_credentials',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            /*
             * Sensitive credentials are encrypted before
             * being stored in the database.
             */
            'public_key' => 'encrypted',
            'secret_key' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'additional_credentials' => 'encrypted:array',
            'is_active' => 'boolean',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(
            PaymentProvider::class,
            'payment_provider_id'
        );
    }
}

