@echo off
setlocal EnableDelayedExpansion
title ai-buddy installer

echo ============================================
echo   ai-buddy CLI installer — AI Code Reviewer
echo ============================================
echo.

rem --- locate a writable PATH directory ---------------------------------
set "TARGET_DIR="
for %%D in (
  "%LOCALAPPDATA%\Python\bin"
  "%LOCALAPPDATA%\Programs\Python\Python312\Scripts"
  "%USERPROFILE%\bin"
) do (
  if not defined TARGET_DIR if exist "%%~D" set "TARGET_DIR=%%~D"
)
if not defined TARGET_DIR (
  echo No suitable PATH directory found.
  echo Install Python or create %%USERPROFILE%%\bin and add it to PATH.
  pause
  exit /b 1
)
echo Installing to: %TARGET_DIR%
echo.

rem --- copy the command ---------------------------------------------------
copy /y "%~dp0ai-buddy.cmd" "%TARGET_DIR%\ai-buddy.cmd" >nul
if errorlevel 1 (
  echo Failed to copy ai-buddy.cmd to %TARGET_DIR%
  pause
  exit /b 1
)
echo [OK] ai-buddy.cmd installed to %TARGET_DIR%
echo.
echo NOTE: if %TARGET_DIR% is not on your PATH yet, add it:
echo   setx PATH "%%PATH%%;%TARGET_DIR%"
echo then open a NEW terminal.
echo.

rem --- token ----------------------------------------------------------------
if exist "%USERPROFILE%\.ai-review-token" (
  echo Existing token found at %USERPROFILE%\.ai-review-token
  set /p OVERWRITE=Replace it? [y/N] 
  if /i "!OVERWRITE!"=="y" goto :gettoken
  goto :verify
)
:gettoken
echo.
echo Paste your Sanctum API token (create one with:
echo   php artisan reviewer:token ^<email^> --name=cli --show
echo):
set /p TOKEN=
if "%TOKEN%"=="" (
  echo No token entered — skipped. Set AI_REVIEW_TOKEN later or re-run installer.
  goto :verify
)
> "%USERPROFILE%\.ai-review-token" echo %TOKEN%
echo [OK] Token saved to %USERPROFILE%\.ai-review-token

:verify
echo.
echo Verifying installation...
call "%TARGET_DIR%\ai-buddy.cmd" --help >nul 2>&1
if errorlevel 1 (
  echo.
  echo WARNING: ai-buddy could not run. Make sure Python 3 is on your PATH.
) else (
  echo.
  echo ============================================
  echo   DONE — open a new terminal and run:
  echo     ai-buddy --help
  echo     ai-buddy path\to\file.php
  echo ============================================
)
pause
