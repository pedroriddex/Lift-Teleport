<?php

declare(strict_types=1);

namespace LiftTeleport\Archive;

use RuntimeException;

final class TarInspector
{
    private const BLOCK_SIZE = 512;

    /**
     * @return array<int,array{path:string,type:string}>
     */
    public function listEntries(string $tarPath): array
    {
        $handle = @fopen($tarPath, 'rb');
        if ($handle === false) {
            throw new RuntimeException(sprintf('Unable to open TAR archive: %s', $tarPath));
        }

        $entries = [];
        $globalPax = [];
        $nextPax = [];
        $nextLongName = '';

        try {
            while (true) {
                $header = $this->readExact($handle, self::BLOCK_SIZE);
                if ($header === '') {
                    break;
                }

                if (strlen($header) !== self::BLOCK_SIZE) {
                    throw new RuntimeException('Invalid TAR header: archive appears truncated.');
                }

                if ($this->isZeroBlock($header)) {
                    break;
                }

                $name = $this->readString($header, 0, 100);
                $prefix = $this->readString($header, 345, 155);
                $typeFlag = $header[156] ?? "\0";
                $size = $this->parseNumericField(substr($header, 124, 12));

                $path = $name;
                if ($prefix !== '') {
                    $path = $prefix . '/' . $name;
                }
                $path = ltrim(str_replace('\\', '/', $path), '/');

                if ($typeFlag === 'x' || $typeFlag === 'g') {
                    $data = $this->readData($handle, $size);
                    $pax = $this->parsePaxRecords($data);
                    if ($typeFlag === 'g') {
                        $globalPax = array_merge($globalPax, $pax);
                    } else {
                        $nextPax = array_merge($nextPax, $pax);
                    }
                    $this->skipPadding($handle, $size);
                    continue;
                }

                if ($typeFlag === 'L') {
                    $data = $this->readData($handle, $size);
                    $nextLongName = trim(str_replace('\\', '/', rtrim($data, "\0\r\n")));
                    $this->skipPadding($handle, $size);
                    continue;
                }

                $pax = array_merge($globalPax, $nextPax);
                if ($nextLongName !== '') {
                    $path = ltrim($nextLongName, '/');
                } elseif (isset($pax['path']) && is_string($pax['path'])) {
                    $path = ltrim(str_replace('\\', '/', $pax['path']), '/');
                }

                $type = $this->mapEntryType($typeFlag);
                if ($path !== '') {
                    $entries[] = [
                        'path' => $path,
                        'type' => $type,
                    ];
                }

                $this->skipData($handle, $size);
                $this->skipPadding($handle, $size);
                $nextPax = [];
                $nextLongName = '';
            }
        } finally {
            fclose($handle);
        }

        return $entries;
    }

    private function readString(string $buffer, int $offset, int $length): string
    {
        $value = substr($buffer, $offset, $length);
        if (! is_string($value)) {
            return '';
        }

        return trim($value, "\0 ");
    }

    private function parseNumericField(string $field): int
    {
        if ($field === '') {
            return 0;
        }

        $first = ord($field[0]);
        if (($first & 0x80) === 0x80) {
            $bytes = unpack('C*', $field);
            if (! is_array($bytes) || $bytes === []) {
                return 0;
            }

            $value = (int) ($bytes[1] & 0x7F);
            $count = count($bytes);
            for ($i = 2; $i <= $count; $i++) {
                $value = ($value << 8) | (int) $bytes[$i];
            }

            return max(0, $value);
        }

        $value = trim($field, "\0 ");
        if ($value === '') {
            return 0;
        }

        if (preg_match('/^[0-7]+$/', $value) === 1) {
            return (int) octdec($value);
        }

        if (is_numeric($value)) {
            return max(0, (int) $value);
        }

        throw new RuntimeException('Invalid TAR numeric field encountered.');
    }

    private function readData($handle, int $size): string
    {
        if ($size <= 0) {
            return '';
        }

        $remaining = $size;
        $chunks = [];
        while ($remaining > 0) {
            $chunk = fread($handle, min(1048576, $remaining));
            if (! is_string($chunk) || $chunk === '') {
                throw new RuntimeException('Invalid TAR archive: archive appears truncated.');
            }

            $chunks[] = $chunk;
            $remaining -= strlen($chunk);
        }

        return implode('', $chunks);
    }

    private function skipData($handle, int $size): void
    {
        if ($size <= 0) {
            return;
        }

        if (@fseek($handle, $size, SEEK_CUR) === 0) {
            return;
        }

        $remaining = $size;
        while ($remaining > 0) {
            $chunk = fread($handle, min(1048576, $remaining));
            if (! is_string($chunk) || $chunk === '') {
                throw new RuntimeException('Invalid TAR archive: archive appears truncated.');
            }
            $remaining -= strlen($chunk);
        }
    }

    private function skipPadding($handle, int $size): void
    {
        if ($size <= 0) {
            return;
        }

        $padding = (self::BLOCK_SIZE - ($size % self::BLOCK_SIZE)) % self::BLOCK_SIZE;
        if ($padding <= 0) {
            return;
        }

        $this->skipData($handle, $padding);
    }

    private function isZeroBlock(string $block): bool
    {
        return trim($block, "\0") === '';
    }

    private function mapEntryType(string $typeFlag): string
    {
        return match ($typeFlag) {
            '5' => 'dir',
            '2' => 'symlink',
            '1' => 'hardlink',
            default => 'file',
        };
    }

    /**
     * @return array<string,string>
     */
    private function parsePaxRecords(string $data): array
    {
        $records = [];
        $offset = 0;
        $length = strlen($data);

        while ($offset < $length) {
            $spacePos = strpos($data, ' ', $offset);
            if ($spacePos === false) {
                break;
            }

            $lenRaw = substr($data, $offset, $spacePos - $offset);
            if ($lenRaw === '' || preg_match('/^\d+$/', $lenRaw) !== 1) {
                break;
            }

            $recordLen = (int) $lenRaw;
            if ($recordLen <= 0 || ($offset + $recordLen) > $length) {
                break;
            }

            $record = substr($data, $offset, $recordLen);
            if (! is_string($record) || $record === '') {
                break;
            }

            $payload = substr($record, strlen($lenRaw) + 1);
            if (! is_string($payload) || $payload === '') {
                $offset += $recordLen;
                continue;
            }

            $payload = rtrim($payload, "\n");
            $eqPos = strpos($payload, '=');
            if ($eqPos !== false) {
                $key = substr($payload, 0, $eqPos);
                $value = substr($payload, $eqPos + 1);
                if (is_string($key) && $key !== '' && is_string($value)) {
                    $records[$key] = $value;
                }
            }

            $offset += $recordLen;
        }

        return $records;
    }

    private function readExact($handle, int $length): string
    {
        if ($length <= 0) {
            return '';
        }

        $buffer = '';
        while (strlen($buffer) < $length) {
            $chunk = fread($handle, $length - strlen($buffer));
            if (! is_string($chunk) || $chunk === '') {
                return $buffer;
            }
            $buffer .= $chunk;
        }

        return $buffer;
    }
}

