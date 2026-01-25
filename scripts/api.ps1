function ApiPost {
  param(
    [Parameter(Mandatory=$true)][string]$Path,
    [Parameter(Mandatory=$true)][hashtable]$Body,
    [hashtable]$Headers = @{}
  )

  if (-not $script:BASE_URL) { throw "BASE_URL is not set" }
  if (-not $script:TOKEN)    { throw "TOKEN is not set" }
  if (-not $script:COMPANY_ID) { throw "COMPANY_ID is not set" }

  $p = $Path
  if (-not $p.StartsWith("/")) { $p = "/$p" }
  if (-not $p.StartsWith("/api/")) { $p = "/api$p" }

  $h = @{
    Authorization  = "Bearer $script:TOKEN"
    "X-Company-Id" = $script:COMPANY_ID
  }

  foreach ($key in $Headers.Keys) { $h[$key] = $Headers[$key] }

  $json = $Body | ConvertTo-Json -Depth 20

  Invoke-RestMethod -Method POST -Uri ($script:BASE_URL + $p) `
    -Headers $h -ContentType "application/json" -Body $json
}
