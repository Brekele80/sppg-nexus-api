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
        // RequireCompanyContext should already validate this header matches auth_user.company_id
        $cid = $request->header('X-Company-Id');
        return $cid ? (string) $cid : null;
    }

    public static function log(Request $request, string $action, string $entity, string $entityId, array $payload): void
    {
        $companyId = self::companyId($request);
        $actorId   = self::actorId($request);

        if (!$companyId || !$actorId) {
            // In production: fail closed is safer. But you may prefer to throw only on protected routes.
            throw new \RuntimeException('Audit requires company_id and actor_id');
        }

        AuditLogger::log([
            'company_id' => $companyId,
            'actor_id'   => $actorId,
            'action'     => $action,
            'entity'     => $entity,
            'entity_id'  => $entityId,
            'payload'    => $payload,
        ]);
    }
}
