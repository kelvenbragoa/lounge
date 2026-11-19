<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Uso: ->middleware('role:1,2,8'). Tem de correr depois do middleware Sanctum, que autentica o utilizador.
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = Auth::user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'error' => 'Autorization required!',
            ], 401);
        }

        $allowed = array_map('intval', $roles);

        if (! in_array((int) $user->role_id, $allowed, true)) {
            return response()->json([
                'message' => 'Não tem permissão para esta operação.',
            ], 403);
        }

        return $next($request);
    }
}
