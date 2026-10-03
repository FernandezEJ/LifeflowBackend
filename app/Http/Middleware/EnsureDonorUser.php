<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDonorUser
{
    public function handle(Request $request, Closure $next): Response
    {
        // Sanctum establishes identity; donor routes never grant staff access to private chats.
        abort_unless($request->user()?->isDonor() && ! $request->user()->isDeactivated(), 403, 'Only active donors may use Flowie.');

        return $next($request);
    }
}
