<?php

use App\Http\Controllers\ArtikalController;
use Illuminate\Support\Facades\Route;

// Pocetna strana vodi na listu artikala
Route::redirect('/', '/artikli');

Route::get('/artikli', [ArtikalController::class, 'index'])->name('artikli.index');