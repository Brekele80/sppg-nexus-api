<?php

namespace App\Support;

use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Audit
{
    public static function actorId(Request $request): ?string
    {
        $u = $request->attributes->get('auth_user');
        return $u?->id ? (string) $u->id : null;
    }

    public static function companyId(Request $request): ?string
    {
        $cid = $request->header('X-Company-Id');
        return $cid ? (string) $cid : null;
    }

    private static function redact(mixed $value): mixed
    {
        $deny = ['token','password','access_token','refresh_token','authorization','supabase','jwt'];

        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $key = is_string($k) ? strtolower($k) : $k;
                if (is_string($key) && in_array($key, $deny, true)) {
                    $out[$k] = '[REDACTED]';
                } else {
                    $out[$k] = self::redact($v);
                }
            }
            return $out;
        }

        return $value;
    }

    public static function log(Request $request, string $action, string $entity, string $entityId, $payload): void
    {
        // Company context is injected by RequireCompanyContext middleware
        $companyId = (string) ($request->attributes->get('company_id') ?? '');

        // Actor comes from auth middleware
        $actor = $request->attributes->get('auth_user');
        $actorId = $actor ? (string) $actor->id : null;

        // Hard guardrails: audit should never write cross-tenant or anonymously
        if ($companyId === '') {
            abort(401, 'Missing company context');
        }
        if (!$actorId) {
            abort(401, 'Unauthenticated');
        }

        // Ensure payload is valid JSON for jsonb column
        if (is_string($payload)) {
            json_decode($payload, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                abort(500, 'Audit payload is not valid JSON: ' . json_last_error_msg());
            }
            $payloadJson = $payload;
        } else {
            $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($payloadJson === false) {
                abort(500, 'Failed to json_encode audit payload: ' . json_last_error_msg());
            }
        }

        DB::table('audit_ledger')->insert([
            'id'         => (string) Str::uuid(),
            'company_id' => $companyId,
            'actor_id'   => $actorId,
            'action'     => (string) $action,
            'entity'     => (string) $entity,
            'entity_id'  => (string) $entityId,
            'payload'    => $payloadJson, // bind JSON text; column is jsonb
            'created_at' => now(),
        ]);
    }
}
