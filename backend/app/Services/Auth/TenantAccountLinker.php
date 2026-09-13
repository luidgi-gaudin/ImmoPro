<?php

namespace App\Services\Auth;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Rls;
use Illuminate\Support\Facades\DB;

/**
 * Rattache les fiches locataire au compte de la personne qu'elles décrivent.
 *
 * Le rapprochement se fait sur l'adresse e-mail, et **seulement après** que
 * cette adresse a été vérifiée. C'est cette vérification qui rend le lien sûr :
 * elle prouve que la personne contrôle l'adresse que son bailleur a inscrite au
 * dossier. Sans elle, il suffirait de s'inscrire avec l'adresse d'un tiers pour
 * lire son bail, ses quittances et sa pièce d'identité.
 *
 * Rattache au pluriel, à dessein : une même personne peut louer chez deux
 * bailleurs, qui ont chacun créé leur propre fiche.
 *
 * Deux chemins pour une même règle, imposés par la Row Level Security. Sous
 * PostgreSQL, l'écriture passe par `claim_tenant_profiles()` : la policy du
 * locataire se fonde sur `account_user_id`, or c'est justement la colonne à
 * écrire — tant qu'elle est vide, le compte ne voit pas la ligne et ne peut
 * donc pas la réclamer. La fonction est le seul point de passage autorisé, et
 * elle relit le bénéficiaire dans la session plutôt que de le recevoir en
 * paramètre. Sous SQLite, où il n'y a pas de policy, la même règle s'écrit
 * directement.
 */
class TenantAccountLinker
{
    /**
     * @return int Nombre de dossiers rattachés.
     */
    public function link(User $user): int
    {
        if (! $user->isTenant() || ! $user->hasVerifiedEmail() || blank($user->email)) {
            return 0;
        }

        if (Rls::active()) {
            // L'identité doit être posée : la fonction lit `app_user_id()`.
            Rls::ensureBound();

            return (int) (DB::selectOne('select public.claim_tenant_profiles() as linked')->linked ?? 0);
        }

        return Tenant::query()
            ->whereNull('account_user_id')
            ->whereRaw('lower(email) = ?', [mb_strtolower($user->email)])
            ->update(['account_user_id' => $user->id]);
    }
}
