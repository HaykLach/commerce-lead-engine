<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureInternalAdminAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $providedToken = (string) $request->header('X-Internal-Admin-Token', '');
        $expectedToken = (string) env('INTERNAL_ADMIN_TOKEN', '');

        if ($expectedToken !== '' && hash_equals($expectedToken, $providedToken)) {
            return $next($request);
        }

        if ($request->ip() === '127.0.0.1' || $request->ip() === '::1') {
            return $next($request);
        }

        abort(403, 'Internal admin access only.');
    }
}
