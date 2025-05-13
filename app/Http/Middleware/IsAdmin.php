<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class IsAdmin
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check() && $request->user()->isAdmin()) { // Asumsi ada kolom 'role' di tabel users
            return $next($request);
        }
        // Atau jika Anda memiliki metode di model User: if (Auth::check() && $request->user()->isAdmin())

        return response()->json(['message' => 'Unauthorized. Administrator access required.'], 403);
    
    }
}
