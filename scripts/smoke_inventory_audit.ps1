$branchId = "edcc1144-e115-4492-9d6b-4ca9f9fda98e"

# A) Branch-wide read-only audit
$kA = "audit-onhand-branch-readonly-$branchId"
$ro = ApiPost "/inventory/audit/on-hand" @{ branch_id=$branchId; fix=$false } @{ "Idempotency-Key" = $kA }
$ro.mismatch_count
$ro.items | Select-Object inventory_item_id,item_name,unit,cached_on_hand,lots_remaining_sum,delta_cached_minus_truth,match

# B) Branch-wide fix audit (only if you want to repair all drift)
$kB = "audit-onhand-branch-fix-$branchId"
$fx = ApiPost "/inventory/audit/on-hand" @{ branch_id=$branchId; fix=$true } @{ "Idempotency-Key" = $kB }
$fx.fixed_count
