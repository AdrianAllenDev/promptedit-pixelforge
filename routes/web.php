<?php

use App\Http\Controllers\Auth\AdminSessionController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\GenerationReviewController;
use App\Http\Controllers\OperatorReportController;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\WebhookController;
use App\Http\Middleware\RequireAdminSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('webhooks/pixelforge', WebhookController::class);
Route::redirect('/', '/dashboard');
Route::middleware('auth:web')->group(function () {
    Route::get('dashboard', [GenerationController::class, 'index'])->name('dashboard');
    Route::get('wallet', [WalletController::class, 'show'])->name('wallet');
    Route::post('wallet/top-ups', [WalletController::class, 'purchase']);
    Route::post('quotes', [GenerationController::class, 'quote'])->name('quotes');
    Route::post('generations', [GenerationController::class, 'store'])->name('generations.store');
});

Route::get('admin/login', [AdminSessionController::class, 'create'])->name('admin.login');
Route::post('admin/login', [AdminSessionController::class, 'store'])->name('admin.authenticate');
Route::middleware([RequireAdminSession::class, 'cache.headers:no_store;private', 'inertia.encrypt'])->group(function () {
    Route::get('admin/report', OperatorReportController::class)->name('admin.report');
    Route::get('admin/generations/review', [GenerationReviewController::class, 'index'])->name('admin.generations.review');
    Route::post('admin/generations/{generation}/release', [GenerationReviewController::class, 'release'])->whereUuid('generation');
    Route::get('operator/report', fn (Request $request) => to_route('admin.report', $request->only('start', 'end')));
    Route::post('admin/logout', [AdminSessionController::class, 'destroy'])->name('admin.logout');
});

require __DIR__.'/auth.php';
