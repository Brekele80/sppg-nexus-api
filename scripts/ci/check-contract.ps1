$ErrorActionPreference = "Stop"

Write-Host "== Regenerating API contract =="
powershell -ExecutionPolicy Bypass -File .\regenerate-api-contract.ps1

Write-Host "== Checking for drift =="
$dirty = git status --porcelain
if ($dirty) {
  Write-Host "ERROR: API contract drift detected. Run regenerate-api-contract.ps1 and commit outputs."
  git status
  exit 1
}

Write-Host "OK: contract is stable"
