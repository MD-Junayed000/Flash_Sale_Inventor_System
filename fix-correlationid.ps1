$ErrorActionPreference = 'Stop'
$path = 'c:\Users\ASUS\Flash_Sale_Inventor_System\app\Http\Controllers\Api\PurchaseController.php'
$lines = Get-Content $path -Encoding UTF8

# Find the line that re-fetches correlation_id via request() helper and replace with local var
$old = "        \$payload = PurchaseResult::fail("
$found = $false
for ($i = 0; $i -lt $lines.Count; $i++) {
    if ($lines[$i].TrimStart().StartsWith('return new JsonResponse($payload, $status);')) {
        # Inject correlationId capture
        $insertIdx = $i
        $lines[$insertIdx] = $insertIdx
    }
}

# Simpler: do string-level replacement
$content = Get-Content $path -Raw -Encoding UTF8

# 1) Pass $correlationId into errorResponse by adding a parameter
$content = $content.Replace(
    "private function errorResponse(
        string \$code,
        string \$message,
        int    \$status,
        array  \$errors = [],
    ): JsonResponse {",
    "private function errorResponse(
        string \$code,
        string \$message,
        int    \$status,
        array  \$errors = [],
    ): JsonResponse {
        \$correlationId = (string) request()->attributes->get('correlation_id');"
)

# Confirm no double declaration
if ($content -match 'private function errorResponse[\s\S]{0,200}\$correlationId\s*=') {
    Write-Output "INSTRUMENTED errorResponse signature"
} else {
    Write-Output "FAILED to instrument errorResponse"
}

Set-Content -Path $path -Value $content -Encoding UTF8 -NoNewline
Write-Output "DONE"
