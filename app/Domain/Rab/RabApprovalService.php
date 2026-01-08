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

                $this->audit($actor->id, 'RAB_APPROVED', 'RAB_VERSION', $rab->id, []);
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

                $this->audit($actor->id, 'RAB_NEEDS_REVISION', 'RAB_VERSION', $rab->id, ['reason' => $reason]);
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

    private function audit(string $actorId, string $action, string $entityType, string $entityId, array $metadata): void
    {
        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid(),
            'actor_id' => $actorId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'metadata' => empty($metadata) ? null : json_encode($metadata),
            'created_at' => now(),
        ]);
    }
}
