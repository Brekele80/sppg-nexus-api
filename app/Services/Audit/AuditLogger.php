<?php

namespace App\Services\Audit;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AuditLogger
{
    /**
     * Append-only audit event.
     *
     * IMPORTANT:
     * - Always include company_id
     * - actor_id should be the supabase profile/user UUID (auth.uid()) you already use in API
     * - entity is a stable string: 'purchase_orders', 'goods_receipts', etc.
     * - action is a stable verb: 'create', 'submit', 'receive', 'confirm', 'reject', 'update', etc.
     * - payload should contain inputs + computed diffs (but no secrets)
     */
    public static function log(array $event): void
    {
        $required = ['company_id', 'actor_id', 'action', 'entity', 'entity_id', 'payload'];
        foreach ($required as $k) {
            if (!array_key_exists($k, $event)) {
                throw new \InvalidArgumentException("Missing audit field: {$k}");
            }
        }

        // Ensure payload is valid JSON for jsonb
        $payload = $event['payload'];

        if (is_string($payload)) {
            // If caller already passed JSON, keep it but validate it is JSON
            json_decode($payload, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \InvalidArgumentException("Audit payload string is not valid JSON: " . json_last_error_msg());
            }
            $payloadJson = $payload;
        } else {
            // Encode arrays/objects safely
            $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($payloadJson === false) {
                throw new \RuntimeException("Failed to json_encode audit payload: " . json_last_error_msg());
            }
        }

        DB::table('audit_ledger')->insert([
            'id'         => (string) Str::uuid(),
            'company_id' => (string) $event['company_id'],
            'actor_id'   => (string) $event['actor_id'],
            'action'     => (string) $event['action'],
            'entity'     => (string) $event['entity'],
            'entity_id'  => (string) $event['entity_id'],

            // IMPORTANT: bind as JSON text, Postgres will store it as jsonb
            'payload'    => DB::raw("'" . str_replace("'", "''", $payloadJson) . "'::jsonb"),

            'created_at' => now(),
        ]);
    }
}
