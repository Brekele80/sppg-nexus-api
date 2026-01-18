php artisan optimize:clear
php artisan scribe:generate

if (!(Test-Path "docs/api")) { New-Item -ItemType Directory -Path "docs/api" | Out-Null }

Copy-Item "public/docs/openapi.yaml" "docs/api/openapi.yaml" -Force
Copy-Item "public/docs/collection.json" "docs/api/collection.json" -Force

Write-Host "OK: regenerated docs/api/openapi.yaml + docs/api/collection.json"
