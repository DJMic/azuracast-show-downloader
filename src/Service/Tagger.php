<?php

declare(strict_types=1);

namespace Plugin\AzuracastShowDownloader\Service;

use App\Entity\StationMedia;
use App\Media\MetadataManager;
use DateTimeInterface;

/**
 * Optional tag changes (title, artist, album, genre) on downloaded files.
 *
 * The tags are written into the file with AzuraCast's own metadata writer before the import,
 * so AzuraCast reads the new values like for any uploaded file. AzuraCast can write tags into
 * MP3, FLAC and OGG files; for other formats (M4A, AAC, WAV, Opus…) the values are only stored
 * in AzuraCast's media library (what is shown and announced on air), the file stays as it is.
 */
final class Tagger
{
    public const array FIELDS = ['title', 'artist', 'album', 'genre'];
    public const array WRITABLE_EXTENSIONS = ['mp3', 'mp2', 'flac', 'ogg'];

    public const array LABELS = [
        'title' => 'Titre',
        'artist' => 'Artiste',
        'album' => 'Album',
        'genre' => 'Genre',
    ];

    public function __construct(
        private readonly MetadataManager $metadataManager
    ) {
    }

    /**
     * Final tag values for one file; fields left empty in the job are not changed.
     *
     * @return array<string, string>
     */
    public static function values(array $job, string $original, int $index, DateTimeInterface $date): array
    {
        $tags = $job['tags'] ?? [];
        if (empty($tags['enabled'])) {
            return [];
        }

        [$base] = Naming::splitName($original);
        $values = [];
        foreach (self::FIELDS as $field) {
            $pattern = trim((string)($tags[$field] ?? ''));
            if ($pattern === '') {
                continue;
            }
            $value = Naming::render($pattern, [
                'original' => $base,
                'show' => (string)$job['name'],
                'n' => $index,
            ], $date, (string)$job['file']['date_format']);
            $value = trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));
            if ($value !== '') {
                $values[$field] = mb_substr($value, 0, 200);
            }
        }

        return $values;
    }

    /**
     * Writes the values into a local file (its name must end with the real extension).
     *
     * @param array<string, string> $values
     *
     * @return string|null A note for the history when the file itself could not be changed.
     */
    public function writeFile(string $localPath, array $values): ?string
    {
        $ext = strtolower(pathinfo($localPath, PATHINFO_EXTENSION));
        if (!in_array($ext, self::WRITABLE_EXTENSIONS, true)) {
            return sprintf(
                'Tags enregistrés dans AzuraCast uniquement : le format .%s ne permet pas de les écrire dans le fichier.',
                $ext === '' ? '?' : $ext
            );
        }

        $metadata = $this->metadataManager->read($localPath);
        $tags = $metadata->getKnownTags();
        foreach ($values as $field => $value) {
            $tags[$field] = $value;
        }
        $metadata->setKnownTags($tags);

        if (!$this->metadataManager->write($metadata, $localPath)) {
            return 'Les tags n\'ont pas pu être écrits dans le fichier (détails dans les journaux d\'AzuraCast) ; '
                . 'ils sont enregistrés dans AzuraCast.';
        }

        return null;
    }

    /**
     * Applies the values to the media record (what AzuraCast shows and sends to listeners).
     *
     * @param array<string, string> $values
     */
    public static function applyToMedia(StationMedia $media, array $values): void
    {
        foreach ($values as $field => $value) {
            match ($field) {
                'title' => $media->title = $value,
                'artist' => $media->artist = $value,
                'album' => $media->album = $value,
                'genre' => $media->genre = $value,
                default => null,
            };
        }
        $media->updateMetaFields();
    }

    /**
     * @param array<string, string> $values
     */
    public static function describe(array $values): string
    {
        $parts = [];
        foreach ($values as $field => $value) {
            $parts[] = self::LABELS[$field] . ' « ' . $value . ' »';
        }

        return implode(', ', $parts);
    }
}
