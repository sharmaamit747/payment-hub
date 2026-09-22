<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateRefundRequest extends FormRequest
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
            'amount' => [
                'required',
                'integer',
                'min:1',
            ],

            'reason' => [
                'nullable',
                'string',
                'max:255',
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