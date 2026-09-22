<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Payments\Enums\PaymentStatus;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'merchant_reference',
        'order_reference',
        'user_id',
        'amount',
        'amount_paid',
        'amount_refunded',
        'currency',
        'status',
        'payment_method',
        'description',
        'metadata',
        'paid_at',
        'expires_at',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'amount_paid' => 'integer',
            'amount_refunded' => 'integer',
            'status' => PaymentStatus::class,
            'metadata' => 'array',
            'paid_at' => 'datetime',
            'expires_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(
            PaymentAttempt::class
        );
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(
            PaymentTransaction::class
        );
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(
            PaymentRefund::class
        );
    }

    /**
     * Amount that can still be refunded.
     */
    public function refundableAmount(): int
    {
        return max(
            0,
            $this->amount_paid - $this->amount_refunded
        );
    }

    /**
     * Determine whether the payment is fully paid.
     */
    public function isPaid(): bool
    {
        return $this->status === PaymentStatus::PAID;
    }

    /**
     * Determine whether the payment has expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null
            && $this->expires_at->isPast();
    }

    public function reconciliations()
    {
        return $this->hasMany(
            \App\Models\PaymentReconciliation::class
        );
    }

    public function reservedRefundAmount(): int
    {
        return (int) $this->refunds()
            ->whereIn('status', [
                \App\Payments\Enums\RefundStatus::PENDING->value,
                \App\Payments\Enums\RefundStatus::PROCESSING->value,
                \App\Payments\Enums\RefundStatus::UNKNOWN->value,
            ])
            ->sum('amount');
    }
}