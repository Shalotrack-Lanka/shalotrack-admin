<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
    
    // Register the custom Firebase middleware alias
    $middleware->alias([
        'auth.firebase' => \App\Http\Middleware\VerifyFirebaseToken::class,
    ]);
    
})
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
