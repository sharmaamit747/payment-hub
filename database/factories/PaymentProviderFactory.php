<?php

namespace Database\Factories;

use App\Models\PaymentProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PaymentProviderFactory extends Factory
{
    protected $model = PaymentProvider::class;

    public function definition(): array
    {
        return [
            'code' => 'test-' . Str::lower(Str::random(8)),
            'name' => 'Test Payment Provider',
            'is_active' => true,
            'priority' => 1,
            'config' => null,
            'version' => 1,
        ];
    }
}