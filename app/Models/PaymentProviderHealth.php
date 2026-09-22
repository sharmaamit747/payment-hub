<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentProviderHealth extends Model
{
    protected $table = 'payment_provider_health';

    protected $fillable = [
        'payment_provider_id',
        'state',
        'consecutive_failures',
        'failure_threshold',
        'recovery_timeout_seconds',
        'opened_at',
        'next_retry_at',
        'last_failure_at',
        'last_success_at',
    ];

    protected function casts(): array
    {
        return [
            'consecutive_failures' => 'integer',
            'failure_threshold' => 'integer',
            'recovery_timeout_seconds' => 'integer',
            'opened_at' => 'datetime',
            'half_opened_at' => 'datetime',
            'next_retry_at' => 'datetime',
            'last_failure_at' => 'datetime',
            'last_success_at' => 'datetime',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(
            PaymentProvider::class,
            'payment_provider_id'
        );
    }

    public function isClosed(): bool
    {
        return $this->state === 'closed';
    }

    public function isOpen(): bool
    {
        return $this->state === 'open';
    }

    public function isHalfOpen(): bool
    {
        return $this->state === 'half_open';
    }
}