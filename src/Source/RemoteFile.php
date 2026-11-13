<?php

declare(strict_types=1);

namespace Plugin\AzuracastShowDownloader\Source;

final readonly class RemoteFile
{
    public function __construct(
        public string $name,
        public ?int $size = null,
        public ?int $mtime = null
    ) {
    }

    public function toArray(): array
    {
        return ['name' => $this->name, 'size' => $this->size, 'mtime' => $this->mtime];
    }
}
