<?php

use App\Http\Middleware\OdbijNeispravanUtf8;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Pre StartSession: neispravan UTF-8 ne sme da stigne do sesije (vidi middleware)
        $middleware->web(prepend: [OdbijNeispravanUtf8::class]);

        // Laravel bi gosta slao na rutu "login", a nasa se zove "prijava"
        $middleware->redirectGuestsTo(fn () => route('prijava'));
        $middleware->redirectUsersTo(fn () => route('artikli.index'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
