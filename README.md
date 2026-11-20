# AI Code Reviewer

Laravel frontend + FastAPI backend + local **CodeGemma 7B Instruct** (llama.cpp). No cloud inference APIs.

## Architecture

```
Browser (login) → Laravel (BFF + usage logs) → FastAPI (:8001) → llama.cpp (:8080) → CodeGemma Q4_K_M
```

### Auth, history, quotas & usage

- Login / register required
- **History** `/history` — past reviews, compare, severity filter, Markdown/print export, re-run
- **Project directory** — local path under `REVIEW_ALLOWED_ROOTS`, or browser folder picker; also multi-file / zip (secrets redacted before LLM)
- **Scan + select files** — path scan checklist, `.reviewignore` + extra ignore patterns
- **Git diff review** — paste/upload unified diff, or local `git diff base...head` under a project path
- **Apply fixes** — from History, write full-file improved code to disk (with backups); diff hunks are skipped
- **CI API** — `POST /api/v1/review` with Sanctum token; sample workflow in `.github/workflows/ai-code-review.example.yml`
- **Generate** `/generate` — instruction-based code generation on the same local CodeGemma model
- **Coding standards** — `/standards` prompt rules + optional phpcs/ruff/eslint
- **Prompt profiles** — security / Laravel-Blade / performance (admin can add more at `/profiles`)
- **Health** `/health-status` — Laravel DB, FastAPI, llama.cpp
- **Audit** `/audit` (admin) — metadata-only activity log
- **Usage** `/usage` — totals, latency, tokens
- **Daily quota** — default 20/day (`DAILY_REVIEW_QUOTA`); admins unlimited
- **Chunked + multi-file review** — per-file chunking with merged findings

### CI token

```powershell
php artisan reviewer:token admin@local.test --show
```

Set GitHub secrets `REVIEWER_URL` (e.g. `https://host/api/v1/review`) and `REVIEWER_TOKEN`, then copy the example workflow.

### CLI (`ai-buddy`)

Terminal client for the same review/generate pipeline (`api/scripts/ai_review.py`, exposed as the `ai-buddy` command on PATH). Runs go through the Laravel API, so history/quota/audit still apply. Token is read from `AI_REVIEW_TOKEN` or `~/.ai-review-token`.

**Install (one-time):**

```powershell
scripts\install-ai-buddy.bat   # copies ai-buddy to your PATH, prompts for token, verifies
php artisan reviewer:token admin@local.test --name=cli --show   # if you need a token
```

The installer copies `scripts/ai-buddy.cmd` into a PATH directory (e.g. `%LOCALAPPDATA%\Python\bin`), saves the token to `%USERPROFILE%\.ai-review-token`, and runs a smoke check. The command resolves the script via `AI_REVIEWER_HOME` → repo-relative → `D:\laragon\www\ai-code-reviewer` default.

**Review commands:**

```powershell
ai-buddy file.php                        # review file(s)
ai-buddy src\a.php src\b.js              # multiple files
ai-buddy --staged                        # review staged git diff
ai-buddy --diff < patch.diff             # review a diff via stdin
ai-buddy --review "def f(): pass" --language python   # inline snippet
git diff | ai-buddy                      # pipe diff (auto-detected)
Get-Content app.py | ai-buddy            # pipe raw code
git show HEAD | ai-buddy                 # review last commit
ai-buddy --generate file.php             # review + improved code
ai-buddy --staged --fail-on high         # exit 1 on high findings (CI mode)
ai-buddy file.py --json                  # raw JSON output
ai-buddy --staged --fail-on high,medium  # CI: fail on high or medium
```

**Generate / modify commands:**

```powershell
ai-buddy --instruction "Create a PHP function..." --language php
ai-buddy --instruction "..." --output out.py          # generate into a new file
ai-buddy --instruction "Add validation" --source-code app.py   # modify in place (.bak backup)
ai-buddy --instruction "..." --source-code app.py --output app2.py
```

**All options:**

| Flag | Purpose |
|------|---------|
| `--staged` | Review `git diff --cached` in the current directory |
| `--diff` | Force stdin to be treated as a unified diff |
| `--review TEXT` | Review an inline code snippet |
| `--generate` | Include improved code in review results |
| `--instruction TEXT` | Generate/modify code (switches to generate mode) |
| `--source-code FILE` | Existing code to modify (requires `--instruction`) |
| `--output FILE` | Write generated code to file (modify mode defaults to the source file, with `.bak` backup) |
| `--language LANG` | python, php, javascript, typescript, java, csharp, go, rust, sql, other |
| `--fail-on SEVS` | Exit 1 on severities `high,medium,low` (comma-separated) |
| `--json` | Print raw JSON response |
| `--base-url URL` | Laravel URL (default `http://127.0.0.1:8000`) |
| `--token TOKEN` | Override API token |
| `--timeout SEC` | HTTP timeout (default 1800) |
| `--stdin-name NAME` | Label for piped code |

Env vars: `AI_REVIEW_BASE_URL`, `AI_REVIEW_TOKEN`.

**Notes:**

- Language is auto-detected from file extensions; `--language` overrides it
- Files over 1 MB are skipped with a warning
- CPU-bound reviews take ~30s–several minutes depending on input size
- All runs appear in the web UI **History** page

Database setup:

```powershell
php artisan migrate --force
php artisan db:seed --force
```

Default admin (after seeding):

- Email: `admin@local.test`
- Password: `password`

| Layer | Path / port | Role |
|-------|-------------|------|
| Laravel UI | project root / Laragon `ai-code-reviewer.test` | Paste code, show review |
| FastAPI | [`api/`](api/) → `http://127.0.0.1:8001` | Review API + Python LLM client |
| CodeGemma | `http://127.0.0.1:8080` | Local GGUF inference |

**Model:** [`bartowski/codegemma-7b-it-GGUF`](https://huggingface.co/bartowski/codegemma-7b-it-GGUF) `Q4_K_M` (~5.3 GB). Official Google F16 (~16 GB) does not fit ~16 GB RAM / Intel UHD.

## Prerequisites

- Laragon (PHP 8.1+) / Composer
- Python 3.10+ with pip
- llama.cpp (`winget install llama.cpp`) on PATH
- Downloaded CodeGemma Q4_K_M (see below)

## Start order (3 processes)

### 1. Local LLM (CodeGemma)

```powershell
.\scripts\start-codegemma.ps1
```

Server: `http://127.0.0.1:8080`

First-time download:

```powershell
llama download -hf bartowski/codegemma-7b-it-GGUF:Q4_K_M
```

### 2. FastAPI

```powershell
cd api
python -m pip install -r requirements.txt
copy .env.example .env   # if needed
python -m uvicorn app.main:app --reload --host 127.0.0.1 --port 8001
```

- Health: `GET http://127.0.0.1:8001/health`
- Review (structured JSON): `POST http://127.0.0.1:8001/api/v1/review`
- Review stream (SSE tokens + final structured result): `POST http://127.0.0.1:8001/api/v1/review/stream`
- Docs: `http://127.0.0.1:8001/docs`

The Laravel UI streams via `POST /review/stream` and renders severity-tagged findings plus improved code when the model returns parseable JSON.

### 3. Laravel

Ensure `.env` has:

```env
FASTAPI_URL=http://127.0.0.1:8001
```

Then via Laragon (`http://ai-code-reviewer.test`) or:

```powershell
php artisan serve --host=127.0.0.1 --port=8000
```

Open the UI, paste code, click **Review code**.

Note: CPU reviews often take 1–3 minutes. The Laravel controller raises PHP’s time limit for `/review` so long FastAPI/LLM calls do not hit the default 60s cap.

## Environment

**FastAPI** ([`api/.env.example`](api/.env.example)):

- `LLM_BASE_URL=http://127.0.0.1:8080`
- `LLM_TIMEOUT_SECONDS=600`
- `LLM_MAX_TOKENS=1024`
- `LLM_TEMPERATURE=0.2`

**Laravel** (`.env`):

- `FASTAPI_URL=http://127.0.0.1:8001`

Do not put Hugging Face tokens in frontend or source files. Use `hf auth login` or a user `HF_TOKEN` env var if needed for gated Google repos.

## Hugging Face (optional / compliance)

1. Accept license: https://huggingface.co/google/codegemma-7b-it-GGUF  
2. `hf auth login`  
3. `hf auth whoami`

The bartowski quant used here typically downloads without a token.

## Smoke tests

```powershell
.\scripts\test-codegemma.ps1
# or
Invoke-RestMethod http://127.0.0.1:8001/health
```

## Project layout

```
ai-code-reviewer/          # Laravel
  api/                     # FastAPI + Python LLM client
  api/scripts/ai_review.py # ai-buddy terminal CLI client
  scripts/                 # start/test CodeGemma
  app/Http/Controllers/ReviewController.php
  resources/views/review/index.blade.php
```

