<?php

use App\Http\Controllers\AuditController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\GenerateController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\HistoryController;
use App\Http\Controllers\PromptProfileController;
use App\Http\Controllers\StandardsController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\UsageController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
    Route::get('/register', [RegisterController::class, 'create'])->name('register');
    Route::post('/register', [RegisterController::class, 'store']);
});

Route::post('/logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/', [ReviewController::class, 'index'])->name('review.index');
    Route::post('/review/scan', [ReviewController::class, 'scan'])->name('review.scan');
    Route::post('/review/git-diff', [ReviewController::class, 'gitDiff'])->name('review.git-diff');
    Route::post('/review/stream', [ReviewController::class, 'stream'])->name('review.stream');

    Route::get('/generate', [GenerateController::class, 'index'])->name('generate.index');
    Route::post('/generate/stream', [GenerateController::class, 'stream'])->name('generate.stream');

    Route::get('/usage', [UsageController::class, 'index'])->name('usage.index');
    Route::get('/health-status', [HealthController::class, 'index'])->name('health.status');

    Route::get('/history', [HistoryController::class, 'index'])->name('history.index');
    Route::get('/history/compare', [HistoryController::class, 'compare'])->name('history.compare');
    Route::get('/history/{reviewRun}', [HistoryController::class, 'show'])->name('history.show');
    Route::get('/history/{reviewRun}/export.md', [HistoryController::class, 'exportMarkdown'])->name('history.export');
    Route::post('/history/{reviewRun}/rerun', [HistoryController::class, 'rerun'])->name('history.rerun');
    Route::post('/history/{reviewRun}/apply', [HistoryController::class, 'apply'])->name('history.apply');

    Route::get('/profiles', [PromptProfileController::class, 'index'])->name('profiles.index');
    Route::post('/profiles', [PromptProfileController::class, 'store'])->name('profiles.store');
    Route::delete('/profiles/{profile}', [PromptProfileController::class, 'destroy'])->name('profiles.destroy');

    Route::get('/standards', [StandardsController::class, 'index'])->name('standards.index');
    Route::put('/standards/{standard}', [StandardsController::class, 'update'])->name('standards.update');

    Route::get('/audit', [AuditController::class, 'index'])->name('audit.index');
});
