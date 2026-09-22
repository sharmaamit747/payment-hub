<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Payments\Enums\TransactionStatus;
use App\Payments\Enums\TransactionType;

class PaymentTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_id',
        'payment_attempt_id',
        'payment_provider_id',
        'transaction_uuid',
        'provider_transaction_id',
        'idempotency_key',
        'type',
        'status',
        'amount',
        'currency',
        'failure_code',
        'failure_reason',
        'metadata',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'status' => TransactionStatus::class,
            'amount' => 'integer',
            'metadata' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(
            Payment::class
        );
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(
            PaymentAttempt::class,
            'payment_attempt_id'
        );
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(
            PaymentProvider::class,
            'payment_provider_id'
        );
    }

    public function isSuccessful(): bool
    {
        return $this->status === TransactionStatus::SUCCEEDED;
    }

    public function isPending(): bool
    {
        return in_array(
            $this->status,
            [
                TransactionStatus::PENDING,
                TransactionStatus::PROCESSING,
                TransactionStatus::UNKNOWN,
            ],
            true
        );
    }

    public function isFailed(): bool
    {
        return in_array(
            $this->status,
            [
                TransactionStatus::FAILED,
                TransactionStatus::CANCELLED,
            ],
            true
        );
    }
}