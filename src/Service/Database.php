<?php

declare(strict_types=1);

namespace Plugin\AzuracastShowDownloader\Service;

use App\Doctrine\ReloadableEntityManagerInterface;
use Throwable;

/**
 * Keeps database access safe around long downloads.
 *
 * A download can last several minutes. During that time no database transaction may stay open:
 * MariaDB may roll it back (deadlock, lock wait timeout, closed connection) and Doctrine then fails
 * with errors such as "SAVEPOINT DOCTRINE_2 does not exist".
 */
final class Database
{
    /** Messages of database errors that disappear when the operation is simply run again. */
    private const array TRANSIENT = [
        'SAVEPOINT',
        'Deadlock found',
        'Lock wait timeout',
        'server has gone away',
        'Lost connection',
        'Connection refused',
        'EntityManager is closed',
        'no active transaction',
    ];

    public function __construct(
        private readonly ReloadableEntityManagerInterface $em
    ) {
    }

    /**
     * Before a database step: reopens the entity manager if an earlier error closed it and
     * reconnects if the connection was dropped during a long download.
     */
    public function prepare(): void
    {
        if (method_exists($this->em, 'open')) {
            $this->em->open();
        }

        $connection = $this->em->getConnection();
        if ($connection->isTransactionActive()) {
            return;
        }
        try {
            $connection->executeQuery('SELECT 1');
        } catch (Throwable) {
            $connection->close(); // the next query opens a fresh connection
        }
    }

    /**
     * After a database error in the plugin's own work: cancels what is left of the failed
     * transaction and starts again from a clean state. Entities must be fetched again afterwards.
     */
    public function recover(): void
    {
        $connection = $this->em->getConnection();
        for ($i = 0; $i < 10 && $connection->isTransactionActive(); $i++) {
            try {
                $connection->rollBack();
            } catch (Throwable) {
                $connection->close();
                break;
            }
        }
        if (method_exists($this->em, 'open')) {
            $this->em->open();
        }
        $this->em->clear();
        $this->prepare();
    }

    public static function isTransient(Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            foreach (self::TRANSIENT as $needle) {
                if (stripos($current->getMessage(), $needle) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Message of an exception followed by its causes (Doctrine often hides the real one).
     */
    public static function describe(Throwable $e): string
    {
        $messages = [];
        for ($current = $e; $current !== null && count($messages) < 4; $current = $current->getPrevious()) {
            $message = trim($current->getMessage());
            if ($message !== '' && !in_array($message, $messages, true)) {
                $messages[] = $message;
            }
        }

        return implode(' — cause : ', $messages);
    }
}
