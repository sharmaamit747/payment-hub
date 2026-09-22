<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreatePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'idempotency_key' => $this->header('Idempotency-Key'),
        ]);
    }

    public function rules(): array
    {
        return [
            'merchant_reference' => [
                'required',
                'string',
                'max:100',
            ],

            'order_reference' => [
                'nullable',
                'string',
                'max:100',
            ],

            'amount' => [
                'required',
                'integer',
                'min:1',
            ],

            'currency' => [
                'required',
                'string',
                'size:3',
                'uppercase',
            ],

            'payment_method' => [
                'nullable',
                'string',
                'max:50',
            ],

            'description' => [
                'nullable',
                'string',
                'max:500',
            ],

            'country_code' => [
                'nullable',
                'string',
                'size:2',
                'uppercase',
            ],

            'metadata' => [
                'nullable',
                'array',
            ],

            'idempotency_key' => [
                'required',
                'string',
                'min:16',
                'max:150',
            ],
        ];
    }
}