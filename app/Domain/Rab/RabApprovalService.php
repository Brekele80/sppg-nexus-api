<?php

namespace App\Domain\Rab;

use App\Models\Profile;
use App\Models\RabVersion;
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
            $rab->refresh();

            if ($rab->status !== 'SUBMITTED') {
                throw new \DomainException('RAB is not in SUBMITTED state');
            }

            $policy = DB::table('approval_policies')->where('code', 'RAB_APPROVAL')->where('is_active', true)->first();
            if (!$policy) {
                throw new \DomainException('Approval policy not configured');
            }

            $allowedRole = $this->resolveAllowedRoleForPolicy($actor, $policy->id);
            if (!$allowedRole) {
                throw new \DomainException('Not authorized to approve this RAB');
            }

            // One decision per user is enforced by unique index; handle duplicate nicely
            DB::table('approval_decisions')->insert([
                'id' => (string) Str::uuid(),
                'entity_type' => 'RAB_VERSION',
                'entity_id' => $rab->id,
                'policy_id' => $policy->id,
                'decided_by' => $actor->id,
                'decided_by_role' => $allowedRole,
                'decision' => $decision,
                'reason' => $reason,
                'created_at' => now(),
            ]);

            $approvals = DB::table('approval_decisions')
                ->where('entity_type', 'RAB_VERSION')
                ->where('entity_id', $rab->id)
                ->where('decision', 'APPROVE')
                ->count();

            if ($approvals >= (int) $policy->min_approvals) {
                $rab->status = 'APPROVED';
                $rab->decided_at = now();
                $rab->decision_reason = null;
                $rab->save();

                $this->audit($actor, 'RAB_APPROVED', 'rab_versions', $rab->id, []);
                return $rab;
            }

            $hasReject = DB::table('approval_decisions')
                ->where('entity_type', 'RAB_VERSION')
                ->where('entity_id', $rab->id)
                ->where('decision', 'REJECT')
                ->exists();

            if (($policy->on_reject ?? null) === 'SOFT_REJECT' && $hasReject) {
                // Soft reject pushes to NEEDS_REVISION (unless already approved, which we handled above)
                $rab->status = 'NEEDS_REVISION';
                $rab->decided_at = now();
                $rab->decision_reason = $reason;
                $rab->save();

                $this->audit($actor, 'RAB_NEEDS_REVISION', 'rab_versions', $rab->id, ['reason' => $reason]);
            }

            return $rab;
        });
    }

    private function resolveAllowedRoleForPolicy(Profile $actor, string $policyId): ?string
    {
        $policyRoles = DB::table('approval_policy_roles')->where('policy_id', $policyId)->pluck('role_code')->all();
        $actorRoles = $actor->roleCodes();

        foreach ($policyRoles as $r) {
            if (in_array($r, $actorRoles, true)) {
                return $r;
            }
        }
        return null;
    }

    private function audit(Profile $actor, string $action, string $entity, string $entityId, array $payload): void
    {
        DB::table('audit_ledger')->insert([
            'id'         => (string) Str::uuid(),
            'company_id' => (string) $actor->company_id,
            'actor_id'   => (string) $actor->id,
            'action'     => $action,
            'entity'     => $entity,          // e.g. 'rab_versions'
            'entity_id'  => $entityId,
            'payload'    => $payload,         // jsonb in postgres; array is fine
            'created_at' => now(),
        ]);
    }
}
