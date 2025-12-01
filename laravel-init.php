#!/usr/bin/env php
<?php

/**
 * Laravel Init Script
 *
 * Combines Flux UI and SSO setup into a single initialization script.
 * Usage: php laravel-init.php /path/to/laravel-project
 */

// ============================================================================
// Configuration
// ============================================================================

$excludeFiles = [
    '.git',
    'laravel-init.php',
    'README.md',
    'LICENSE',
];

$autoCopyPatterns = [
    'fluxui',
    'SSOServiceProvider',
];

$envVariables = [
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

$gitignoreEntries = [
    'auth.json',
];

// ============================================================================
// Helper Functions
// ============================================================================

function info(string $message): void
{
    echo "\033[34m[INFO]\033[0m $message\n";
}

function success(string $message): void
{
    echo "\033[32m[OK]\033[0m $message\n";
}

function warning(string $message): void
{
    echo "\033[33m[WARN]\033[0m $message\n";
}

function error(string $message): void
{
    echo "\033[31m[ERROR]\033[0m $message\n";
}

function fatal(string $message): never
{
    error($message);
    exit(1);
}

function run(string $command, ?string $cwd = null): bool
{
    $descriptors = [
        0 => STDIN,
        1 => STDOUT,
        2 => STDERR,
    ];

    $process = proc_open($command, $descriptors, $pipes, $cwd);

    if (is_resource($process)) {
        $exitCode = proc_close($process);
        return $exitCode === 0;
    }

    return false;
}

function prompt(string $question, bool $default = true): bool
{
    $hint = $default ? '[Y/n]' : '[y/N]';
    echo "$question $hint ";

    $handle = fopen('php://stdin', 'r');
    $input = trim(fgets($handle));
    fclose($handle);

    if ($input === '') {
        return $default;
    }

    return strtolower($input[0]) === 'y';
}

function upsertEnv(string $file, string $key, string $value): void
{
    if (!file_exists($file)) {
        return;
    }

    $contents = file_get_contents($file);

    if (preg_match("/^{$key}=/m", $contents)) {
        return; // Already exists
    }

    file_put_contents($file, $contents . "\n{$key}={$value}");
}

function addToGitignore(string $destDir, string $entry): void
{
    $gitignore = "$destDir/.gitignore";

    if (!file_exists($gitignore)) {
        return;
    }

    $contents = file_get_contents($gitignore);

    if (strpos($contents, $entry) !== false) {
        return; // Already exists
    }

    file_put_contents($gitignore, $contents . "\n$entry");
}

function isGitClean(string $dir): bool
{
    $output = [];
    exec("cd " . escapeshellarg($dir) . " && git status --porcelain 2>/dev/null", $output);
    return empty($output);
}

function shouldAutoCopy(string $path, array $patterns): bool
{
    foreach ($patterns as $pattern) {
        if (strpos($path, $pattern) !== false) {
            return true;
        }
    }
    return false;
}

function copyTemplateFiles(string $srcDir, string $destDir, array $excludeFiles, array $autoCopyPatterns): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
        $relativePath = substr($file->getPathname(), strlen($srcDir) + 1);

        // Check exclusions
        $skip = false;
        foreach ($excludeFiles as $exclude) {
            if (strpos($relativePath, $exclude) === 0 || $relativePath === $exclude) {
                $skip = true;
                break;
            }
        }
        if ($skip) {
            continue;
        }

        $destPath = "$destDir/$relativePath";

        if ($file->isDir()) {
            if (!is_dir($destPath)) {
                mkdir($destPath, 0755, true);
            }
            continue;
        }

        // Ensure parent directory exists
        $destDirPath = dirname($destPath);
        if (!is_dir($destDirPath)) {
            mkdir($destDirPath, 0755, true);
        }

        // Auto-copy new files, prompt only for overwrites
        if (file_exists($destPath)) {
            // File exists - prompt before overwriting (unless it's an auto-copy pattern)
            if (shouldAutoCopy($relativePath, $autoCopyPatterns) || prompt("Overwrite $relativePath?", false)) {
                copy($file->getPathname(), $destPath);
                info("Copied: $relativePath");
            } else {
                info("Skipped: $relativePath");
            }
        } else {
            // New file - copy without prompting
            copy($file->getPathname(), $destPath);
            info("Copied: $relativePath");
        }
    }
}

function injectSsoRoute(string $destDir): void
{
    $file = "$destDir/routes/web.php";

    if (!file_exists($file)) {
        warning("routes/web.php not found, skipping SSO route injection");
        return;
    }

    $contents = file_get_contents($file);
    $requireLine = "require __DIR__ . '/sso-auth.php';";

    if (strpos($contents, 'sso-auth.php') !== false) {
        info("SSO route already present in routes/web.php");
        return;
    }

    // Find the position after <?php and any use statements
    // Insert after the last use statement, or after <?php if no use statements
    if (preg_match('/^<\?php\s*((?:use\s+[^;]+;\s*)*)/s', $contents, $matches)) {
        $insertPos = strlen($matches[0]);
        $newContents = substr($contents, 0, $insertPos)
            . "\n$requireLine\n"
            . substr($contents, $insertPos);

        file_put_contents($file, $newContents);
        success("Injected SSO route into routes/web.php");
    } else {
        warning("Could not find suitable location in routes/web.php, please add manually:");
        echo "  $requireLine\n";
    }
}

function injectKeycloakConfig(string $destDir): void
{
    $file = "$destDir/config/services.php";

    if (!file_exists($file)) {
        warning("config/services.php not found, skipping Keycloak config injection");
        return;
    }

    $contents = file_get_contents($file);

    if (strpos($contents, "'keycloak'") !== false || strpos($contents, '"keycloak"') !== false) {
        info("Keycloak config already present in config/services.php");
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

    // Find the last ]; in the file (the closing of the return array)
    $lastBracketPos = strrpos($contents, '];');

    if ($lastBracketPos !== false) {
        $newContents = substr($contents, 0, $lastBracketPos)
            . $keycloakConfig . "\n"
            . substr($contents, $lastBracketPos);

        file_put_contents($file, $newContents);
        success("Injected Keycloak config into config/services.php");
    } else {
        warning("Could not find suitable location in config/services.php, please add manually");
        warning($keycloakConfig);
    }
}

// ============================================================================
// Main Script
// ============================================================================

echo "\n";
echo "\033[1m=== Laravel Init Script ===\033[0m\n";
echo "\n";

// Get directories
$srcDir = dirname(__FILE__);
$destDir = $argv[1] ?? '.';
$destDir = realpath($destDir);

if (!$destDir) {
    fatal("Destination directory does not exist: " . ($argv[1] ?? '.'));
}

info("Source: $srcDir");
info("Destination: $destDir");
echo "\n";

// ============================================================================
// Validation
// ============================================================================

info("Validating environment...");

// Check Flux credentials
$fluxUsername = getenv('FLUX_USERNAME');
$fluxLicenseKey = getenv('FLUX_LICENSE_KEY');

if (empty($fluxUsername) || empty($fluxLicenseKey)) {
    fatal("FLUX_USERNAME and/or FLUX_LICENSE_KEY not set. Export them and re-run.");
}
success("Flux credentials found");

// Check Laravel project
if (!file_exists("$destDir/artisan")) {
    fatal("$destDir is not a Laravel project (artisan not found)");
}
success("Laravel project detected");

// Check git status
if (!isGitClean($destDir)) {
    fatal("There are uncommitted changes in the destination directory. Please commit or stash them and re-run.");
}
success("Git working tree is clean");

echo "\n";

// ============================================================================
// File Copying
// ============================================================================

info("Copying template files...");
copyTemplateFiles($srcDir, $destDir, $excludeFiles, $autoCopyPatterns);
echo "\n";

// ============================================================================
// SSO Provider Registration
// ============================================================================

info("Registering SSO Service Provider...");
$ssoProviderPath = "$destDir/app/Providers/SSOServiceProvider.php";

if (!file_exists($ssoProviderPath)) {
    run("php artisan make:provider SSOServiceProvider", $destDir);
    success("Created SSOServiceProvider via artisan");
} else {
    info("SSOServiceProvider already exists");
}
echo "\n";

// ============================================================================
// Package Installation
// ============================================================================

info("Installing npm packages...");
if (!run("npm install -D vite tailwindcss @tailwindcss/vite laravel-vite-plugin", $destDir)) {
    warning("npm install may have had issues, please check manually");
}
echo "\n";

info("Installing composer packages...");
if (!run("composer require livewire/flux laravel/socialite socialiteproviders/keycloak laravel/horizon laravel/sanctum", $destDir)) {
    warning("composer require may have had issues, please check manually");
}
echo "\n";

info("Activating Flux license...");
if (!run("php artisan flux:activate " . escapeshellarg($fluxUsername) . " " . escapeshellarg($fluxLicenseKey), $destDir)) {
    warning("Flux activation may have had issues, please check manually");
}
echo "\n";

// ============================================================================
// Automated Config Edits
// ============================================================================

info("Injecting configuration...");
injectSsoRoute($destDir);
injectKeycloakConfig($destDir);
echo "\n";

// ============================================================================
// Environment Variables
// ============================================================================

info("Adding environment variables...");
foreach ($envVariables as $key => $value) {
    upsertEnv("$destDir/.env", $key, $value);
    upsertEnv("$destDir/.env.example", $key, $value);
}
success("Environment variables added to .env and .env.example");
echo "\n";

// ============================================================================
// Gitignore Updates
// ============================================================================

info("Updating .gitignore...");
foreach ($gitignoreEntries as $entry) {
    addToGitignore($destDir, $entry);
}
success("Updated .gitignore");
echo "\n";

// ============================================================================
// Final Output
// ============================================================================

echo "\033[1m=== Setup Complete! ===\033[0m\n\n";

// Check if boost is installed
$composerJson = file_get_contents("$destDir/composer.json");
if (strpos($composerJson, 'laravel/boost') === false) {
    info("Consider running: composer require laravel/boost");
    echo "  (Adds Laravel MCP for better Claude Code integration with Flux/Livewire)\n\n";
}

info("If using Claude Code, check .claude/commands for helpful migration commands.");
echo "\n";

success("Done!");
echo "\n";
