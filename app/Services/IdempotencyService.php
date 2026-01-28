<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use RuntimeException;

class IdempotencyService
{
    public static function lock(
        Request $request,
        string $companyId,
        string $key
    ): ?array {
        $hash = hash('sha256', $request->getContent());

        return DB::transaction(function () use ($companyId, $key, $hash) {
            $row = DB::table('api_idempotency_keys')
                ->where('company_id', $companyId)
                ->where('key', $key)
                ->lockForUpdate()
                ->first();

            if ($row) {
                if ($row->request_hash !== $hash) {
                    throw new RuntimeException('Idempotency key reuse with different payload');
                }

                if ($row->status === 'completed') {
                    return json_decode($row->response, true);
                }

                throw new RuntimeException('Request already in progress');
            }

            DB::table('api_idempotency_keys')->insert([
                'company_id'   => $companyId,
                'key'          => $key,
                'request_hash'=> $hash,
                'status'      => 'processing',
                'created_at'  => now()
            ]);

            return null;
        });
    }

    public static function complete(
        string $companyId,
        string $key,
        array $response
    ): void {
        DB::table('api_idempotency_keys')
            ->where('company_id', $companyId)
            ->where('key', $key)
            ->update([
                'status'   => 'completed',
                'response'=> json_encode($response)
            ]);
    }
}
