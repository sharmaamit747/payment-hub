<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentProvider extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'is_active',
        'priority',
        'config',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'priority' => 'integer',
            'config' => 'array',
            'version' => 'integer',
        ];
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(
            PaymentProviderCredential::class
        );
    }

    public function routingRules(): HasMany
    {
        return $this->hasMany(
            PaymentRoutingRule::class
        );
    }

    public function webhooks(): HasMany
    {
        return $this->hasMany(
            PaymentWebhook::class
        );
    }

    public function activeCredentials()
    {
        return $this->credentials()
            ->where('is_active', true);
    }

    public function health()
    {
        return $this->hasOne(
            \App\Models\PaymentProviderHealth::class
        );
    }

    public function reconciliations()
    {
        return $this->hasMany(
            \App\Models\PaymentReconciliation::class
        );
    }
}