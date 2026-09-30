<?php

use App\Http\Controllers\ImportController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ImportController::class, 'index'])->name('imports.index');
Route::post('/imports', [ImportController::class, 'store'])->name('imports.store');
Route::get('/imports/{import}', [ImportController::class, 'show'])->name('imports.show');
