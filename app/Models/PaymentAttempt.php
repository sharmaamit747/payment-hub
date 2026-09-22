<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Payments\Enums\PaymentAttemptStatus;

class PaymentAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_id',
        'payment_provider_id',
        'attempt_uuid',
        'provider_payment_id',
        'provider_order_id',
        'idempotency_key',
        'amount',
        'status',
        'request_payload',
        'response_payload',
        'failure_code',
        'failure_reason',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'status' => PaymentAttemptStatus::class,
            'request_payload' => 'array',
            'response_payload' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(
            Payment::class
        );
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(
            PaymentProvider::class,
            'payment_provider_id'
        );
    }

    public function getRouteKeyName(): string
    {
        return 'attempt_uuid';
    }

    public function isSuccessful(): bool
    {
        return $this->status === PaymentAttemptStatus::SUCCEEDED;
    }

    public function isPending(): bool
    {
        return in_array(
            $this->status,
            [
                PaymentAttemptStatus::CREATED,
                PaymentAttemptStatus::INITIATED,
                PaymentAttemptStatus::PROCESSING,
                PaymentAttemptStatus::PENDING,
                PaymentAttemptStatus::UNKNOWN,
            ],
            true
        );
    }

    public function isFailed(): bool
    {
        return in_array(
            $this->status,
            [
                PaymentAttemptStatus::FAILED,
                PaymentAttemptStatus::CANCELLED,
                PaymentAttemptStatus::EXPIRED,
            ],
            true
        );
    }

    public function reconciliations()
    {
        return $this->hasMany(
            \App\Models\PaymentReconciliation::class
        );
    }
}