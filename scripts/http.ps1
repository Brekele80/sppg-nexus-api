function With-Idem {
  param([hashtable]$Headers, [string]$Key = (New-Guid).Guid)
  $h = @{}
  foreach ($k in $Headers.Keys) { $h[$k] = [string]$Headers[$k] }
  $h["Idempotency-Key"] = [string]$Key
  return $h
}

function Call {
  param(
    [Parameter(Mandatory=$true)][ValidateSet('GET','POST','PUT','PATCH','DELETE')]$Method,
    [Parameter(Mandatory=$true)][string]$Url,
    [Parameter(Mandatory=$true)][hashtable]$Headers,
    $Body = $null
  )

  try {
    if ($null -ne $Body) {
      $json = $Body | ConvertTo-Json -Depth 20
      $resp = Invoke-RestMethod -Method $Method -Uri $Url -Headers $Headers -Body $json -ContentType "application/json"
    } else {
      $resp = Invoke-RestMethod -Method $Method -Uri $Url -Headers $Headers
    }

    return [pscustomobject]@{
      StatusCode = 200
      Body       = $resp
    }
  } catch {
    $r = $_.Exception.Response
    if ($r -and $r.GetResponseStream()) {
      $reader = New-Object System.IO.StreamReader($r.GetResponseStream())
      $body = $reader.ReadToEnd()
      throw "HTTP ERROR calling $Method $Url`nStatusCode: $([int]$r.StatusCode)`n$body"
    }
    throw
  }
}
