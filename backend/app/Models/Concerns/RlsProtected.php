<?php

namespace App\Models\Concerns;

use App\Support\Rls;
use Illuminate\Database\Eloquent\Builder;

/**
 * Marque un modèle dont la table est couverte par une policy PostgreSQL.
 *
 * Le trait ne filtre rien lui-même — c'est la base qui filtre. Son seul rôle
 * est de garantir que l'identité du bailleur a bien été annoncée à PostgreSQL
 * avant la première lecture.
 *
 * En régime normal, il ne déclenche aucune requête : l'identité a déjà été
 * posée par la requête d'authentification (voir App\Models\PersonalAccessToken)
 * et `ensureBound()` n'a plus qu'à le constater. Le seul cas où il coûte un
 * aller-retour est celui d'un accès depuis un contexte non authentifié — une
 * commande artisan, un job en file d'attente — où il vaut mieux payer 135 ms
 * que lire des lignes sans savoir à qui elles appartiennent.
 *
 * Ne pas confondre avec un global scope de filtrage : rien n'est ajouté au SQL
 * généré. Un développeur qui écrirait `Lease::all()` obtiendra les baux du
 * bailleur connecté, et une chaîne vide s'il n'y en a pas — sans que le code
 * ait eu à y penser.
 */
trait RlsProtected
{
    public static function bootRlsProtected(): void
    {
        static::addGlobalScope('rls', function (Builder $query): void {
            Rls::ensureBound();
        });
    }
}
