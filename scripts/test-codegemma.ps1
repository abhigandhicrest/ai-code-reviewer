# Smoke-test local OpenAI-compatible chat completions at :8080
$ErrorActionPreference = "Stop"
$Base = "http://127.0.0.1:8080"

Write-Host "Checking server..."
try {
    $models = Invoke-RestMethod -Uri "$Base/v1/models" -Method Get -TimeoutSec 10
    $modelId = $models.data[0].id
    Write-Host "OK - model id: $modelId"
} catch {
    Write-Error "Server not reachable at $Base. Start it with scripts\start-codegemma.ps1 first."
    exit 1
}

$prompt = @"
Review this Python code:

def divide(a, b):
    return a / b

print(divide(10, 0))

1. Identify potential bugs.
2. Explain the problem.
3. Suggest an improved implementation.
"@

$body = @{
    model       = $modelId
    messages    = @(@{ role = "user"; content = $prompt })
    temperature = 0.2
    max_tokens  = 512
} | ConvertTo-Json -Depth 5

Write-Host "`nSending chat completion request (this may take a while on CPU)..."
$resp = Invoke-RestMethod -Uri "$Base/v1/chat/completions" -Method Post -ContentType "application/json" -Body $body -TimeoutSec 600
$content = $resp.choices[0].message.content
Write-Host "`n=== Model response ===`n"
Write-Host $content
Write-Host "`n=== End ==="
