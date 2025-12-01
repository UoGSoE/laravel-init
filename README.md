# Laravel Init

A single-command setup script that bootstraps Laravel projects with Flux UI, Keycloak SSO, and common packages.

## What It Does

- Installs and activates [Flux UI](https://fluxui.dev/) with Tailwind CSS v4
- Configures [Keycloak SSO](https://www.keycloak.org/) via Laravel Socialite
- Installs Horizon and Sanctum
- Copies your template files (routes, providers, views, etc.)
- Injects config into `routes/web.php` and `config/services.php`
- Sets up environment variables

## Requirements

- A fresh Laravel project with a clean git working tree
- [Flux UI license](https://fluxui.dev/)
- Node.js and Composer

## Usage

```bash
export FLUX_USERNAME="your-flux-username"
export FLUX_LICENSE_KEY="your-flux-license-key"

php laravel-init.php /path/to/your-laravel-project
```

## Packages Installed

**Composer:**
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

## Customization

Fork this repo and modify to suit your stack:

| Variable | Purpose |
|----------|---------|
| `$excludeFiles` | Files/directories to skip when copying |
| `$autoCopyPatterns` | Patterns that overwrite without prompting |
| `$envVariables` | Environment variables added to `.env` |
| `$gitignoreEntries` | Entries appended to `.gitignore` |

### Template Files

Place your boilerplate files alongside `laravel-init.php`. The script copies everything except files in `$excludeFiles`. Existing files prompt before overwriting (unless matched by `$autoCopyPatterns`).

Example structure:
```
laravel-init/
├── laravel-init.php
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

The following are added to `.env` and `.env.example`:

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

## License

MIT
