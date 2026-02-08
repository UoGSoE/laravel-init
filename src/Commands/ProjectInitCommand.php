<?php

namespace UoGSoE\LaravelInit\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;

class ProjectInitCommand extends Command
{
    protected $signature = 'project:init
                            {--skip-npm : Skip npm package installation}
                            {--skip-composer : Skip composer package installation}
                            {--skip-flux : Skip Flux activation}
                            {--force : Overwrite all files without prompting}';

    protected $description = 'Bootstrap this Laravel project with Flux UI, Keycloak SSO, and common packages';

    protected array $envVariables = [
        'KEYCLOAK_BASE_URL' => 'https://',
        'KEYCLOAK_REALM' => '',
        'KEYCLOAK_CLIENT_ID' => 'name-in-keycloak',
        'KEYCLOAK_CLIENT_SECRET' => 'secret-in-keycloak',
        'KEYCLOAK_REDIRECT_URI' => 'http://your-app/auth/callback',
        'SSO_ENABLED' => 'false',
        'SSO_AUTOCREATE_NEW_USERS' => 'false',
        'SSO_ALLOW_STUDENTS' => 'false',
        'SSO_ADMINS_ONLY' => 'false',
    ];

    protected array $gitignoreEntries = ['auth.json'];

    protected string $boostPromptUrl = 'https://raw.githubusercontent.com/UoGSoE/boost-prompts/refs/heads/master/.ai/guidelines/team-conventions.blade.php';

    protected array $autoCopyPatterns = ['fluxui', 'SSOServiceProvider'];

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
        $this->addEnvironmentVariables();
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

        $exitCode = Artisan::call('flux:activate', [
            'username' => $fluxUsername,
            'license-key' => $fluxLicenseKey,
        ], $this->output);

        if ($exitCode !== 0) {
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

    private function addEnvironmentVariables(): void
    {
        $this->info('Adding environment variables...');

        foreach ($this->envVariables as $key => $value) {
            $this->upsertEnv(base_path('.env'), $key, $value);
            $this->upsertEnv(base_path('.env.example'), $key, $value);
        }

        $this->info('Environment variables added to .env and .env.example');
    }

    private function upsertEnv(string $file, string $key, string $value): void
    {
        if (! file_exists($file)) {
            return;
        }

        $contents = file_get_contents($file);

        if (preg_match("/^{$key}=/m", $contents)) {
            return;
        }

        file_put_contents($file, $contents."\n{$key}={$value}");
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
}
