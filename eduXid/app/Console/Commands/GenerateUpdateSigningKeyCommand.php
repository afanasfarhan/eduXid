<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use RuntimeException;

class GenerateUpdateSigningKeyCommand extends Command
{
    protected $signature = 'eduxid:update-keygen {--force : Timpa key yang sudah ada di .env}';
    protected $description = 'Membuat master Ed25519 signing key eduXid pada publisher resmi.';

    public function handle(): int
    {
        if (!function_exists('sodium_crypto_sign_keypair')) {
            $this->error('PHP Sodium tidak tersedia.');
            return self::FAILURE;
        }

        $envPath = base_path('.env');

        if (!is_file($envPath)) {
            $this->error('.env tidak ditemukan.');
            return self::FAILURE;
        }

        $env = (string) file_get_contents($envPath);

        $existingPrivate = $this->envValue($env, 'EDUXID_UPDATE_PRIVATE_KEY');
        $existingPublic = $this->envValue($env, 'EDUXID_UPDATE_PUBLIC_KEY');

        if (($existingPrivate !== '' || $existingPublic !== '') && !$this->option('force')) {
            $this->error('Signing key sudah ada. Gunakan --force hanya jika benar-benar ingin melakukan key rotation.');
            return self::FAILURE;
        }

        $pair = sodium_crypto_sign_keypair();
        $private = base64_encode(sodium_crypto_sign_secretkey($pair));
        $public = base64_encode(sodium_crypto_sign_publickey($pair));

        $env = $this->putEnv($env, 'EDUXID_UPDATE_PRIVATE_KEY', $private);
        $env = $this->putEnv($env, 'EDUXID_UPDATE_PUBLIC_KEY', $public);

        if (file_put_contents($envPath, $env, LOCK_EX) === false) {
            throw new RuntimeException('Gagal menyimpan signing key ke .env.');
        }

        $this->info('Master Ed25519 keypair berhasil dibuat.');
        $this->warn('PRIVATE KEY tersimpan hanya di .env publisher. Jangan salin private key ke Guru/Git/ZIP.');
        $this->line('Public key fingerprint SHA-256: ' . hash('sha256', base64_decode($public, true)));

        return self::SUCCESS;
    }

    protected function envValue(string $env, string $key): string
    {
        if (preg_match('/^' . preg_quote($key, '/') . '=(.*)$/m', $env, $m) !== 1) {
            return '';
        }
        return trim(trim($m[1]), "\"'");
    }

    protected function putEnv(string $env, string $key, string $value): string
    {
        $line = $key . '=' . $value;
        $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';

        if (preg_match($pattern, $env) === 1) {
            return (string) preg_replace($pattern, $line, $env);
        }

        return rtrim($env) . PHP_EOL . $line . PHP_EOL;
    }
}
