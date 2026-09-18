<?php
declare(strict_types=1);

final class KiComMailSecretStore
{
    private string $varRoot;
    private string $envName;

    public function __construct(string $varRoot, string $envName = 'KICOM_MAIL_PASSWORD')
    {
        $real = realpath($varRoot);
        if ($real === false || !is_dir($real)) {
            throw new InvalidArgumentException('MAIL_VAR_ROOT_INVALID');
        }
        $this->varRoot = rtrim($real, DIRECTORY_SEPARATOR);
        $this->envName = $envName;
    }

    public function hasSecret(): bool
    {
        $env = getenv($this->envName);
        if (is_string($env) && $env !== '') {
            return true;
        }
        $path = $this->secretPath();
        return is_file($path) && filesize($path) > 0;
    }

    public function read(): string
    {
        $env = getenv($this->envName);
        if (is_string($env) && $env !== '') {
            return $env;
        }

        $path = $this->secretPath();
        if (!is_file($path)) {
            throw new RuntimeException('MAIL_SECRET_UNAVAILABLE');
        }
        $secret = file_get_contents($path);
        if (!is_string($secret)) {
            throw new RuntimeException('MAIL_SECRET_UNREADABLE');
        }
        $secret = rtrim($secret, "\r\n");
        if ($secret === '') {
            throw new RuntimeException('MAIL_SECRET_EMPTY');
        }
        return $secret;
    }

    /**
     * Provisioning boundary only. The caller must already have authenticated,
     * human-authorized secret-provisioning context. This method never returns
     * the supplied secret and does not log it.
     */
    public function provision(string $secret, bool $authorized): array
    {
        if (!$authorized) {
            throw new RuntimeException('MAIL_SECRET_PROVISION_AUTH_REQUIRED');
        }
        if ($secret === '' || strlen($secret) > 4096 || str_contains($secret, "\0")) {
            throw new InvalidArgumentException('MAIL_SECRET_INVALID');
        }

        $dir = $this->secretDir();
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('MAIL_SECRET_DIR_CREATE_FAILED');
        }
        @chmod($dir, 0700);

        $path = $this->secretPath();
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(8));
        $bytes = file_put_contents($tmp, $secret . "\n", LOCK_EX);
        if ($bytes === false) {
            throw new RuntimeException('MAIL_SECRET_WRITE_FAILED');
        }
        @chmod($tmp, 0600);
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('MAIL_SECRET_COMMIT_FAILED');
        }
        @chmod($path, 0600);

        return [
            'stored' => true,
            'source' => 'protected-var-file',
            'secret_visible' => false,
            'path_visible' => false,
        ];
    }

    public function status(): array
    {
        return [
            'configured' => $this->hasSecret(),
            'env_ref' => $this->envName,
            'secret_visible' => false,
            'path_visible' => false,
        ];
    }

    private function secretDir(): string
    {
        return $this->varRoot . DIRECTORY_SEPARATOR . 'secrets';
    }

    private function secretPath(): string
    {
        return $this->secretDir() . DIRECTORY_SEPARATOR . 'mail-password';
    }
}
