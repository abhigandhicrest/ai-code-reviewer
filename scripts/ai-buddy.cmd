@echo off
rem ai-buddy — AI code reviewer CLI
rem Token resolution order: AI_REVIEW_TOKEN env -> %USERPROFILE%\.ai-review-token
rem Script resolution order: AI_REVIEWER_HOME env -> repo-relative -> default path
setlocal

set "SCRIPT=%AI_REVIEWER_HOME%\api\scripts\ai_review.py"
if not exist "%SCRIPT%" (
  set "SCRIPT=%~dp0..\api\scripts\ai_review.py"
)
if not exist "%SCRIPT%" (
  set "SCRIPT=D:\laragon\www\ai-code-reviewer\api\scripts\ai_review.py"
)

if "%AI_REVIEW_TOKEN%"=="" (
  if exist "%USERPROFILE%\.ai-review-token" (
    set /p AI_REVIEW_TOKEN=<"%USERPROFILE%\.ai-review-token"
  )
)
python "%SCRIPT%" %*
