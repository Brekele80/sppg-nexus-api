<?php

namespace App\Domain\Rab;

use App\Models\Profile;
use App\Models\RabVersion;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RabApprovalService
{
    public function decide(RabVersion $rab, Profile $actor, string $decision, ?string $reason): RabVersion
    {
        $decision = strtoupper(trim($decision));
        if (!in_array($decision, ['APPROVE', 'REJECT'], true)) {
            throw new \DomainException('Invalid decision');
        }

        return DB::transaction(function () use ($rab, $actor, $decision, $reason) {

            // Lock the RAB row to prevent decision races
            /** @var RabVersion $lockedRab */
            $lockedRab = RabVersion::query()
                ->where('id', (string) $rab->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedRab->status !== 'SUBMITTED') {
                throw new \DomainException('RAB is not in SUBMITTED state');
            }

            $policy = DB::table('approval_policies')
                ->where('code', 'RAB_APPROVAL')
                ->where('is_active', true)
                ->first();

            if (!$policy) {
                throw new \DomainException('Approval policy not configured');
            }

            $allowedRole = $this->resolveAllowedRoleForPolicy($actor, (string) $policy->id);
            if (!$allowedRole) {
                throw new \DomainException('Not authorized to approve this RAB');
            }

            // One decision per user is enforced by unique index; handle duplicate nicely
            try {
                DB::table('approval_decisions')->insert([
                    'id'             => (string) Str::uuid(),
                    'entity_type'    => 'RAB_VERSION',
                    'entity_id'      => (string) $lockedRab->id,
                    'policy_id'      => (string) $policy->id,
                    'decided_by'     => (string) $actor->id,
                    'decided_by_role'=> (string) $allowedRole,
                    'decision'       => (string) $decision,
                    'reason'         => $reason,
                    'created_at'     => now(),
                ]);
            } catch (QueryException $e) {
                // Postgres unique violation
                if (($e->errorInfo[0] ?? null) === '23505') {
                    throw new \DomainException('You have already submitted a decision for this RAB');
                }
                throw $e;
            }

            $approvals = DB::table('approval_decisions')
                ->where('entity_type', 'RAB_VERSION')
                ->where('entity_id', (string) $lockedRab->id)
                ->where('decision', 'APPROVE')
                ->count();

            if ($approvals >= (int) $policy->min_approvals) {
                $lockedRab->status = 'APPROVED';
                $lockedRab->decided_at = now();
                $lockedRab->decision_reason = null;
                $lockedRab->save();

                $this->audit($actor, 'RAB_APPROVED', 'rab_versions', (string) $lockedRab->id, [
                    'decision' => 'APPROVE',
                    'decided_by_role' => $allowedRole,
                    'reason' => $reason,
                    'policy_id' => (string) $policy->id,
                    'min_approvals' => (int) $policy->min_approvals,
                    'approvals_count' => (int) $approvals,
                ]);

                return $lockedRab;
            }

            $hasReject = DB::table('approval_decisions')
                ->where('entity_type', 'RAB_VERSION')
                ->where('entity_id', (string) $lockedRab->id)
                ->where('decision', 'REJECT')
                ->exists();

            if (($policy->on_reject ?? null) === 'SOFT_REJECT' && $hasReject) {
                // Soft reject pushes to NEEDS_REVISION (unless already approved, which we handled above)
                $lockedRab->status = 'NEEDS_REVISION';
                $lockedRab->decided_at = now();
                $lockedRab->decision_reason = $reason;
                $lockedRab->save();

                $this->audit($actor, 'RAB_NEEDS_REVISION', 'rab_versions', (string) $lockedRab->id, [
                    'decision' => 'REJECT',
                    'decided_by_role' => $allowedRole,
                    'reason' => $reason,
                    'policy_id' => (string) $policy->id,
                    'min_approvals' => (int) $policy->min_approvals,
                ]);
            }

            return $lockedRab;
        });
    }

    private function resolveAllowedRoleForPolicy(Profile $actor, string $policyId): ?string
    {
        $policyRoles = DB::table('approval_policy_roles')
            ->where('policy_id', $policyId)
            ->pluck('role_code')
            ->all();

        $actorRoles = $actor->roleCodes();

        foreach ($policyRoles as $r) {
            if (in_array((string) $r, $actorRoles, true)) {
                return (string) $r;
            }
        }

        return null;
    }

    private function audit(Profile $actor, string $action, string $entity, string $entityId, array $payload): void
    {
        AuditLogger::log([
            'company_id' => (string) $actor->company_id,
            'actor_id'   => (string) $actor->id,
            'action'     => (string) $action,
            'entity'     => (string) $entity,
            'entity_id'  => (string) $entityId,
            'payload'    => $payload, // can be array; AuditLogger will JSON-encode safely
        ]);
    }
}
