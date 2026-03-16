<?php

declare(strict_types=1);

namespace LiftTeleport\Archive;

use RuntimeException;

final class Encryption
{
    public const MAGIC = 'LIFT1E';
    private const VERSION = 1;

    public function isSupported(): bool
    {
        return extension_loaded('sodium')
            && function_exists('sodium_crypto_pwhash')
            && function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push');
    }

    public function isEncryptedFile(string $path): bool
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        $magic = fread($handle, strlen(self::MAGIC));
        fclose($handle);

        return $magic === self::MAGIC;
    }

    public function encryptFile(string $inputPath, string $outputPath, string $password, ?callable $progressCallback = null): void
    {
        if (! $this->isSupported()) {
            throw new RuntimeException('Sodium extension is required for encrypted exports.');
        }

        $in = fopen($inputPath, 'rb');
        $out = fopen($outputPath, 'wb');

        if ($in === false || $out === false) {
            throw new RuntimeException('Unable to open files for encryption.');
        }

        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $ops = SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE;
        $mem = SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE;

        $key = sodium_crypto_pwhash(
            SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES,
            $password,
            $salt,
            $ops,
            $mem,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
        );

        $init = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        if (! is_array($init) || count($init) !== 2) {
            fclose($in);
            fclose($out);
            throw new RuntimeException('Failed to initialize encryption stream.');
        }

        [$state, $streamHeader] = $init;

        fwrite($out, self::MAGIC);
        fwrite($out, chr(self::VERSION));
        fwrite($out, $salt);
        fwrite($out, pack('N', (int) $ops));
        fwrite($out, pack('N', (int) $mem));
        fwrite($out, $streamHeader);

        $totalBytes = file_exists($inputPath) ? (int) filesize($inputPath) : 0;
        $bytesDone = 0;
        $startedAt = microtime(true);

        while (! feof($in)) {
            $chunk = fread($in, 1024 * 1024);
            if ($chunk === false) {
                break;
            }

            $isFinal = feof($in);
            $tag = $isFinal
                ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;

            $encrypted = sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $tag);
            fwrite($out, pack('N', strlen($encrypted)));
            fwrite($out, $encrypted);

            $bytesDone += strlen($chunk);
            if ($progressCallback !== null) {
                $progressCallback([
                    'bytes_done' => $bytesDone,
                    'bytes_total' => max(1, $totalBytes),
                    'elapsed_seconds' => round(microtime(true) - $startedAt, 3),
                ]);
            }

            if ($isFinal) {
                break;
            }
        }

        fclose($in);
        fclose($out);

        sodium_memzero($key);

        if ($progressCallback !== null) {
            $progressCallback([
                'bytes_done' => max($bytesDone, $totalBytes),
                'bytes_total' => max(1, $totalBytes),
                'elapsed_seconds' => round(microtime(true) - $startedAt, 3),
            ]);
        }
    }

    public function decryptFile(string $inputPath, string $outputPath, string $password): void
    {
        if (! $this->isSupported()) {
            throw new RuntimeException('Sodium extension is required for encrypted imports.');
        }

        $in = fopen($inputPath, 'rb');
        $out = fopen($outputPath, 'wb');
        if ($in === false || $out === false) {
            throw new RuntimeException('Unable to open files for decryption.');
        }

        $magic = fread($in, strlen(self::MAGIC));
        if ($magic !== self::MAGIC) {
            fclose($in);
            fclose($out);
            throw new RuntimeException('Invalid encrypted file magic.');
        }

        $version = ord((string) fread($in, 1));
        if ($version !== self::VERSION) {
            fclose($in);
            fclose($out);
            throw new RuntimeException('Unsupported encryption format version.');
        }

        $salt = fread($in, SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $opsData = fread($in, 4);
        $memData = fread($in, 4);
        $streamHeader = fread($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);

        if ($salt === false || $opsData === false || $memData === false || $streamHeader === false) {
            fclose($in);
            fclose($out);
            throw new RuntimeException('Encrypted header is incomplete.');
        }

        $ops = unpack('N', $opsData)[1] ?? SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE;
        $mem = unpack('N', $memData)[1] ?? SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE;

        $key = sodium_crypto_pwhash(
            SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES,
            $password,
            $salt,
            (int) $ops,
            (int) $mem,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
        );

        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($streamHeader, $key);

        while (! feof($in)) {
            $lengthData = fread($in, 4);
            if ($lengthData === '' || $lengthData === false) {
                break;
            }

            $length = unpack('N', $lengthData)[1] ?? 0;
            if ($length <= 0) {
                break;
            }

            $cipher = fread($in, $length);
            if ($cipher === false || strlen($cipher) !== $length) {
                fclose($in);
                fclose($out);
                throw new RuntimeException('Encrypted payload is truncated.');
            }

            $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipher);
            if ($result === false || ! is_array($result)) {
                fclose($in);
                fclose($out);
                throw new RuntimeException('Invalid password or corrupted encrypted archive.');
            }

            [$plain, $tag] = $result;
            fwrite($out, $plain);

            if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                break;
            }
        }

        fclose($in);
        fclose($out);
        sodium_memzero($key);
    }
}
