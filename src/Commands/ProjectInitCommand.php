<?php

namespace UoGSoE\LaravelInit\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class ProjectInitCommand extends Command
{
    protected $signature = 'project:init
                            {--skip-npm : Skip npm package installation}
                            {--skip-composer : Skip composer package installation}
                            {--skip-flux : Skip Flux activation}
                            {--force : Overwrite all files without prompting}';

    protected $description = 'Bootstrap this Laravel project with Flux UI, Keycloak SSO, Docker/Lando, and common packages';

    protected array $gitignoreEntries = [
        '*.log',
        '.DS_Store',
        '.env',
        '.env.backup',
        '.env.production',
        '.phpactor.json',
        '.phpunit.result.cache',
        '/.fleet',
        '/.idea',
        '/.nova',
        '/.phpunit.cache',
        '/.vscode',
        '/.zed',
        '/auth.json',
        '/node_modules',
        '/public/build',
        '/public/hot',
        '/public/storage',
        '/storage/*.key',
        '/storage/pail',
        '/vendor',
        'Homestead.json',
        'Homestead.yaml',
        'Thumbs.db',
        'auth.json',
        '/storage/minio_dev/bucket/*',
        '!/storage/minio_dev/bucket/.gitkeep',
        '/storage/minio_dev/.minio.sys',
        '/storage/meilisearch/*',
        '!/storage/meilisearch/.gitkeep*',
        'npm-debug.log',
        'yarn-error.log',
    ];

    protected string $boostPromptUrl = 'https://raw.githubusercontent.com/UoGSoE/boost-prompts/refs/heads/master/.ai/guidelines/team-conventions.blade.php';

    protected array $autoCopyPatterns = ['fluxui', 'SSOServiceProvider'];

    protected array $internalStubFiles = ['.env.lando'];

    public function handle(): int
    {
        $this->newLine();
        $this->components->info('Laravel Project Init');
        $this->newLine();

        if (! $this->validateEnvironment()) {
            return self::FAILURE;
        }

        $this->newLine();
        $this->updateBoostPrompt();
        $this->newLine();
        $this->copyStubs();
        $this->newLine();
        $this->registerSsoProvider();
        $this->newLine();

        if (! $this->option('skip-npm')) {
            $this->installNpmPackages();
            $this->newLine();
        }

        if (! $this->option('skip-composer')) {
            $this->installComposerPackages();
            $this->newLine();
        }

        if (! $this->option('skip-flux')) {
            $this->activateFlux();
            $this->newLine();
        }

        $this->injectSsoRoute();
        $this->injectKeycloakConfig();
        $this->newLine();
        $this->ensureEnvFileExists();
        $this->newLine();
        $this->mergeLandoEnvOverrides();
        $this->applyProjectNameDefaults();
        $this->syncEnvExampleFromEnv();
        $this->newLine();
        $this->updateGitignore();

        $this->newLine();
        $this->components->info('Setup Complete!');
        $this->newLine();
        $this->suggestBoost();

        return self::SUCCESS;
    }

    private function validateEnvironment(): bool
    {
        $this->info('Validating environment...');

        $fluxUsername = env('FLUX_USERNAME') ?: getenv('FLUX_USERNAME');
        $fluxLicenseKey = env('FLUX_LICENSE_KEY') ?: getenv('FLUX_LICENSE_KEY');

        if (empty($fluxUsername) || empty($fluxLicenseKey)) {
            $this->error('FLUX_USERNAME and/or FLUX_LICENSE_KEY not set. Export them and re-run.');

            return false;
        }
        $this->info('Flux credentials found');

        $process = new Process(['git', 'status', '--porcelain'], base_path());
        $process->run();

        if (trim($process->getOutput()) !== '') {
            $this->error('There are uncommitted changes. Please commit or stash them and re-run.');

            return false;
        }
        $this->info('Git working tree is clean');

        return true;
    }

    private function updateBoostPrompt(): void
    {
        $this->info('Updating Boost prompt from GitHub...');

        $destPath = base_path('.ai/guidelines/team-conventions.blade.php');
        $destDir = dirname($destPath);

        if (! is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }

        $contents = @file_get_contents($this->boostPromptUrl);

        if ($contents === false) {
            $this->warn('Could not fetch Boost prompt from GitHub. Skipping.');

            return;
        }

        file_put_contents($destPath, $contents);
        $this->info('Updated team-conventions.blade.php');
    }

    private function copyStubs(): void
    {
        $this->info('Copying template files...');

        $stubsDir = $this->stubsPath();
        $destDir = base_path();

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($stubsDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            $relativePath = substr($file->getPathname(), strlen($stubsDir) + 1);
            $destPath = $destDir.'/'.$relativePath;

            if ($file->isDir()) {
                if (! is_dir($destPath)) {
                    mkdir($destPath, 0755, true);
                }

                continue;
            }

            if ($this->isInternalStubFile($relativePath)) {
                continue;
            }

            $destDirPath = dirname($destPath);
            if (! is_dir($destDirPath)) {
                mkdir($destDirPath, 0755, true);
            }

            if (file_exists($destPath)) {
                if ($this->option('force') || $this->shouldAutoCopy($relativePath)) {
                    copy($file->getPathname(), $destPath);
                    $this->line("  Copied: {$relativePath}");
                } else {
                    $action = $this->promptWithDiff($relativePath, $file->getPathname(), $destPath);
                    if ($action === 'y') {
                        copy($file->getPathname(), $destPath);
                        $this->line("  Copied: {$relativePath}");
                    } else {
                        $this->line("  Skipped: {$relativePath}");
                    }
                }
            } else {
                copy($file->getPathname(), $destPath);
                $this->line("  Copied: {$relativePath}");
            }
        }
    }

    private function promptWithDiff(string $relativePath, string $stubPath, string $existingPath): string
    {
        while (true) {
            $action = strtolower(trim(
                $this->ask("Overwrite {$relativePath}? [y/n/d(iff)]", 'n')
            ));

            if ($action === 'd' || $action === 'diff') {
                $this->showDiff($existingPath, $stubPath);

                continue;
            }

            if (in_array($action, ['y', 'n', 'yes', 'no'])) {
                return str_starts_with($action, 'y') ? 'y' : 'n';
            }

            $this->warn('Please enter y, n, or d');
        }
    }

    private function showDiff(string $existingPath, string $stubPath): void
    {
        $process = new Process(
            ['diff', '--color=always', '-u', $existingPath, $stubPath],
        );
        $process->run();

        $this->newLine();
        $this->line('<comment>--- Existing file</comment>');
        $this->line('<comment>+++ Template (new)</comment>');
        $output = $process->getOutput();

        // Skip the first two lines of diff output (the file paths) since we show our own labels
        $lines = explode("\n", $output);
        if (count($lines) > 2) {
            $output = implode("\n", array_slice($lines, 2));
        }

        $this->output->write($output);
        $this->newLine();
    }

    private function shouldAutoCopy(string $path): bool
    {
        foreach ($this->autoCopyPatterns as $pattern) {
            if (str_contains($path, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function registerSsoProvider(): void
    {
        $this->info('Registering SSO Service Provider...');

        $providersFile = base_path('bootstrap/providers.php');

        if (! file_exists($providersFile)) {
            $this->warn('bootstrap/providers.php not found. Please register SSOServiceProvider manually.');

            return;
        }

        $contents = file_get_contents($providersFile);

        if (str_contains($contents, 'SSOServiceProvider')) {
            $this->line('  SSOServiceProvider already registered');

            return;
        }

        $newContents = str_replace(
            '];',
            "    App\\Providers\\SSOServiceProvider::class,\n];",
            $contents
        );

        file_put_contents($providersFile, $newContents);
        $this->info('Registered SSOServiceProvider in bootstrap/providers.php');
    }

    private function installNpmPackages(): void
    {
        $this->info('Installing npm packages...');

        if (! $this->runProcess(['npm', 'install', '-D', 'vite', 'tailwindcss', '@tailwindcss/vite', 'laravel-vite-plugin'])) {
            $this->warn('npm install may have had issues, please check manually');
        }
    }

    private function installComposerPackages(): void
    {
        $this->info('Installing composer packages...');

        if (! $this->runProcess(['composer', 'require', 'livewire/livewire', 'livewire/flux', 'laravel/socialite', 'socialiteproviders/keycloak', 'laravel/horizon', 'laravel/sanctum'])) {
            $this->warn('composer require may have had issues, please check manually');
        }
    }

    private function activateFlux(): void
    {
        $this->info('Activating Flux licence...');

        $fluxUsername = env('FLUX_USERNAME') ?: getenv('FLUX_USERNAME');
        $fluxLicenseKey = env('FLUX_LICENSE_KEY') ?: getenv('FLUX_LICENSE_KEY');

        // Must shell out because the Flux package was just installed via composer
        // and the current process hasn't loaded its service provider
        if (! $this->runProcess(['php', 'artisan', 'flux:activate', $fluxUsername, $fluxLicenseKey])) {
            $this->warn('Flux activation may have had issues, please check manually');
        }
    }

    private function injectSsoRoute(): void
    {
        $file = base_path('routes/web.php');

        if (! file_exists($file)) {
            $this->warn('routes/web.php not found, skipping SSO route injection');

            return;
        }

        $contents = file_get_contents($file);
        $requireLine = "require __DIR__ . '/sso-auth.php';";

        if (str_contains($contents, 'sso-auth.php')) {
            $this->line('  SSO route already present in routes/web.php');

            return;
        }

        if (preg_match('/^<\?php\s*((?:use\s+[^;]+;\s*)*)/s', $contents, $matches)) {
            $insertPos = strlen($matches[0]);
            $newContents = substr($contents, 0, $insertPos)
                ."\n{$requireLine}\n"
                .substr($contents, $insertPos);

            file_put_contents($file, $newContents);
            $this->info('Injected SSO route into routes/web.php');
        } else {
            $this->warn('Could not find suitable location in routes/web.php, please add manually:');
            $this->line("  {$requireLine}");
        }
    }

    private function injectKeycloakConfig(): void
    {
        $file = base_path('config/services.php');

        if (! file_exists($file)) {
            $this->warn('config/services.php not found, skipping Keycloak config injection');

            return;
        }

        $contents = file_get_contents($file);

        if (str_contains($contents, "'keycloak'") || str_contains($contents, '"keycloak"')) {
            $this->line('  Keycloak config already present in config/services.php');

            return;
        }

        $keycloakConfig = <<<'PHP'

    'keycloak' => [
        'client_id' => env('KEYCLOAK_CLIENT_ID'),
        'client_secret' => env('KEYCLOAK_CLIENT_SECRET'),
        'redirect' => env('KEYCLOAK_REDIRECT_URI'),
        'base_url' => env('KEYCLOAK_BASE_URL'),
        'realms' => env('KEYCLOAK_REALM'),
    ],
PHP;

        $lastBracketPos = strrpos($contents, '];');

        if ($lastBracketPos !== false) {
            $newContents = substr($contents, 0, $lastBracketPos)
                .$keycloakConfig."\n"
                .substr($contents, $lastBracketPos);

            file_put_contents($file, $newContents);
            $this->info('Injected Keycloak config into config/services.php');
        } else {
            $this->warn('Could not find suitable location in config/services.php, please add manually');
        }
    }

    private function ensureEnvFileExists(): void
    {
        $this->info('Ensuring .env exists...');

        $envPath = base_path('.env');

        if (file_exists($envPath)) {
            $this->line('  .env already exists');

            return;
        }

        $envExamplePath = base_path('.env.example');
        if (! file_exists($envExamplePath)) {
            $this->warn('No .env or .env.example found. Skipping .env creation.');

            return;
        }

        copy($envExamplePath, $envPath);
        $this->info('Created .env from .env.example');
    }

    private function mergeLandoEnvOverrides(): void
    {
        $this->info('Merging .env.lando overrides into .env...');

        $landoEnvPath = $this->stubsPath('.env.lando');
        $envPath = base_path('.env');

        if (! file_exists($landoEnvPath)) {
            $this->warn('stubs/.env.lando not found. Skipping merge.');

            return;
        }

        if (! file_exists($envPath)) {
            $this->warn('.env not found. Skipping .env.lando merge.');

            return;
        }

        $lines = file($landoEnvPath, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            $this->warn('Could not read stubs/.env.lando. Skipping merge.');

            return;
        }

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            if (! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);

            if ($key === '') {
                continue;
            }

            $this->setEnvValue($envPath, $key, $value);
        }
    }

    private function applyProjectNameDefaults(): void
    {
        $this->info('Applying project naming defaults...');

        $envPath = base_path('.env');
        $landoPath = base_path('.lando.yml');

        $slug = $this->projectSlug();
        $appName = $this->projectDisplayName();

        if (file_exists($envPath)) {
            $escapedAppName = str_replace('"', '\"', $appName);
            $this->setEnvValue($envPath, 'APP_NAME', '"'.$escapedAppName.'"');
            $this->setEnvValue($envPath, 'APP_URL', "https://{$slug}.lndo.site/");
        }

        if (! file_exists($landoPath)) {
            return;
        }

        $contents = file_get_contents($landoPath);
        if ($contents === false) {
            $this->warn('Could not read .lando.yml to update project name.');

            return;
        }

        $updated = preg_replace('/^name:\s*.*$/m', "name: {$slug}", $contents, 1, $count);

        if ($updated === null) {
            $this->warn('Could not update name key in .lando.yml.');

            return;
        }

        if ($count === 0) {
            $updated = "name: {$slug}\n".$updated;
        }

        file_put_contents($landoPath, $updated);
    }

    private function setEnvValue(string $file, string $key, string $value): void
    {
        if (! file_exists($file)) {
            return;
        }

        $contents = file_get_contents($file);
        if ($contents === false) {
            return;
        }

        $pattern = '/^'.preg_quote($key, '/').'=.*/m';
        $replacement = "{$key}={$value}";

        if (preg_match($pattern, $contents)) {
            $updated = preg_replace($pattern, $replacement, $contents, 1);
            if ($updated !== null) {
                file_put_contents($file, $updated);
            }

            return;
        }

        file_put_contents($file, rtrim($contents)."\n{$replacement}\n");
    }

    private function syncEnvExampleFromEnv(): void
    {
        $this->info('Syncing .env.example from .env...');

        $envPath = base_path('.env');
        $envExamplePath = base_path('.env.example');

        if (! file_exists($envPath)) {
            $this->warn('.env not found. Skipping .env.example sync.');

            return;
        }

        copy($envPath, $envExamplePath);
        $this->info('Updated .env.example from .env');
    }

    private function updateGitignore(): void
    {
        $this->info('Updating .gitignore...');

        foreach ($this->gitignoreEntries as $entry) {
            $this->addToGitignore($entry);
        }

        $this->info('Updated .gitignore');
    }

    private function addToGitignore(string $entry): void
    {
        $gitignore = base_path('.gitignore');

        if (! file_exists($gitignore)) {
            return;
        }

        $contents = file_get_contents($gitignore);

        if (str_contains($contents, $entry)) {
            return;
        }

        file_put_contents($gitignore, $contents."\n{$entry}");
    }

    private function suggestBoost(): void
    {
        $composerJson = file_get_contents(base_path('composer.json'));

        if (! str_contains($composerJson, 'laravel/boost')) {
            $this->line('Consider running: <comment>composer require laravel/boost</comment>');
            $this->line('  (Adds Laravel MCP for better Claude Code integration with Flux/Livewire)');
            $this->newLine();
        }

        $this->line("If using Claude Code, check <comment>.claude/commands</comment> for helpful migration commands.");
    }

    private function runProcess(array $command): bool
    {
        $process = new Process($command, base_path());
        $process->setTimeout(300);
        $process->run(function ($type, $buffer) {
            $this->output->write($buffer);
        });

        return $process->isSuccessful();
    }

    private function stubsPath(string $relativePath = ''): string
    {
        $base = dirname(__DIR__, 2).'/stubs';

        return $relativePath ? $base.'/'.$relativePath : $base;
    }

    private function isInternalStubFile(string $relativePath): bool
    {
        return in_array($relativePath, $this->internalStubFiles, true);
    }

    private function projectSlug(): string
    {
        $baseName = strtolower(basename(base_path()));
        $slug = str_replace('_', '-', $baseName);
        $slug = preg_replace('/[^a-z0-9-]+/', '-', $slug) ?? '';
        $slug = preg_replace('/-+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : 'laravel-app';
    }

    private function projectDisplayName(): string
    {
        $baseName = basename(base_path());
        $spaced = str_replace(['-', '_'], ' ', $baseName);
        $spaced = preg_replace('/\s+/', ' ', $spaced) ?? $spaced;

        return ucwords(trim($spaced));
    }

}
