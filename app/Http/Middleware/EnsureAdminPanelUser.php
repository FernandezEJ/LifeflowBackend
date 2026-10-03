<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminPanelUser
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $role = 'admin'): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401, 'Unauthenticated.');

        if ($user->isDeactivated() || $user->trashed()) {
            $token = $user->currentAccessToken();
            if ($token instanceof PersonalAccessToken) {
                $token->delete();
            }
            abort(403, 'Access denied.');
        }

        abort_unless($user->isAdminPanelUser()
            && ($role === UserRole::Admin->value || ($role === UserRole::SuperAdmin->value && $user->isSuperAdmin())),
            403, 'Access denied.');

        return $next($request);
    }
}
