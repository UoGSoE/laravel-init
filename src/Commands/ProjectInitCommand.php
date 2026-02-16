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
                            {--skip-docker : Skip Docker/Lando/CI template files}
                            {--dry-run : Show what would change without writing files}
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

    protected array $skipDockerStubPaths = [
        '.dockerignore',
        '.env.github',
        '.env.gitlab',
        '.github/',
        '.gitlab-ci.yml',
        '.lando.yml',
        'Dockerfile',
        'docker/',
        'docker-compose.yml',
        'prod-stack.yml',
        'qa-stack.yml',
        'phpunit-compose.yml',
        'phpunit.Dockerfile',
        'phpunit.github.xml',
        'phpunit.gitlab.xml',
    ];

    protected array $summary = [
        'copied' => 0,
        'overwritten' => 0,
        'skipped' => 0,
        'docker_skipped' => 0,
        'would_copy' => 0,
        'processes_ran' => 0,
        'processes_failed' => 0,
        'processes_skipped' => 0,
        'file_writes' => 0,
        'file_writes_skipped' => 0,
    ];

    public function handle(): int
    {
        $this->newLine();
        $this->components->info('Laravel Project Init');
        if ($this->isDryRun()) {
            $this->components->warn('Dry-run mode enabled: no files will be changed');
        }
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

        $this->configureLivewire();
        $this->newLine();

        $this->injectSsoRoute();
        $this->injectKeycloakConfig();
        $this->newLine();
        $this->ensureEnvFileExists();
        $this->newLine();
        $this->mergeLandoEnvOverrides();
        $this->applyProjectNameDefaults();
        $this->syncEnvExampleFromEnv();
        $this->refreshEnvExampleAppKey();
        $this->newLine();
        $this->updateGitignore();

        if (! $this->option('skip-npm')) {
            $this->newLine();
            $this->buildFrontendAssets();
        }

        $this->newLine();
        $this->components->info('Setup Complete!');
        $this->newLine();
        $this->printSummary();
        $this->newLine();
        $this->suggestBoost();
        $this->printNextSteps();

        return self::SUCCESS;
    }

    private function validateEnvironment(): bool
    {
        $this->info('Validating environment...');

        if (! $this->option('skip-flux')) {
            $fluxUsername = env('FLUX_USERNAME') ?: getenv('FLUX_USERNAME');
            $fluxLicenseKey = env('FLUX_LICENSE_KEY') ?: getenv('FLUX_LICENSE_KEY');

            if (empty($fluxUsername) || empty($fluxLicenseKey)) {
                $this->error('FLUX_USERNAME and/or FLUX_LICENSE_KEY not set. Export them and re-run.');

                return false;
            }
            $this->info('Flux credentials found');
        } else {
            $this->line('  Skipping Flux credential check (--skip-flux)');
        }

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

        if (! is_dir($destDir) && ! $this->isDryRun()) {
            mkdir($destDir, 0755, true);
        }

        $contents = @file_get_contents($this->boostPromptUrl);

        if ($contents === false) {
            $this->warn('Could not fetch Boost prompt from GitHub. Skipping.');

            return;
        }

        $this->writeFile($destPath, $contents);
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

            if ($this->shouldSkipDockerStub($relativePath)) {
                if (! $file->isDir()) {
                    $this->summary['docker_skipped']++;
                    $this->line("  Skipped (--skip-docker): {$relativePath}");
                }

                continue;
            }

            if ($file->isDir()) {
                if (! is_dir($destPath) && ! $this->isDryRun()) {
                    mkdir($destPath, 0755, true);
                }

                continue;
            }

            if ($this->isInternalStubFile($relativePath)) {
                continue;
            }

            $destDirPath = dirname($destPath);
            if (! is_dir($destDirPath) && ! $this->isDryRun()) {
                mkdir($destDirPath, 0755, true);
            }

            if (file_exists($destPath)) {
                if ($this->option('force') || $this->shouldAutoCopy($relativePath)) {
                    $this->copyOrDescribe($file->getPathname(), $destPath, $relativePath, true);
                } else {
                    $action = $this->promptWithDiff($relativePath, $file->getPathname(), $destPath);
                    if ($action === 'y') {
                        $this->copyOrDescribe($file->getPathname(), $destPath, $relativePath, true);
                    } else {
                        $this->summary['skipped']++;
                        $this->line("  Skipped: {$relativePath}");
                    }
                }
            } else {
                $this->copyOrDescribe($file->getPathname(), $destPath, $relativePath, false);
            }
        }
    }

    private function copyOrDescribe(string $sourcePath, string $destPath, string $relativePath, bool $overwrite): void
    {
        if ($this->isDryRun()) {
            $this->summary['would_copy']++;
            $verb = $overwrite ? 'Would overwrite' : 'Would copy';
            $this->line("  {$verb}: {$relativePath}");

            return;
        }

        copy($sourcePath, $destPath);
        $this->summary['copied']++;
        if ($overwrite) {
            $this->summary['overwritten']++;
        }

        $this->line("  Copied: {$relativePath}");
    }

    private function promptWithDiff(string $relativePath, string $stubPath, string $existingPath): string
    {
        if ($this->isDryRun()) {
            return 'y';
        }

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

        $this->writeFile($providersFile, $newContents);
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

        if (! $this->runProcess(['composer', 'require', '--dev', 'fruitcake/laravel-debugbar'])) {
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

    private function configureLivewire(): void
    {
        $this->info('Publishing and configuring Livewire config...');

        if (! $this->runProcess(['php', 'artisan', 'livewire:config'])) {
            $this->warn('Could not publish Livewire config. Please run "php artisan livewire:config" manually.');

            return;
        }

        $configPath = base_path('config/livewire.php');

        if (! file_exists($configPath)) {
            $this->warn('config/livewire.php not found after publishing. Please configure manually.');

            return;
        }

        $contents = file_get_contents($configPath);

        $contents = preg_replace(
            "/'type'\s*=>\s*'sfc'/",
            "'type' => 'class'",
            $contents
        );

        $contents = preg_replace(
            "/'emoji'\s*=>\s*true/",
            "'emoji' => false",
            $contents
        );

        $contents = preg_replace(
            "/views\/layouts/",
            "views/components/layouts",
            $contents
        );

        $this->writeFile($configPath, $contents);
        $this->info('Configured Livewire: make_command type=class, emoji=false');
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

            $this->writeFile($file, $newContents);
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

            $this->writeFile($file, $newContents);
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

        $this->copyFile($envExamplePath, $envPath);
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

        $insertBlankLineBeforeNextKey = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                $insertBlankLineBeforeNextKey = true;
                continue;
            }

            if (str_starts_with($trimmed, '#')) {
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

            if ($insertBlankLineBeforeNextKey) {
                $this->ensureBlankLineBeforeEnvKey($envPath, $key);
            }

            $insertBlankLineBeforeNextKey = false;
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

        $updated = preg_replace('/^name:[ \t]*.*$/m', "name: {$slug}", $contents, 1, $count);

        if ($updated === null) {
            $this->warn('Could not update name key in .lando.yml.');

            return;
        }

        if ($count === 0) {
            $updated = "name: {$slug}\n".$updated;
        }

        $this->writeFile($landoPath, $updated);
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
        $commentedPattern = '/^\s*#\s*'.preg_quote($key, '/').'=.*/m';
        $replacement = "{$key}={$value}";

        if (preg_match($pattern, $contents)) {
            $updated = preg_replace($pattern, $replacement, $contents, 1);
            if ($updated !== null) {
                $this->writeFile($file, $updated);
            }

            return;
        }

        if (preg_match($commentedPattern, $contents)) {
            $updated = preg_replace($commentedPattern, $replacement, $contents, 1);
            if ($updated !== null) {
                $this->writeFile($file, $updated);
            }

            return;
        }

        $this->writeFile($file, rtrim($contents)."\n{$replacement}\n");
    }

    private function ensureBlankLineBeforeEnvKey(string $file, string $key): void
    {
        if (! file_exists($file)) {
            return;
        }

        $contents = file_get_contents($file);
        if ($contents === false) {
            return;
        }

        $lines = preg_split('/\R/', $contents);
        if ($lines === false) {
            return;
        }

        $targetPrefix = $key.'=';

        foreach ($lines as $index => $line) {
            if (! str_starts_with(ltrim($line), $targetPrefix)) {
                continue;
            }

            if ($index === 0) {
                return;
            }

            if (trim($lines[$index - 1]) === '') {
                return;
            }

            array_splice($lines, $index, 0, ['']);
            $this->writeFile($file, implode("\n", $lines)."\n");

            return;
        }
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

        $this->copyFile($envPath, $envExamplePath);
        $this->info('Updated .env.example from .env');
    }

    private function refreshEnvExampleAppKey(): void
    {
        $this->info('Refreshing APP_KEY in .env.example...');

        $envExamplePath = base_path('.env.example');
        if (! file_exists($envExamplePath)) {
            $this->warn('.env.example not found. Skipping APP_KEY refresh.');

            return;
        }

        $process = new Process(['php', 'artisan', 'key:generate', '--show'], base_path());
        $process->setTimeout(30);
        $process->run();

        if (! $process->isSuccessful()) {
            $this->warn('Could not generate a fresh APP_KEY for .env.example. Leaving current value.');

            return;
        }

        $appKey = trim($process->getOutput());
        if ($appKey === '') {
            $this->warn('Generated APP_KEY was empty. Leaving current value in .env.example.');

            return;
        }

        $this->setEnvValue($envExamplePath, 'APP_KEY', $appKey);
        $this->info('Refreshed APP_KEY in .env.example');
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

        $this->writeFile($gitignore, $contents."\n{$entry}");
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

    private function buildFrontendAssets(): void
    {
        $this->info('Building frontend assets...');

        if (! $this->runProcess(['npm', 'run', 'build'])) {
            $this->warn('npm run build may have had issues, please check manually');
        }
    }

    private function printNextSteps(): void
    {
        $slug = $this->projectSlug();

        $this->newLine();
        $this->components->info('Next steps:');
        $this->line('  cd into your project directory and run:');
        $this->newLine();
        $this->line('    <comment>lando start && lando mfs</comment>');
        $this->newLine();
        $this->line('  This will start Lando and seed the database.');
    }

    private function runProcess(array $command): bool
    {
        if ($this->isDryRun()) {
            $this->summary['processes_skipped']++;
            $this->line('  [dry-run] Skipping command: '.implode(' ', $command));

            return true;
        }

        $process = new Process($command, base_path());
        $process->setTimeout(300);
        $process->run(function ($type, $buffer) {
            $this->output->write($buffer);
        });

        $this->summary['processes_ran']++;
        if (! $process->isSuccessful()) {
            $this->summary['processes_failed']++;
        }

        return $process->isSuccessful();
    }

    private function copyFile(string $sourcePath, string $destPath): void
    {
        if ($this->isDryRun()) {
            $this->summary['file_writes_skipped']++;
            $this->line("  [dry-run] Would copy {$sourcePath} -> {$destPath}");

            return;
        }

        copy($sourcePath, $destPath);
        $this->summary['file_writes']++;
    }

    private function writeFile(string $path, string $contents): void
    {
        if ($this->isDryRun()) {
            $this->summary['file_writes_skipped']++;
            $this->line("  [dry-run] Would write {$path}");

            return;
        }

        file_put_contents($path, $contents);
        $this->summary['file_writes']++;
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

    private function shouldSkipDockerStub(string $relativePath): bool
    {
        if (! $this->option('skip-docker')) {
            return false;
        }

        foreach ($this->skipDockerStubPaths as $stubPath) {
            if (str_ends_with($stubPath, '/')) {
                $dirPath = rtrim($stubPath, '/');
                if ($relativePath === $dirPath || str_starts_with($relativePath, $stubPath)) {
                    return true;
                }

                continue;
            }

            if ($relativePath === $stubPath) {
                return true;
            }
        }

        return false;
    }

    private function isDryRun(): bool
    {
        return (bool) $this->option('dry-run');
    }

    private function printSummary(): void
    {
        $this->info('Summary:');
        $this->line('  Copied files: '.$this->summary['copied']);
        $this->line('  Overwritten files: '.$this->summary['overwritten']);
        $this->line('  Skipped files: '.$this->summary['skipped']);
        $this->line('  Skipped by --skip-docker: '.$this->summary['docker_skipped']);
        $this->line('  Dry-run would copy: '.$this->summary['would_copy']);
        $this->line('  Commands run: '.$this->summary['processes_ran']);
        $this->line('  Commands failed: '.$this->summary['processes_failed']);
        $this->line('  Commands skipped in dry-run: '.$this->summary['processes_skipped']);
        $this->line('  File writes: '.$this->summary['file_writes']);
        $this->line('  File writes skipped in dry-run: '.$this->summary['file_writes_skipped']);
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
