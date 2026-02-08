# Laravel Init

A Composer package that bootstraps Laravel projects with Flux UI, Keycloak SSO, Docker/Lando setup, and common packages via a single Artisan command.

## What It Does

- Installs and activates [Flux UI](https://fluxui.dev/) with Tailwind CSS v4
- Configures [Keycloak SSO](https://www.keycloak.org/) via Laravel Socialite
- Installs Horizon and Sanctum
- Installs Docker/Lando scaffolding used for local/dev/CI workflows
- Copies template files (routes, providers, views, etc.) with diff preview
- Injects config into `routes/web.php` and `config/services.php`
- Registers `SSOServiceProvider` in `bootstrap/providers.php`
- Sets up environment variables

## Requirements

- A fresh Laravel 11+ project with a clean git working tree
- [Flux UI licence](https://fluxui.dev/)
- Node.js and Composer

## Installation

Install as a dev dependency:

```bash
composer require --dev uogsoe/laravel-init
```

## Usage

```bash
export FLUX_USERNAME="your-flux-username"
export FLUX_LICENSE_KEY="your-flux-license-key"

php artisan project:init
```

### Options

| Option | Description |
|--------|-------------|
| `--skip-npm` | Skip npm package installation |
| `--skip-composer` | Skip composer package installation |
| `--skip-flux` | Skip Flux activation |
| `--force` | Overwrite all files without prompting |

### File Diff Preview

When copying template files that already exist in your project, the command prompts with `y/n/d(iff)`. Pressing `d` shows a unified diff between your existing file and the template, so you can make an informed decision before overwriting.

## Docker/Lando Setup

`project:init` installs Docker/Lando files from `stubs/`, including:

- `docker/` scripts/config
- `.lando.yml`
- `docker-compose.yml`, `prod-stack.yml`, `qa-stack.yml`
- CI files (`.github/`, `.gitlab-ci.yml`, `phpunit*.xml`, `phpunit*.Dockerfile`)
- `Dockerfile`, `.dockerignore`, and related support files

Environment handling is applied in this order:

- If `.env` is missing, it is created from the project's `.env.example`
- `stubs/.env.lando` values are merged into `.env`
- `APP_NAME` is set from the project folder name (title-cased)
- `APP_URL` is set to `https://<project-folder>.lndo.site/`
- `.lando.yml` `name:` is set to `<project-folder>` (slug form)
- `.env.example` is updated to match the final `.env`

It also adds required Docker/Lando ignore entries to your project `.gitignore` and ensures:

- `storage/minio_dev/bucket/.gitkeep`
- `storage/meilisearch/.gitkeep`

## Packages Installed

**Composer:**
- `livewire/livewire`
- `livewire/flux`
- `laravel/socialite`
- `socialiteproviders/keycloak`
- `laravel/horizon`
- `laravel/sanctum`

**NPM:**
- `vite`
- `tailwindcss`
- `@tailwindcss/vite`
- `laravel-vite-plugin`

## Customisation

Fork this repo and modify the properties in `src/Commands/ProjectInitCommand.php`:

| Property | Purpose |
|----------|---------|
| `$autoCopyPatterns` | Patterns that overwrite without prompting |
| `$internalStubFiles` | Stub files used internally (not copied directly) |
| `$gitignoreEntries` | Entries appended to `.gitignore` |
| `$boostPromptUrl` | URL for team conventions file |

### Template Files

Template files live in the `stubs/` directory. The command copies stubs into the target project, except internal helper files such as `stubs/.env.lando`. Existing files prompt before overwriting (unless matched by `$autoCopyPatterns` or `--force` is used).

```
laravel-init/
├── composer.json
├── src/
│   ├── LaravelInitServiceProvider.php
│   └── Commands/
│       └── ProjectInitCommand.php
└── stubs/
    ├── app/
    │   └── Providers/
    │       └── SSOServiceProvider.php
    ├── routes/
    │   └── sso-auth.php
    ├── resources/
    │   └── views/
    │       └── ...
    └── config/
        └── ...
```

## Environment Variables

Project-specific environment values are defined in `stubs/.env.lando` and merged into `.env`, then copied to `.env.example`. This includes keys such as:

```env
KEYCLOAK_BASE_URL=https://
KEYCLOAK_REALM=
KEYCLOAK_CLIENT_ID=name-in-keycloak
KEYCLOAK_CLIENT_SECRET=secret-in-keycloak
KEYCLOAK_REDIRECT_URI=http://your-app/auth/callback
SSO_ENABLED=false
SSO_AUTOCREATE_NEW_USERS=false
SSO_ALLOW_STUDENTS=false
SSO_ADMINS_ONLY=false
```

## Licence

MIT
