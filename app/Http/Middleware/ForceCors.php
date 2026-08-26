<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ForceCors
{
    public function handle(Request $request, Closure $next)
    {
        $origin = $request->header('Origin');
        $allowedOrigins = [
            'http://192.168.4.22:5173',
            'http://192.168.4.23:5173',
            'http://localhost:5173',
            'http://127.0.0.1:5173',
        ];

        // Handle OPTIONS request (preflight)
        if ($request->isMethod('OPTIONS')) {
            return response()->json()
                ->header('Access-Control-Allow-Origin', $origin)
                ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, X-CSRF-TOKEN')
                ->header('Access-Control-Allow-Credentials', 'true');
        }

        $response = $next($request);

        // Always set CORS headers for allowed origins
        if (in_array($origin, $allowedOrigins) || $origin === null) {
            $response->headers->set('Access-Control-Allow-Origin', $origin ?? '*');
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, X-CSRF-TOKEN');
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
        }

        return $response;
    }
}