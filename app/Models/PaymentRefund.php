<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Payments\Enums\RefundStatus;

class PaymentRefund extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_id',
        'payment_attempt_id',
        'payment_provider_id',
        'payment_transaction_id',
        'refund_uuid',
        'provider_refund_id',
        'idempotency_key',
        'amount',
        'currency',
        'status',
        'reason',
        'failure_code',
        'failure_reason',
        'metadata',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'metadata' => 'array',
            'status' => \App\Payments\Enums\RefundStatus::class,
            'processed_at' => 'datetime',
        ];
    }

    public function payment()
    {
        return $this->belongsTo(
            \App\Models\Payment::class
        );
    }

    public function attempt()
    {
        return $this->belongsTo(
            \App\Models\PaymentAttempt::class,
            'payment_attempt_id'
        );
    }

    public function provider()
    {
        return $this->belongsTo(
            \App\Models\PaymentProvider::class,
            'payment_provider_id'
        );
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(
            PaymentTransaction::class,
            'payment_transaction_id'
        );
    }

    public function isSuccessful(): bool
    {
        return $this->status === RefundStatus::SUCCEEDED;
    }

    public function isPending(): bool
    {
        return in_array(
            $this->status,
            [
                RefundStatus::PENDING,
                RefundStatus::PROCESSING,
                RefundStatus::UNKNOWN,
            ],
            true
        );
    }

    public function isFailed(): bool
    {
        return in_array(
            $this->status,
            [
                RefundStatus::FAILED,
                RefundStatus::CANCELLED,
            ],
            true
        );
    }
}