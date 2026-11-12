<?php

use App\Http\Controllers\ArtikalController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\PrijavaController;
use Illuminate\Support\Facades\Route;

// Pocetna strana vodi na listu artikala
Route::redirect('/', '/artikli');

Route::get('/artikli', [ArtikalController::class, 'index'])->name('artikli.index');

// Dodavanje i izmena, kao i audit log, samo za prijavljene
Route::middleware('auth')->group(function () {
    Route::get('/artikli/novi', [ArtikalController::class, 'create'])->name('artikli.create');
    Route::post('/artikli', [ArtikalController::class, 'store'])->name('artikli.store');
    Route::get('/artikli/{artikal}/izmena', [ArtikalController::class, 'edit'])->name('artikli.edit');
    Route::put('/artikli/{artikal}', [ArtikalController::class, 'update'])->name('artikli.update');

    Route::get('/audit-log', [AuditLogController::class, 'index'])->name('audit-log.index');
    Route::get('/audit-log/provera', [AuditLogController::class, 'provera'])->name('audit-log.provera');
    Route::get('/audit-log/izvoz', [AuditLogController::class, 'izvoz'])->name('audit-log.izvoz');

    // Brisanje i vracanje stanja iz loga samo za sefa (Gate "sef" u AppServiceProvider-u)
    Route::middleware('can:sef')->group(function () {
        Route::delete('/artikli/{artikal}', [ArtikalController::class, 'destroy'])->name('artikli.destroy');
        Route::post('/audit-log/{zapis}/vrati', [ArtikalController::class, 'vrati'])->name('audit-log.vrati');
    });
});

// Prijava samo za goste; prijavljenog bootstrap/app.php vraca na listu
Route::middleware('guest')->group(function () {
    Route::get('/prijava', [PrijavaController::class, 'create'])->name('prijava');
    Route::post('/prijava', [PrijavaController::class, 'store'])->name('prijava.store');
});

// Odjava ide preko POST forme (sa CSRF tokenom), nikad preko obicnog linka
Route::post('/odjava', [PrijavaController::class, 'destroy'])->middleware('auth')->name('odjava');