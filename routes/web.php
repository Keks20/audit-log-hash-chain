<?php

use App\Http\Controllers\ArtikalController;
use App\Http\Controllers\PrijavaController;
use Illuminate\Support\Facades\Route;

// Pocetna strana vodi na listu artikala
Route::redirect('/', '/artikli');

Route::get('/artikli', [ArtikalController::class, 'index'])->name('artikli.index');

// Dodavanje, izmena i brisanje samo za prijavljene
Route::middleware('auth')->group(function () {
    Route::get('/artikli/novi', [ArtikalController::class, 'create'])->name('artikli.create');
    Route::post('/artikli', [ArtikalController::class, 'store'])->name('artikli.store');
    Route::get('/artikli/{artikal}/izmena', [ArtikalController::class, 'edit'])->name('artikli.edit');
    Route::put('/artikli/{artikal}', [ArtikalController::class, 'update'])->name('artikli.update');
    Route::delete('/artikli/{artikal}', [ArtikalController::class, 'destroy'])->name('artikli.destroy');
});

// Prijava samo za goste; prijavljenog bootstrap/app.php vraca na listu
Route::middleware('guest')->group(function () {
    Route::get('/prijava', [PrijavaController::class, 'create'])->name('prijava');
    Route::post('/prijava', [PrijavaController::class, 'store'])->name('prijava.store');
});

// Odjava ide preko POST forme (sa CSRF tokenom), nikad preko obicnog linka
Route::post('/odjava', [PrijavaController::class, 'destroy'])->middleware('auth')->name('odjava');