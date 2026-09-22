<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Payments\Enums\WebhookStatus;

class PaymentWebhook extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_provider_id',
        'payment_id',
        'event_uuid',
        'provider_event_id',
        'event_type',
        'status',
        'payload',
        'headers',
        'signature',
        'attempts',
        'received_at',
        'processed_at',
        'failed_at',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => WebhookStatus::class,
            'payload' => 'array',
            'headers' => 'array',
            'attempts' => 'integer',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
            'failed_at' => 'datetime',
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
        return $this->belongsTo(
            Payment::class
        );
    }

    public function isProcessed(): bool
    {
        return $this->status === WebhookStatus::PROCESSED;
    }

    public function isPending(): bool
    {
        return in_array(
            $this->status,
            [
                WebhookStatus::RECEIVED,
                WebhookStatus::PROCESSING,
            ],
            true
        );
    }

    public function isFailed(): bool
    {
        return $this->status === WebhookStatus::FAILED;
    }
}