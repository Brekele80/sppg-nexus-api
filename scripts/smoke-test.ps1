$ErrorActionPreference = "Stop"
$BASE = "http://127.0.0.1:8000/api"

if (-not $TOKEN -or $TOKEN.Length -lt 50) {
    throw "TOKEN missing - set Chef token first"
}

Write-Host ""
Write-Host "========== SPPG NEXUS SMOKE TEST =========="

# 1. Health
Write-Host "`n[1] API HEALTH"
$health = Invoke-RestMethod "$BASE/health"
if (-not $health.ok) { throw "Health failed" }
Write-Host "OK API reachable"

# 2. Auth
Write-Host "`n[2] AUTH CHECK"
$me = Invoke-RestMethod -Headers @{ Authorization="Bearer $TOKEN" } "$BASE/me"
Write-Host "OK Authenticated as $($me.email)"
Write-Host ("Roles: " + ($me.roles -join ", "))
Write-Host ("Branch: " + $me.branch_id)

# 3. Inventory
Write-Host "`n[3] INVENTORY LEDGER"
$inv = Invoke-RestMethod -Headers @{ Authorization="Bearer $TOKEN" } "$BASE/inventory"

if ($inv.Count -eq 0) { throw "Inventory empty" }

$inv | Format-Table item_name, on_hand
Write-Host "OK Inventory ledger"

Write-Host ""
Write-Host "========== SYSTEM READY =========="
