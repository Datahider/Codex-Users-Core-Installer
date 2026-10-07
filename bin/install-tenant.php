#!/usr/bin/env php
<?php

declare(strict_types=1);

const ROUTER_INSTALL_URL = 'https://cdx-router.botmeister.ru/api/v1/core/install';
const CORE_REPOSITORY_URL = 'https://github.com/Datahider/Codex-Users-Core.git';
const HELP = <<<'TEXT'
Usage:
  sudo php bin/install-tenant.php --pair CODE [--user USERNAME] [--yes]
  sudo php bin/install-tenant.php --update USERNAME

Options:
  --pair CODE       One-time code from the CodexGate Telegram bot
  --user USERNAME   Linux user for Core (default: codex)
  --yes             Accept user creation or reuse without a prompt
  --update USERNAME Update an installed Core
TEXT;

if (count($argv) === 1) {
    fwrite(STDERR, HELP . PHP_EOL);
    exit(1);
}
if (($argv[1] ?? '') === '--help' && count($argv) === 2) {
    fwrite(STDOUT, HELP . PHP_EOL);
    exit(0);
}

try {
    $arguments = parseArguments(array_slice($argv, 1));
    requireRoot();
    if ($arguments['mode'] === 'update') {
        updateCore($arguments['username']);
    } else {
        installCore($arguments['pairing_code'], $arguments['username'], $arguments['yes']);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Core installation failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

/** @return array{mode:string,pairing_code:string,username:?string,yes:bool} */
function parseArguments(array $arguments): array
{
    if (($arguments[0] ?? '') === '--update' && count($arguments) === 2) {
        validateUsername((string) $arguments[1]);
        return ['mode' => 'update', 'pairing_code' => '', 'username' => (string) $arguments[1], 'yes' => true];
    }

    $pairing_code = null;
    $username = null;
    $yes = false;
    for ($index = 0; $index < count($arguments); $index++) {
        $argument = $arguments[$index];
        if ($argument === '--yes') {
            $yes = true;
            continue;
        }
        if (in_array($argument, ['--pair', '--user'], true)) {
            $value = $arguments[++$index] ?? null;
            if (!is_string($value) || $value === '') throw new RuntimeException("Missing value for $argument");
            if ($argument === '--pair') $pairing_code = normalizePairingCode($value);
            else $username = $value;
            continue;
        }
        throw new RuntimeException("Unknown argument: $argument");
    }
    if ($pairing_code === null) throw new RuntimeException('--pair is required');
    if ($username !== null) validateUsername($username);
    return ['mode' => 'install', 'pairing_code' => $pairing_code, 'username' => $username, 'yes' => $yes];
}

function normalizePairingCode(string $code): string
{
    $normalized = strtoupper(str_replace('-', '', trim($code)));
    if (preg_match('/^[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{16}$/', $normalized) !== 1) {
        throw new RuntimeException('Invalid pairing code');
    }
    return $normalized;
}

function requireRoot(): void
{
    if (!function_exists('posix_geteuid') || !function_exists('posix_getpwnam')) {
        throw new RuntimeException('PHP POSIX extension is required');
    }
    if (posix_geteuid() !== 0) {
        throw new RuntimeException('Installer must be run as root');
    }
}

function installCore(string $pairing_code, ?string $username, bool $yes): void
{
    foreach (['git', 'composer', 'codex', 'systemctl', 'runuser', 'useradd'] as $command) requireCommand($command);
    if (PHP_VERSION_ID < 80200) throw new RuntimeException('PHP 8.2 or newer is required');
    if (!function_exists('curl_init')) throw new RuntimeException('PHP curl extension is required');

    $username ??= prompt('Linux user [codex]: ', 'codex');
    validateUsername($username);
    $home = '/home/' . $username;
    $existing = posix_getpwnam($username);
    if ($existing === false) {
        if (!$yes && !confirm("Create Linux user $username with home $home?")) throw new RuntimeException('Installation cancelled');
        run(['useradd', '--create-home', '--home-dir', $home, '--shell', '/bin/bash', $username]);
    } else {
        if (($existing['dir'] ?? null) !== $home) throw new RuntimeException("User $username must use home $home");
        if (!$yes && !confirm("Use existing Linux user $username with home $home?")) throw new RuntimeException('Installation cancelled');
    }

    $core_dir = $home . '/Codex-Users-Core';
    if (file_exists($core_dir)) throw new RuntimeException("Core directory already exists: $core_dir");
    runAsUser($username, ['git', 'clone', CORE_REPOSITORY_URL, $core_dir]);
    runAsUser($username, ['composer', 'install', '--working-dir=' . $core_dir, '--no-dev', '--prefer-dist', '--no-interaction']);

    $credentials = exchangePairingCode($pairing_code);
    try {
        writeCoreConfig($username, $home, $credentials['core_token']);
        installUnitTemplate();
        run(['systemctl', 'daemon-reload']);
        $service = 'codex-core@' . $username . '.service';
        run(['systemctl', 'enable', '--now', $service]);
        $status = trim(run(['systemctl', 'is-active', $service]));
        if ($status !== 'active') throw new RuntimeException("Service $service is not active");
    } catch (Throwable $exception) {
        throw new RuntimeException('Pairing code was consumed; request a new code with /install before retrying. ' . $exception->getMessage(), 0, $exception);
    }

    fwrite(STDOUT, "Core installed for user: $username\n");
    fwrite(STDOUT, "Tenant: {$credentials['tenant']}\n");
    fwrite(STDOUT, "Run Codex authorization for this user: runuser -u $username -- codex\n");
}

function updateCore(string $username): void
{
    validateUsername($username);
    $home = '/home/' . $username;
    $core_dir = $home . '/Codex-Users-Core';
    if (posix_getpwnam($username) === false || !is_dir($core_dir)) throw new RuntimeException('Installed Core not found');
    $changes = trim(runAsUser($username, ['git', '-C', $core_dir, 'status', '--porcelain', '--untracked-files=no']));
    if ($changes !== '') throw new RuntimeException('Core has modified tracked files');
    runAsUser($username, ['git', '-C', $core_dir, 'pull', '--ff-only']);
    runAsUser($username, ['composer', 'install', '--working-dir=' . $core_dir, '--no-dev', '--prefer-dist', '--no-interaction']);
    installUnitTemplate();
    run(['systemctl', 'daemon-reload']);
    $service = 'codex-core@' . $username . '.service';
    run(['systemctl', 'restart', $service]);
    if (trim(run(['systemctl', 'is-active', $service])) !== 'active') throw new RuntimeException("Service $service is not active");
    fwrite(STDOUT, "Core updated for user: $username\n");
}

/** @return array{tenant:string,core_token:string} */
function exchangePairingCode(string $pairing_code): array
{
    $handle = curl_init(ROUTER_INSTALL_URL);
    if ($handle === false) throw new RuntimeException('Cannot initialize Router request');
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $pairing_code, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => '{}',
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw = curl_exec($handle);
    if ($raw === false) throw new RuntimeException('Router request failed: ' . curl_error($handle));
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $response = json_decode((string) $raw, true);
    if ($status !== 200 || !is_array($response)) throw new RuntimeException('Pairing code is invalid, expired or already used');
    $tenant = trim((string) ($response['tenant'] ?? ''));
    $core_token = trim((string) ($response['core_token'] ?? ''));
    if ($tenant === '' || preg_match('/^[a-f0-9]{96}$/', $core_token) !== 1) throw new RuntimeException('Router returned invalid Core credentials');
    return ['tenant' => $tenant, 'core_token' => $core_token];
}

function writeCoreConfig(string $username, string $home, string $core_token): void
{
    $directory = $home . '/.codex-users-core';
    if (!is_dir($directory) && !mkdir($directory, 0700, true)) throw new RuntimeException("Cannot create $directory");
    $config = "<?php\n\nreturn " . var_export([
        'codex' => ['bin' => 'codex', 'cwd' => $home, 'extra_args' => ['--skip-git-repo-check', '--dangerously-bypass-approvals-and-sandbox', '--json']],
        'limits' => [],
        'router' => ['base_url' => 'https://cdx-router.botmeister.ru', 'core_token' => $core_token],
        'storage' => ['root' => $home . '/var/codex-users-core'],
    ], true) . ";\n";
    $path = $directory . '/config.php';
    if (file_put_contents($path, $config, LOCK_EX) === false || !chmod($path, 0600)) throw new RuntimeException("Cannot write $path");
    $account = posix_getpwnam($username);
    if ($account === false || !chown($directory, (int) $account['uid']) || !chgrp($directory, (int) $account['gid']) || !chown($path, (int) $account['uid']) || !chgrp($path, (int) $account['gid'])) {
        throw new RuntimeException('Cannot assign Core config ownership');
    }
}

function installUnitTemplate(): void
{
    $source = dirname(__DIR__) . '/systemd/codex-core@.service';
    $target = '/etc/systemd/system/codex-core@.service';
    $contents = file_get_contents($source);
    if ($contents === false || file_put_contents($target, $contents, LOCK_EX) === false || !chmod($target, 0644)) throw new RuntimeException('Cannot install systemd unit template');
}

function requireCommand(string $command): void
{
    run(['sh', '-c', 'command -v -- "$1" >/dev/null', 'sh', $command]);
}

function runAsUser(string $username, array $command): string
{
    return run(array_merge(['runuser', '-u', $username, '--'], $command));
}

function validateUsername(string $username): void
{
    if (preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $username) !== 1) throw new RuntimeException('Invalid username');
}

function prompt(string $message, string $default): string
{
    fwrite(STDOUT, $message);
    $value = fgets(STDIN);
    if ($value === false) throw new RuntimeException('Interactive input is unavailable; use --user and --yes');
    $value = trim($value);
    return $value === '' ? $default : $value;
}

function confirm(string $message): bool
{
    fwrite(STDOUT, $message . ' [y/N] ');
    $value = fgets(STDIN);
    if ($value === false) throw new RuntimeException('Interactive input is unavailable; use --yes');
    return in_array(strtolower(trim($value)), ['y', 'yes'], true);
}

/** @param list<string> $command */
function run(array $command): string
{
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start command: ' . $command[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit_code = proc_close($process);
    if ($exit_code !== 0) throw new RuntimeException(trim((string) $stderr) ?: 'Command failed: ' . implode(' ', $command));
    return (string) $stdout;
}
