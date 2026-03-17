# ImmoPro

Application de gestion immobilière avec une architecture découplée backend/frontend.

## Stack technique

### Backend — `backend/`

| Technologie | Version |
|---|---|
| PHP | >= 8.2 |
| Laravel | 12.x |
| Laravel Sanctum | 4.x (authentification API) |
| Base de données | SQLite (par défaut) |
| Tests | PHPUnit 11 |

### Frontend — `frontend/`

| Technologie | Version |
|---|---|
| Angular | 21.x |
| TypeScript | 5.9 |
| RxJS | 7.8 |
| Styles | SCSS |
| Tests | Vitest 4 |

## Prérequis

- PHP >= 8.2 + Composer
- Node.js >= 20.19.0 (npm 10+)

## Installation

```bash
# Backend
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate

# Frontend
cd frontend
npm install
```

## Lancement en développement

```bash
# Backend (port 8000)
cd backend && php artisan serve

# Frontend (port 4200)
cd frontend && ng serve
```

## Communication frontend ↔ backend

- Le frontend Angular (port `4200`) communique avec l'API Laravel (port `8000`) via des appels HTTP REST sur les routes `api/*`.
- L'authentification est gérée par **Laravel Sanctum** (SPA cookie-based).
- Le CORS est configuré côté Laravel pour accepter `http://localhost:4200` avec `supports_credentials: true`.