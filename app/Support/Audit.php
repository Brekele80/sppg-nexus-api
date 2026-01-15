<?php

namespace App\Support;

use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;

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

    public static function log(Request $request, string $action, string $entity, string $entityId, array $payload): void
    {
        $companyId = self::companyId($request);
        $actorId   = self::actorId($request);

        // If missing context, do not hard-crash controllers by default.
        if (!$companyId || !$actorId) {
            return;
        }

        $safe = self::redact($payload);

        // Simple size guard (avoid multi-MB jsonb rows)
        $json = json_encode($safe, JSON_UNESCAPED_UNICODE);
        if ($json !== false && strlen($json) > 200_000) { // 200KB
            $safe = ['truncated' => true, 'note' => 'payload exceeded 200KB'];
        }

        AuditLogger::log([
            'company_id' => $companyId,
            'actor_id'   => $actorId,
            'action'     => $action,
            'entity'     => $entity,
            'entity_id'  => $entityId,
            'payload'    => $safe,
        ]);
    }
}
