<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class DatabaseConnectionMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            // Test database connection before processing the request
            DB::connection()->getPdo();
        } catch (\Exception $e) {
            // Check if it's a connection error
            if ($this->isDatabaseConnectionError($e)) {
                return response()->json([
                    'message' => 'OMEGA, Updating assets'
                ], 503);
            }
        }

        return $next($request);
    }

    /**
     * Check if the exception is a database connection error
     */
    private function isDatabaseConnectionError(\Exception $e): bool
    {
        $connectionErrorMessages = [
            'Connection refused',
            'SQLSTATE[HY000]',
            'could not connect to server',
            'No connection could be made',
            'Connection timed out',
            'Connection reset by peer',
            'Lost connection to MySQL server',
            'MySQL server has gone away',
            'Can\'t connect to MySQL server'
        ];

        $message = $e->getMessage();
        
        foreach ($connectionErrorMessages as $errorMessage) {
            if (str_contains($message, $errorMessage)) {
                return true;
            }
        }

        return false;
    }
}
