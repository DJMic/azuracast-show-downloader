<?php

declare(strict_types=1);

namespace Plugin\AzuracastShowDownloader\Service;

use DateTimeInterface;
use InvalidArgumentException;

/**
 * File name helpers shared by the runner, the test action and (mirrored in JS) the editor preview.
 *
 * Date formats use readable tokens:
 *   YYYY = 2026, YY = 26, MM = 01-12, M = 1-12, DD = 01-31, D = 1-31
 * Everything else is copied literally (e.g. "YYYY-MM-DD", "DDMMYY", "YYYYMMDD").
 *
 * Patterns accept these placeholders:
 *   {date}         date with the job's date format
 *   {date:FORMAT}  date with an explicit format, e.g. {date:DD.MM.YYYY}
 *   {original}     original file name without extension (renaming only)
 *   {ext}          original extension without dot (renaming only)
 *   {show}         name of the show/job (renaming only)
 *   {n}            file number when several files are downloaded (renaming only)
 */
final class Naming
{
    private const string TOKEN_REGEX = '/YYYY|YY|MM|M|DD|D/';

    public static function formatDate(string $format, DateTimeInterface $date): string
    {
        return (string)preg_replace_callback(
            self::TOKEN_REGEX,
            static fn(array $m): string => match ($m[0]) {
                'YYYY' => $date->format('Y'),
                'YY' => $date->format('y'),
                'MM' => $date->format('m'),
                'M' => $date->format('n'),
                'DD' => $date->format('d'),
                'D' => $date->format('j'),
            },
            $format
        );
    }

    /**
     * @param array<string, string|int> $vars
     */
    public static function render(
        string $pattern,
        array $vars,
        ?DateTimeInterface $date,
        string $dateFormat
    ): string {
        $result = (string)preg_replace_callback(
            '/\{date(?::([^}]*))?\}/',
            static function (array $m) use ($date, $dateFormat): string {
                if ($date === null) {
                    return $m[0];
                }
                $format = isset($m[1]) && $m[1] !== '' ? $m[1] : $dateFormat;

                return self::formatDate($format, $date);
            },
            $pattern
        );

        foreach ($vars as $key => $value) {
            $result = str_replace('{' . $key . '}', (string)$value, $result);
        }

        return $result;
    }

    public static function splitName(string $filename): array
    {
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $base = $ext === '' ? $filename : substr($filename, 0, -strlen($ext) - 1);

        return [$base, strtolower($ext)];
    }

    public static function sanitizeFilename(string $name): string
    {
        $name = str_replace(['/', '\\', "\0"], '-', $name);
        $name = (string)preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
        $name = trim($name, " .\t");

        return $name === '' ? 'emission' : mb_substr($name, 0, 200);
    }

    /**
     * Builds the final file name; the original extension is added when the pattern has none.
     */
    public static function targetName(
        string $original,
        bool $renameEnabled,
        string $pattern,
        string $showName,
        int $index,
        ?DateTimeInterface $date,
        string $dateFormat
    ): string {
        if (!$renameEnabled || trim($pattern) === '') {
            return self::sanitizeFilename($original);
        }

        [$base, $ext] = self::splitName($original);
        $name = self::render($pattern, [
            'original' => $base,
            'ext' => $ext,
            'show' => $showName,
            'n' => $index,
        ], $date, $dateFormat);
        $name = self::sanitizeFilename($name);

        [, $newExt] = self::splitName($name);
        if ($ext !== '' && ($newExt === '' || !self::looksLikeExtension($newExt))) {
            $name .= '.' . $ext;
        }

        return $name;
    }

    private static function looksLikeExtension(string $ext): bool
    {
        return (bool)preg_match('/^[a-z0-9]{2,5}$/', $ext) && !ctype_digit($ext);
    }

    /**
     * Normalizes a directory relative to the station media root ("" = root).
     */
    public static function normalizeDirectory(string $dir): string
    {
        $dir = str_replace('\\', '/', trim($dir));
        $parts = [];
        foreach (explode('/', $dir) as $part) {
            $part = trim($part);
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                throw new InvalidArgumentException('Le dossier de destination ne peut pas contenir "..".');
            }
            if (str_starts_with($part, '.')) {
                throw new InvalidArgumentException('Les dossiers cachés (commençant par un point) ne sont pas autorisés.');
            }
            $parts[] = $part;
        }

        return implode('/', $parts);
    }

    public static function matches(string $pattern, string $filename): bool
    {
        $pattern = trim($pattern);
        if ($pattern === '' || $pattern === '*') {
            return true;
        }

        return fnmatch($pattern, $filename, FNM_CASEFOLD);
    }

    public static function hasWildcard(string $pattern): bool
    {
        return strpbrk($pattern, '*?[') !== false;
    }
}
