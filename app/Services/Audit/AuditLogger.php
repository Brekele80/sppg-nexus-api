<?php

namespace App\Services\Audit;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AuditLogger
{
    /**
     * Append-only audit event.
     *
     * Required keys:
     * - company_id (uuid)
     * - actor_id (uuid)
     * - action (text)
     * - entity (text)
     * - entity_id (uuid)
     * - payload (array|object|string(JSON))
     */
    public static function log(array $event): void
    {
        $required = ['company_id', 'actor_id', 'action', 'entity', 'entity_id', 'payload'];
        foreach ($required as $k) {
            if (!array_key_exists($k, $event)) {
                throw new \InvalidArgumentException("Missing audit field: {$k}");
            }
        }

        $payload = $event['payload'];

        if (is_string($payload)) {
            // Validate JSON string
            json_decode($payload, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \InvalidArgumentException(
                    "Audit payload string is not valid JSON: " . json_last_error_msg()
                );
            }
            $payloadJson = $payload;
        } else {
            // Encode arrays/objects safely
            $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($payloadJson === false) {
                throw new \RuntimeException("Failed to json_encode audit payload: " . json_last_error_msg());
            }
        }

        // IMPORTANT:
        // For a jsonb column, sending a JSON text parameter is fine; Postgres stores it as jsonb.
        DB::table('audit_ledger')->insert([
            'id'         => (string) Str::uuid(),
            'company_id' => (string) $event['company_id'],
            'actor_id'   => (string) $event['actor_id'],
            'action'     => (string) $event['action'],
            'entity'     => (string) $event['entity'],
            'entity_id'  => (string) $event['entity_id'],
            'payload'    => $payloadJson, // bind as string; column is jsonb
            'created_at' => now(),
        ]);
    }
}
