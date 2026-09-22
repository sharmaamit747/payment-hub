<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PaymentReconciliation extends Model
{
    use HasFactory;
    protected $fillable = [
        'payment_provider_id',
        'payment_id',
        'payment_attempt_id',
        'reconciliation_uuid',
        'provider_transaction_id',
        'provider_payment_id',
        'provider_order_id',
        'local_status',
        'provider_status',
        'local_amount',
        'provider_amount',
        'local_currency',
        'provider_currency',
        'status',
        'mismatch_type',
        'resolution_status',
        'local_data',
        'provider_data',
        'resolution_note',
        'detected_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'local_amount' => 'integer',
            'provider_amount' => 'integer',
            'local_data' => 'array',
            'provider_data' => 'array',
            'detected_at' => 'datetime',
            'resolved_at' => 'datetime',
            'status' => \App\Payments\Enums\ReconciliationStatus::class,
            'resolution_status' =>
                \App\Payments\Enums\ReconciliationResolutionStatus::class,
            'mismatch_type' =>
                \App\Payments\Enums\ReconciliationMismatchType::class,
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(
            PaymentProvider::class,
            'payment_provider_id'
        );
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(
            PaymentAttempt::class,
            'payment_attempt_id'
        );
    }

    public function isMatched(): bool
    {
        return $this->status === 'matched';
    }

    public function isMismatch(): bool
    {
        return $this->status === 'mismatch';
    }

    public function isResolved(): bool
    {
        return $this->resolution_status === 'resolved';
    }
}