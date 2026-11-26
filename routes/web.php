<?php

use App\Http\Controllers\GenerationController;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::post('webhooks/pixelforge', WebhookController::class);
Route::redirect('/', '/dashboard');
Route::middleware('auth')->group(function () {
    Route::get('dashboard', [GenerationController::class, 'index'])->name('dashboard');
    Route::get('wallet', [WalletController::class, 'show'])->name('wallet');
    Route::post('wallet/top-ups', [WalletController::class, 'purchase']);
    Route::post('quotes', [GenerationController::class, 'quote'])->name('quotes');
    Route::post('generations', [GenerationController::class, 'store'])->name('generations.store');
});

require __DIR__.'/auth.php';
