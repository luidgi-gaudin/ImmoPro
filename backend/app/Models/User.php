<?php

namespace App\Models;

//use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable  // implements MustVerifyEmail  Décommenter pour forcer la verification par mail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'iban',
        'bic',
        'siret',
        'siren',
        'currency',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'iban',
        'bic',
        'siret',
        'siren',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'iban' => 'encrypted',
            'bic' => 'encrypted',
            'siret' => 'encrypted',
            'siren' => 'encrypted',
        ];
    }

    public function portfolios(): HasMany
    {
        return $this->hasMany(Portfolio::class);
    }

    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class);
    }

    /**
     * Anonymise les données personnelles sans casser les FK.
     * À appeler avant ou à la place d'un hard delete.
     */
    public function anonymize(): void
    {
        $this->update([
            'name' => 'Utilisateur supprimé',
            'email' => "deleted_{$this->id}@anonymized.local",
            'password' => bcrypt(str()->random(32)),
            'iban' => null,
            'bic' => null,
            'siret' => null,
            'siren' => null,
        ]);

        $this->tokens()->delete(); // Révoque les tokens Sanctum
        $this->delete(); //soft delete
    }
}
