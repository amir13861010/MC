<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Register database connection middleware globally
        $middleware->web(append: [
            \App\Http\Middleware\DatabaseConnectionMiddleware::class,
        ]);
        
        $middleware->api(append: [
            \App\Http\Middleware\DatabaseConnectionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Handle database connection issues gracefully
        $exceptions->render(function (Throwable $e, $request) {
            // Check if it's a database connection error
            if ($e instanceof \PDOException || 
                $e instanceof \Illuminate\Database\QueryException ||
                $e instanceof \Illuminate\Database\ConnectionException ||
                str_contains($e->getMessage(), 'Connection refused') ||
                str_contains($e->getMessage(), 'SQLSTATE[HY000]') ||
                str_contains($e->getMessage(), 'could not connect to server') ||
                str_contains($e->getMessage(), 'No connection could be made') ||
                str_contains($e->getMessage(), 'Connection timed out')) {
                
                // Return a user-friendly error response in the same format as login API
                return response()->json([
                    'message' => 'OMEGA, Updating assets'
                ], 503);
            }
            
            return null; // Let Laravel handle other exceptions normally
        });
    })->create();
