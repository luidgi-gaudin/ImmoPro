# ImmoPro — API

API REST de gestion locative pour propriétaires bailleurs : portefeuilles de biens, locataires, baux conformes à la **loi n° 89-462 du 6 juillet 1989**, échéances de loyer et quittances.

## Stack

- **PHP ≥ 8.3** / **Laravel 13**
- **Laravel Sanctum** (authentification par token Bearer)
- **pragmarx/google2fa** (double authentification TOTP)
- **SQLite** par défaut (configurable via `.env`)

## Installation

```bash
composer run setup   # composer install, .env, clé d'app, migrations, npm install + build
composer run dev     # serveur, queue, logs et vite en parallèle
composer test        # suite de tests (unit + feature)
```

Variables d'environnement notables :

| Variable | Rôle | Défaut |
|---|---|---|
| `FRONTEND_URL` | Base des liens envoyés par e-mail (reset de mot de passe) | `http://localhost:4200` |
| `MAIL_MAILER` | Transport mail (mettre `smtp` en production) | `log` |

Toutes les routes sont préfixées par `/api`. Les réponses sont en JSON. Les routes protégées attendent un header `Authorization: Bearer {token}`.

---

## Authentification

### Inscription / connexion

| Méthode | Route | Description |
|---|---|---|
| `POST` | `/auth/register` | Inscription — retourne l'utilisateur + token |
| `POST` | `/auth/login` | Connexion — retourne un token **ou** un défi 2FA |
| `POST` | `/auth/logout` | 🔒 Révoque le token courant |
| `GET` | `/auth/user` | 🔒 Utilisateur connecté |

`POST /auth/register` : `name`, `email`, `password`, `password_confirmation` (min. 8 caractères, lettres + chiffres).

`POST /auth/login` : `email`, `password`.

- Sans 2FA : `200` → `{ data, token, token_type }`
- Avec 2FA active : `200` → `{ two_factor_required: true, challenge_token }` — enchaîner sur `/auth/2fa/challenge`.

### Double authentification (TOTP)

| Méthode | Route | Description |
|---|---|---|
| `POST` | `/auth/2fa/enable` | 🔒 Génère le secret + URL `otpauth://` (QR code) |
| `POST` | `/auth/2fa/confirm` | 🔒 Confirme avec un code à 6 chiffres → retourne **8 codes de récupération** |
| `POST` | `/auth/2fa/challenge` | Échange `challenge_token` + `code` (ou `recovery_code`) contre un token |
| `POST` | `/auth/2fa/disable` | 🔒 Désactive (mot de passe requis) |
| `POST` | `/auth/2fa/recovery-codes` | 🔒 Regénère les codes de récupération (mot de passe requis) |

Flux d'activation : `enable` → scanner le QR code (`otpauth_url`) dans Google Authenticator/Aegis → `confirm` avec le code affiché → stocker les codes de récupération (affichés une seule fois).

Flux de connexion : `login` → si `two_factor_required`, `challenge` avec le `challenge_token` (valable 5 minutes) et le code TOTP. En cas de perte de l'appareil, un `recovery_code` (usage unique) remplace le code.

Le secret et les codes de récupération sont **chiffrés en base** et jamais exposés par l'API.

### Mot de passe

| Méthode | Route | Description |
|---|---|---|
| `POST` | `/auth/forgot-password` | Envoie l'e-mail de réinitialisation (réponse générique anti-énumération) |
| `POST` | `/auth/reset-password` | Réinitialise avec `token`, `email`, `password`, `password_confirmation` |
| `PUT` | `/auth/password` | 🔒 Changement : `current_password`, `password`, `password_confirmation` |

Le lien envoyé pointe vers `{FRONTEND_URL}/reset-password?token=…&email=…`. Un reset révoque **tous** les tokens d'API ; un changement de mot de passe révoque tous les tokens **sauf** celui de la session courante.

---

## Ressources métier

Toutes les routes ci-dessous sont protégées (🔒 Sanctum) et limitées aux données de l'utilisateur connecté (policies).

### Portefeuilles & biens

| Méthode | Route |
|---|---|
| `GET/POST` | `/portfolios` |
| `GET/PUT/DELETE` | `/portfolios/{id}` |
| `GET/POST` | `/portfolios/{id}/properties` |
| `GET/PUT/DELETE` | `/portfolios/{id}/properties/{id}` |

Un bien porte : type (`appartement`, `maison`, `terrain`), adresse, DPE (`A`–`G`), surface, pièces, équipements, loyer indicatif. Le champ `is_rented` est **synchronisé automatiquement** avec l'existence d'un bail actif.

### Locataires

| Méthode | Route |
|---|---|
| `GET/POST` | `/tenants` (index paginé) |
| `GET/PUT/DELETE` | `/tenants/{id}` |

RGPD : IBAN/BIC des locataires chiffrés au repos ; suppression douce (soft delete).

### Baux

| Méthode | Route | Description |
|---|---|---|
| `GET/POST` | `/leases` | Liste / création |
| `GET/PUT/DELETE` | `/leases/{id}` | Détail / modification / suppression douce |
| `POST` | `/leases/{id}/terminate` | Résiliation (`end_date`) — libère le bien |
| `POST` | `/leases/{id}/revise-rent` | Révision annuelle du loyer (`irl_old`, `irl_new`) |

Champs : `property_id`, `tenant_id`, `type`, `start_date`, `end_date`, `monthly_rent` (hors charges), `charges` (provision mensuelle), `deposit`, `payment_day` (1–28), `statut` (`actif`, `termine`, `en_attente`).

**Règles légales appliquées à la validation (loi n° 89-462) :**

| Type de bail (`type`) | Durée | Dépôt de garantie max |
|---|---|---|
| `nu` — location vide | ≥ 3 ans (art. 10) | 1 mois de loyer HC (art. 22) |
| `meuble` — location meublée | ≥ 1 an (art. 25-7) | 2 mois de loyer HC (art. 25-6) |
| `etudiant` — meublé étudiant | 9 mois (art. 25-7) | 2 mois de loyer HC |
| `mobilite` — bail mobilité | 1 à 10 mois, `end_date` obligatoire (art. 25-12) | **interdit** (art. 25-13) |

`end_date` est facultative pour `nu` et `meuble` (tacite reconduction). Un bail résilié (`termine`) échappe au contrôle de durée minimale.

**Révision IRL (art. 17-1)** : `nouveau loyer = loyer × irl_new / irl_old` (indices INSEE trimestriels), au plus **une fois par an**, uniquement sur un bail actif. Réponse : `{ old_rent, new_rent, data }`.

### Échéances de loyer & quittances

| Méthode | Route | Description |
|---|---|---|
| `GET/POST` | `/leases/{id}/payments` | Échéances du bail |
| `GET/PUT/DELETE` | `/leases/{id}/payments/{id}` | Détail / paiement / suppression |
| `GET` | `/leases/{id}/payments/{id}/quittance` | **Quittance de loyer** (art. 21) |

- `period` est normalisée au 1er du mois — une seule échéance par mois et par bail.
- À la création, `amount_rent` et `amount_charges` reprennent par défaut le loyer et les charges du bail.
- `status` est calculé : `paye` (si `paid_at`), `en_retard` (échéance dépassée), `en_attente`.
- Pour marquer un paiement : `PUT` avec `paid_at` (+ `payment_method` facultatif).

La **quittance** n'est délivrée que si l'échéance est payée (`422` sinon), avec le détail obligatoire loyer / charges, la période, le bailleur, le locataire, le bien et la mention de gratuité (art. 21).

---

## Limitation de débit & erreurs

| Contexte | Limite |
|---|---|
| `/auth/register`, `/auth/login`, `/auth/2fa/challenge` | 10 req/min |
| `/auth/forgot-password`, `/auth/reset-password` | 5 req/min |
| Nouvelle demande de reset pour un même compte | 1/min (throttle broker) |

Codes de réponse : `200/201` succès, `401` non authentifié ou défi 2FA invalide, `403` ressource d'un autre utilisateur, `404` inexistant ou hors périmètre, `409` conflit d'état métier (bail déjà résilié, révision < 1 an, 2FA déjà active…), `422` erreur de validation, `429` trop de requêtes.

## Tests

```bash
composer test
```

- **Unit** : plafonds/durées légales par type de bail, statut des échéances, service TOTP (codes, défis, codes de récupération).
- **Feature** : auth (register/login/logout), 2FA de bout en bout, reset et changement de mot de passe, CRUD portfolios/properties/tenants/leases/payments, conformité loi 89-462 (dépôts, durées, mobilité), résiliation, révision IRL, quittances, isolation entre utilisateurs (403/404).
