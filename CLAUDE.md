# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

---

## Repo identity

- **This repo**: Laravel 11 API backend — `D:\laragon\www\car-auction-api`
- **Frontend**: Next.js at `D:\laragon\www\car-auction-web` — do not touch
- **GitHub**: `https://github.com/Hariszafar37/Auction-api.git`

---

## Commands

```bash
# Serve (Laragon handles this via vhost: http://car-auction-api.test)
php artisan serve          # Alternative: manual serve on port 8000

# Migrations
php artisan migrate
php artisan migrate:rollback

# Seeders
php artisan db:seed

# Queue / Jobs
php artisan queue:work

# Tests (Pest)
php artisan test
./vendor/bin/pest

# Storage symlink (required on fresh environments)
php artisan storage:link
```

PHP binary on this machine (Laravel 12 requires PHP ≥ 8.2):
```
d:/laragon/bin/php/php-8.3.15-Win32-vs16-x64/php.exe artisan
```

---

## Git workflow

Both repositories are initialized and connected to GitHub. Three long-lived branches exist:

| Branch | Purpose |
|---|---|
| `main` | Production — live server |
| `staging` | Pre-production — staging server deployments |
| `develop` | Active integration — ongoing feature work |

**Default branching rule:**
> Always create new feature branches from `develop` unless explicitly instructed to branch from `staging` or `main`.

Promotion flow:
```
feature/* → develop → staging → main
```

Git operations (commit, push, branch creation) are permitted and expected as part of normal development workflow. Do not use `--no-verify` or force-push to `main` or `staging` without explicit instruction.

---

## Architecture notes

- **Auth**: Laravel Sanctum Personal Access Tokens (Bearer)
- **API prefix**: `/api/v1/...`
- **Realtime**: Laravel Reverb (WebSocket broadcasting)
- **Media**: spatie/laravel-medialibrary (images/videos on Vehicle model)
- **Permissions**: spatie/laravel-permission
- **Queue**: Redis-backed jobs (NotifyAuctionWinner, ProcessLotClose, etc.)

### Key API groups

| Prefix | Purpose |
|---|---|
| `/api/v1/auth/` | Registration, login, password, me |
| `/api/v1/activation/` | Multi-step user activation flow |
| `/api/v1/auctions/` | Public auction listing + bidding |
| `/api/v1/my/` | User-scoped: bids, vehicles, won lots |
| `/api/v1/vehicles/` | Public vehicle inventory |
| `/api/v1/admin/` | Admin CRUD for users, auctions, vehicles |

---

## 🚫 FRONTEND SAFETY RULE

- Frontend is located at: `D:\laragon\www\car-auction-web`
- DO NOT modify frontend files from this repo context

---

## 🔒 FINAL PRINCIPLE

This is a production backend system.

Prioritize:
- correctness
- stability
- minimal changes
- zero regression risk

Do not write code until you have inspected the relevant files.
