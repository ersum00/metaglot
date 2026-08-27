<?php

declare(strict_types=1);

namespace Metaglot\Tests\Integration;

use Metaglot\Config;
use Metaglot\Database;
use PHPUnit\Framework\TestCase;

/**
 * Base class for tests that run against a real PostgreSQL instance.
 *
 * Set METAGLOT_TEST_PG_DSN (plus METAGLOT_TEST_PG_USER / METAGLOT_TEST_PG_PASS)
 * to enable them; without it every integration test is skipped. The schema is
 * applied and all tables are truncated before each test — point it at a
 * throwaway database.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected Database $db;

    protected function setUp(): void
    {
        $dsn = getenv('METAGLOT_TEST_PG_DSN');
        if ($dsn === false || $dsn === '') {
            $this->markTestSkipped('METAGLOT_TEST_PG_DSN is not set; PostgreSQL integration tests skipped.');
        }

        $this->db = new Database(new Config(
            pgDsn: $dsn,
            pgUser: getenv('METAGLOT_TEST_PG_USER') ?: '',
            pgPass: getenv('METAGLOT_TEST_PG_PASS') ?: '',
            llmEndpoint: '',
            llmModel: '',
            llmKey: '',
        ));

        $pdo = $this->db->pdo();
        $pdo->exec((string) file_get_contents(__DIR__ . '/../../migrations/001_initial.sql'));
        $pdo->exec('TRUNCATE channels RESTART IDENTITY CASCADE');
    }

    /**
     * Inserts a channel with dummy credentials and returns its row.
     *
     * @return array<string, mixed>
     */
    protected function insertChannel(int $dailyQuota = 10000, string $targetLangs = '{en,es}'): array
    {
        $st = $this->db->pdo()->prepare(
            "INSERT INTO channels (label, refresh_token, client_id, client_secret, source_lang, target_langs, daily_quota)
             VALUES ('itest', 'dummy', 'dummy', 'dummy', 'tr', :langs, :quota)
             RETURNING *"
        );
        $st->execute([':langs' => $targetLangs, ':quota' => $dailyQuota]);

        return (array) $st->fetch();
    }
}
