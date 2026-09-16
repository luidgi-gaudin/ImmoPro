<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Réserve un groupe de routes à un profil.
 *
 * Ce n'est pas ce qui protège les données — l'isolation, elle, tient aux
 * policies et à la Row Level Security, qui ne regardent pas le rôle mais la
 * possession des lignes. Un bailleur qui atteindrait l'espace locataire n'y
 * verrait rien de plus que ses propres dossiers, s'il en a.
 *
 * Ce garde-fou sert à autre chose : empêcher qu'un écran s'ouvre sur du vide
 * inexplicable, et faire échouer tôt, avec un message clair, plutôt que tard,
 * sur une liste vide qui ressemble à un bogue.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        $allowed = array_filter(array_map(
            fn (string $role) => UserRole::tryFrom($role),
            $roles
        ));

        if ($user === null || ! in_array($user->role, $allowed, true)) {
            abort(403, 'Cet espace ne correspond pas à votre profil.');
        }

        return $next($request);
    }
}
