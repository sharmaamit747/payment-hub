<?php

namespace App\Payments\Services;

use App\Models\IdempotencyKey;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class IdempotencyService
{
    public function hashRequest(array $data): string
    {
        return hash(
            'sha256',
            json_encode(
                $this->normalize($data),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            )
        );
    }

    public function get(string $key): ?IdempotencyKey
    {
        return IdempotencyKey::where('key', $key)->first();
    }

    public function create(
        string $key,
        string $requestHash,
        ?int $expiresInMinutes = 1440
    ): IdempotencyKey {
        return DB::transaction(function () use (
            $key,
            $requestHash,
            $expiresInMinutes
        ) {
            return IdempotencyKey::create([
                'key' => $key,
                'request_hash' => $requestHash,
                'status' => 'processing',
                'expires_at' => now()->addMinutes($expiresInMinutes),
            ]);
        });
    }

    public function validateExisting(
        IdempotencyKey $existing,
        string $requestHash
    ): void {
        if ($existing->request_hash !== $requestHash) {
            throw new RuntimeException(
                'Idempotency key was already used with a different request.'
            );
        }
    }

    public function complete(
        IdempotencyKey $idempotencyKey,
        int $paymentId,
        int $responseStatus,
        array $responseBody
    ): IdempotencyKey {
        $idempotencyKey->update([
            'status' => 'completed',
            'payment_id' => $paymentId,
            'response_status' => $responseStatus,
            'response_body' => $responseBody,
        ]);

        return $idempotencyKey->refresh();
    }

    private function normalize(array $data): array
    {
        ksort($data);

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->normalize($value);
            }
        }

        return $data;
    }
}