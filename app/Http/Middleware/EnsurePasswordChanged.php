<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordChanged
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user(), 401, 'Unauthenticated.');
        if ($request->user()->must_change_password) {
            return response()->json(['message' => 'Password change required.', 'reason' => 'password_change_required'], 403);
        }

        return $next($request);
    }
}
