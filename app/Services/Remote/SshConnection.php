<?php

namespace App\Services\Remote;

use App\Enums\ServerAuthMethod;
use App\Models\Server;
use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use Throwable;

/**
 * SSH + SFTP to a server with phpseclib (pure PHP, works on Windows and Linux,
 * no ssh binary needed). One connection is reused for all commands of a deploy.
 */
class SshConnection implements Connection
{
    private ?SFTP $client = null;

    public function __construct(private readonly Server $server) {}

    public function run(string $script, ?callable $onOutput = null, ?int $timeout = null): CommandResult
    {
        $client = $this->client();
        $client->setTimeout($timeout ?? (int) config('autodeploy.command_timeout'));

        $output = '';
        $stopped = false;

        $client->exec('bash -c '.Shell::quote($script), function (string $chunk) use (&$output, &$stopped, $onOutput) {
            $output .= $chunk;

            // Keep memory bounded for very chatty commands; the step log has the full output.
            if (strlen($output) > 2_000_000) {
                $output = substr($output, -1_000_000);
            }

            if ($onOutput && $onOutput($chunk) === true) {
                $stopped = true;

                return true;
            }

            return false;
        });

        $timedOut = $client->isTimeout();
        $exit = $client->getExitStatus();

        if ($timedOut || $stopped) {
            // The channel may still be busy; start fresh for the next command.
            $this->close();
        }

        return new CommandResult(
            exitCode: $stopped ? 130 : ($exit === false ? ($timedOut ? 124 : 255) : (int) $exit),
            output: $output,
            timedOut: $timedOut,
            stopped: $stopped,
        );
    }

    public function upload(string $localFile, string $remotePath): void
    {
        if (! $this->client()->put($remotePath, $localFile, SFTP::SOURCE_LOCAL_FILE)) {
            throw new ConnectionException(__('Upload to :path failed: :error', [
                'path' => $remotePath,
                'error' => $this->client()->getLastSFTPError() ?: __('unknown error'),
            ]));
        }
    }

    public function put(string $remotePath, string $contents): void
    {
        if (! $this->client()->put($remotePath, $contents)) {
            throw new ConnectionException(__('Writing :path failed: :error', [
                'path' => $remotePath,
                'error' => $this->client()->getLastSFTPError() ?: __('unknown error'),
            ]));
        }
    }

    public function close(): void
    {
        try {
            $this->client?->disconnect();
        } catch (Throwable) {
            // Already gone.
        }

        $this->client = null;
    }

    /** SHA256 fingerprint of the server's host key, like "ssh-keygen -lf" prints it. */
    public static function fingerprint(string $publicHostKey): string
    {
        $parts = explode(' ', trim($publicHostKey));
        $blob = base64_decode($parts[1] ?? $parts[0], true) ?: $publicHostKey;

        return 'SHA256:'.rtrim(base64_encode(hash('sha256', $blob, true)), '=');
    }

    private function client(): SFTP
    {
        if ($this->client?->isConnected()) {
            return $this->client;
        }

        $server = $this->server;
        $client = new SFTP((string) $server->host, $server->port, (int) config('autodeploy.connect_timeout'));
        $client->setKeepAlive(30);

        try {
            $hostKey = $client->getServerPublicHostKey();
        } catch (Throwable $e) {
            throw new ConnectionException(__('Cannot connect to :host: :error', ['host' => $server->host.':'.$server->port, 'error' => $e->getMessage()]), previous: $e);
        }

        if ($hostKey === false) {
            throw new ConnectionException(__('Cannot connect to :host (no answer from the SSH server).', ['host' => $server->host.':'.$server->port]));
        }

        $this->verifyHostKey($hostKey);

        if (! $client->login($server->username, $this->credential())) {
            throw new ConnectionException(__('Login refused for :user@:host. Check the user name and the key / password.', [
                'user' => $server->username,
                'host' => $server->host,
            ]));
        }

        return $this->client = $client;
    }

    /** Trust on first use: the fingerprint is saved once, later connections must match it. */
    private function verifyHostKey(string $hostKey): void
    {
        $fingerprint = self::fingerprint($hostKey);

        if ($this->server->host_fingerprint === null) {
            $this->server->forceFill(['host_fingerprint' => $fingerprint])->save();

            return;
        }

        if (! hash_equals($this->server->host_fingerprint, $fingerprint)) {
            throw new ConnectionException(__('The host key of :host changed (expected :expected, got :actual). If you rebuilt the server, use "Forget host key" on the server page.', [
                'host' => $this->server->host,
                'expected' => $this->server->host_fingerprint,
                'actual' => $fingerprint,
            ]));
        }
    }

    private function credential(): PrivateKey|string
    {
        $server = $this->server;

        if ($server->auth_method === ServerAuthMethod::Password) {
            return (string) $server->password;
        }

        if ($server->auth_method === ServerAuthMethod::KeyFile) {
            $path = Shell::expandHome((string) $server->key_path);

            if (! is_file($path) || ! is_readable($path)) {
                throw new ConnectionException(__('Key file not found or not readable: :path', ['path' => $path]));
            }

            $keyText = (string) file_get_contents($path);
        } else {
            $keyText = (string) $server->private_key;
        }

        try {
            $key = filled($server->passphrase)
                ? PublicKeyLoader::load($keyText, (string) $server->passphrase)
                : PublicKeyLoader::load($keyText);
        } catch (Throwable $e) {
            throw new ConnectionException(__('The private key could not be read (wrong passphrase or format): :error', ['error' => $e->getMessage()]), previous: $e);
        }

        if (! $key instanceof PrivateKey) {
            throw new ConnectionException(__('The key is a public key. Use the private key file (without .pub).'));
        }

        return $key;
    }
}
