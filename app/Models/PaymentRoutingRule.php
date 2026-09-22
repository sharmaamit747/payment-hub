<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentRoutingRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_provider_id',
        'country_code',
        'currency',
        'payment_method',
        'min_amount',
        'max_amount',
        'priority',
        'is_active',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'min_amount' => 'integer',
            'max_amount' => 'integer',
            'priority' => 'integer',
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
