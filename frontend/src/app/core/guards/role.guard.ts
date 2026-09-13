import { inject } from '@angular/core';
import { CanActivateChildFn, Router } from '@angular/router';
import { AuthService } from '../services/auth.service';

/**
 * Réserve un groupe de routes à un profil.
 *
 * Ne protège aucune donnée — l'isolation est l'affaire du serveur, qui ne
 * regarde pas le rôle mais la possession des lignes. Ce garde évite qu'un
 * bailleur atterrisse sur l'espace locataire, où il ne verrait rien, ou qu'un
 * locataire ouvre un tableau de bord dont chaque compteur afficherait zéro.
 *
 * Rediriger vaut mieux que refuser : la personne est bien connectée, elle n'est
 * simplement pas au bon endroit.
 */
export function roleGuard(role: 'proprietaire' | 'locataire'): CanActivateChildFn {
  return () => {
    const auth = inject(AuthService);
    const router = inject(Router);

    const current = auth.currentUser();

    /*
     * Compte pas encore chargé : laisser passer.
     *
     * `AuthService` relit le compte au démarrage, et la première navigation
     * peut la précéder. Bloquer ici renverrait vers l'écran de connexion
     * quelqu'un qui est parfaitement authentifié — au rechargement d'une page
     * mise en favori, par exemple. Le serveur, lui, refusera de toute façon ce
     * qui doit l'être.
     */
    if (current === null) {
      return true;
    }

    return current.role === role ? true : router.createUrlTree([auth.homePath()]);
  };
}
