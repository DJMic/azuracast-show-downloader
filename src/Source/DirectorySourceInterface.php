<?php

declare(strict_types=1);

namespace Plugin\AzuracastShowDownloader\Source;

/**
 * A server with directories (FTP, FTPS, SFTP).
 */
interface DirectorySourceInterface
{
    /**
     * @return RemoteFile[] Regular files of the remote directory.
     */
    public function listFiles(): array;

    /**
     * Adds size/modification time when the listing did not provide them.
     */
    public function stat(RemoteFile $file): RemoteFile;

    public function download(RemoteFile $file, string $localPath): void;

    public function close(): void;
}
