$ErrorActionPreference = "Stop"
$BASE = "http://127.0.0.1:8000/api"

# ========= TOKEN SANITY =========
if (-not $TOKEN -or $TOKEN.Length -lt 50) { throw "TOKEN missing / invalid" }
if (-not $ACCOUNTING_TOKEN -or $ACCOUNTING_TOKEN.Length -lt 50) { throw "ACCOUNTING_TOKEN missing / invalid" }

# ========= HELPERS =========
function New-Headers([string]$Token, [bool]$Idemp = $false) {
    $h = @{
        Authorization = "Bearer $Token"
        Accept        = "application/json"
    }
    if ($Idemp) { $h["Idempotency-Key"] = [guid]::NewGuid().ToString() }
    return $h
}

function Call($Method, $Url, $Headers, $Body = $null) {
    if ($Body -ne $null) {
        $json = $Body | ConvertTo-Json -Depth 10
        return Invoke-RestMethod -Method $Method -Uri $Url -Headers $Headers -ContentType "application/json" -Body $json
    } else {
        return Invoke-RestMethod -Method $Method -Uri $Url -Headers $Headers
    }
}

# ========= HEADERS (MUST NOT COLLIDE WITH /me OBJECT VARS) =========
$ChefHeaders       = New-Headers $TOKEN
$ChefHeadersIdemp  = New-Headers $TOKEN $true
$AccHeaders        = New-Headers $ACCOUNTING_TOKEN
$AccHeadersIdemp   = New-Headers $ACCOUNTING_TOKEN $true

# ========= /ME =========
$ChefMe = Call GET "$BASE/me" $ChefHeaders
$AccMe  = Call GET "$BASE/me" $AccHeaders

Write-Host "Chef:       $($ChefMe.email)"
Write-Host "Chef roles: $($ChefMe.roles -join ', ')"
Write-Host "Accounting: $($AccMe.email)"
Write-Host "Acc roles:  $($AccMe.roles -join ', ')"
Write-Host "Branch:     $($ChefMe.branch_id)"
Write-Host ""

# ========= 1) CREATE PR =========
Write-Host "CREATE PR"

$pr = Call POST "$BASE/prs" $ChefHeaders @{
  branch_id = $ChefMe.branch_id
  notes     = "Auto Phase-3 PR"
  items     = @(
    @{ item_name="Beras"; unit="kg";  qty=25 }
    @{ item_name="Telur"; unit="pcs"; qty=50 }
  )
}

$prId = $pr.id
Write-Host "PR ID: $prId"

# ========= 2) SUBMIT PR =========
Write-Host "SUBMIT PR"
Call POST "$BASE/prs/$prId/submit" $ChefHeadersIdemp | Out-Null

# ========= 3) CREATE RAB =========
Write-Host "CREATE RAB"

$rab = Call POST "$BASE/prs/$prId/rabs" $ChefHeaders @{
  notes="Auto RAB"
  line_items=@(
    @{ item_name="Beras"; unit="kg";  qty=25; unit_price=12000 }
    @{ item_name="Telur"; unit="pcs"; qty=50; unit_price=2000 }
  )
}

$rabId = $rab.id
Write-Host "RAB ID: $rabId"

# ========= 4) SUBMIT RAB =========
Write-Host "SUBMIT RAB"
Call POST "$BASE/rabs/$rabId/submit" $ChefHeadersIdemp | Out-Null

# ========= 5) ACCOUNTING APPROVE =========
Write-Host "ACCOUNTING APPROVE RAB"
Call POST "$BASE/rabs/$rabId/decisions" $AccHeadersIdemp @{
  decision="APPROVE"
  notes="Auto approved"
} | Out-Null
Write-Host "Approved."

# ========= 6) FETCH SUPPLIERS =========
Write-Host "FETCH SUPPLIERS"
$suppliers = Call GET "$BASE/suppliers" $ChefHeaders

# Handle both possible formats:
# - array: [ {id,code,name}, ... ]
# - paginated: { data: [ ... ] }
if ($suppliers -is [System.Array]) {
    $supplierId = $suppliers[0].id
} elseif ($suppliers.PSObject.Properties.Name -contains "data") {
    $supplierId = $suppliers.data[0].id
} else {
    throw "Unexpected suppliers response format: $($suppliers | ConvertTo-Json -Depth 5)"
}

Write-Host "Using supplier_id: $supplierId"

# ========= 7) CREATE PO =========
Write-Host "CREATE PO"
$po = Call POST "$BASE/rabs/$rabId/po" $ChefHeaders @{
    supplier_id   = $supplierId
    delivery_date = (Get-Date).AddDays(3).ToString("yyyy-MM-dd")
    payment_terms = "NET 14"
    address       = "Gudang Cabang $($ChefMe.branch_id)"
    notes         = "Auto Phase-3 PO"
}
$poId = $po.id

# ========= 8) SEND PO =========
Write-Host "SEND PO"
Call POST "$BASE/pos/$poId/send" $ChefHeadersIdemp | Out-Null

Write-Host ""
Write-Host "==============================="
Write-Host "PO READY → $poId"
Write-Host "==============================="
