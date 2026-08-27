<?php

declare(strict_types=1);

namespace Metaglot;

use PDO;

/**
 * Lazily opened PDO connection, shared for the lifetime of the process.
 */
class Database
{
    private ?PDO $pdo = null;

    public function __construct(private readonly Config $config)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = new PDO(
                $this->config->pgDsn,
                $this->config->pgUser,
                $this->config->pgPass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ],
            );
        }

        return $this->pdo;
    }
}
