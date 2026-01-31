<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class JournalAuthorityClient
{
    public static function seal(
        string $companyId,
        string $branchId,
        string $sourceType,
        string $sourceId,
        array $journalPayload
    ): array {
        $endpoint = config('services.signing.url') . '/api/ingest';

        $response = Http::withToken(config('services.signing.token'))
            ->timeout(15)
            ->post($endpoint, [
                'company_id' => $companyId,
                'branch_id'  => $branchId,
                'source_type' => $sourceType,
                'source_id'   => $sourceId,
                'payload'     => $journalPayload
            ]);

        if (!$response->ok()) {
            throw new RuntimeException(
                "Journal signing failed: " . $response->body()
            );
        }

        return $response->json();
    }
}
