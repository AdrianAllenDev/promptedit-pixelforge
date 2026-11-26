<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::redirect('/', '/dashboard');
Route::get('/dashboard', fn () => Inertia::render('Workspace'))->middleware('auth')->name('dashboard');

require __DIR__.'/auth.php';
