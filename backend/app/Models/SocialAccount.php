<?php

namespace App\Models;

use App\Enums\SocialProvider;
use Database\Factories\SocialAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Compte Google ou Apple rattaché à un utilisateur.
 *
 * Volontairement hors Row Level Security : cette table est lue pendant
 * l'authentification, c'est-à-dire avant qu'une identité soit connue de la
 * base. La protéger reviendrait à rendre la connexion externe impossible.
 * Elle ne contient d'ailleurs aucune donnée que le fournisseur ne rendrait pas
 * lui-même au porteur du jeton.
 *
 * @property int $user_id
 * @property SocialProvider $provider
 * @property string $provider_user_id
 */
class SocialAccount extends Model
{
    /** @use HasFactory<SocialAccountFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'provider',
        'provider_user_id',
        'provider_email',
        'provider_name',
        'avatar_url',
        'last_login_at',
    ];

    protected function casts(): array
    {
        return [
            'provider' => SocialProvider::class,
            'last_login_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
