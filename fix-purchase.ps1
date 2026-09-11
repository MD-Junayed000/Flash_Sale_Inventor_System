$f="app/Services/PurchaseService.php"
$content = Get-Content $f -Raw
$before  = "?string `$idempotencyKey = null," + [Environment]::NewLine + "    ): PurchaseResult"
$after   = "?string `$idempotencyKey = null," + [Environment]::NewLine + "        ?int    `$userId         = null," + [Environment]::NewLine + "    ): PurchaseResult"
if ($content.Contains($before)) { $content = $content.Replace($before, $after); Write-Host "REPLACED 1" } else { Write-Host "NOT FOUND 1" }
$b2 = "use (`$email, `$sku, `$quantity, `$paymentRef, `$idempotencyKey)"
$a2 = "use (`$email, `$sku, `$quantity, `$paymentRef, `$idempotencyKey, `$userId)"
if ($content.Contains($b2)) { $content = $content.Replace($b2, $a2); Write-Host "REPLACED 2" } else { Write-Host "NOT FOUND 2" }
$b3 = "`"user_email`"          => `$email,"
$a3 = "`"user_email`"          => `$email," + [Environment]::NewLine + "                    `"user_id`"             => `$userId,"
if ($content.Contains($b3)) { $content = $content.Replace($b3, $a3); Write-Host "REPLACED 3" } else { Write-Host "NOT FOUND 3" }
Set-Content -Path $f -Value $content -Encoding UTF8 -Force
Write-Host "DONE"
