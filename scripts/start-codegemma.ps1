# Start local CodeGemma 7B Instruct (Q4_K_M) via llama.cpp
# Requires: llama.cpp on PATH (winget package ggml.llamacpp)

$ErrorActionPreference = "Stop"

$HostBind = "127.0.0.1"
$Port = 8080
# CodeGemma supports up to 8192; keep in sync with api LLM_CONTEXT_SIZE.
$Ctx = 8192
$HfRepo = "bartowski/codegemma-7b-it-GGUF:Q4_K_M"

# Prefer already-downloaded snapshot if present
$Cached = Join-Path $env:USERPROFILE ".cache\huggingface\hub\models--bartowski--codegemma-7b-it-GGUF\snapshots"
$LocalModel = $null
if (Test-Path $Cached) {
    $LocalModel = Get-ChildItem $Cached -Recurse -Filter "codegemma-7b-it-Q4_K_M.gguf" -ErrorAction SilentlyContinue |
        Select-Object -First 1 -ExpandProperty FullName
}

Write-Host "Starting CodeGemma at http://${HostBind}:${Port}"
Write-Host "CPU-only (-ngl 0), context $Ctx"
Write-Host "Press Ctrl+C to stop."
Write-Host ""

if ($LocalModel) {
    Write-Host "Using local model: $LocalModel"
    llama serve -m $LocalModel --host $HostBind --port $Port -c $Ctx -ngl 0
} else {
    Write-Host "Local cache not found; downloading via -hf $HfRepo"
    # Prefer HF_TOKEN from environment if set (do not hardcode tokens)
    llama serve -hf $HfRepo --host $HostBind --port $Port -c $Ctx -ngl 0
}
