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

        DB::table('audit_ledger')->insert([
            'id'         => (string) Str::uuid(),
            'company_id' => $event['company_id'],
            'actor_id'   => $event['actor_id'],
            'action'     => (string) $event['action'],
            'entity'     => (string) $event['entity'],
            'entity_id'  => $event['entity_id'],
            'payload'    => $event['payload'],
            'created_at' => now(),
        ]);
    }
}
