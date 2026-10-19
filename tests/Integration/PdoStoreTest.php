<?php

namespace FA\Mcp\Tests\Integration;

use FA\Mcp\OAuth\PdoStore;
use FA\Mcp\OAuth\Store;
use FA\Mcp\Tests\Unit\OAuth\StoreContractTest;

/**
 * StoreContractTest against MariaDB. The tables come from sql/update_1.0.sql
 * with the prefix zz_ in place of 0_, are created for each test and dropped
 * after it. Runs only when FA_DB_DSN, FA_DB_USER and FA_DB_PASSWORD are set
 * (tools/init.sh sets them in CI).
 */
class PdoStoreTest extends StoreContractTest
{
    private const PREFIX = 'zz_';
    private const TABLES = ['mcp_oauth_client', 'mcp_oauth_code', 'mcp_oauth_refresh'];

    private ?\PDO $pdo = null;

    protected function createStore(): Store
    {
        $dsn = getenv('FA_DB_DSN');
        $user = getenv('FA_DB_USER');
        $password = getenv('FA_DB_PASSWORD');
        if (!is_string($dsn) || $dsn === '' || !is_string($user) || !is_string($password)) {
            $this->markTestSkipped('FA_DB_DSN, FA_DB_USER and FA_DB_PASSWORD are not set.');
        }

        $this->pdo = new \PDO($dsn, $user, $password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->dropTables();
        $sql = (string)file_get_contents(dirname(__DIR__, 2) . '/sql/update_1.0.sql');
        foreach (self::statements(str_replace('`0_', '`' . self::PREFIX, $sql)) as $statement) {
            $this->pdo->exec($statement);
        }

        return new PdoStore($this->pdo, self::PREFIX);
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $this->dropTables();
            $this->pdo = null;
        }
    }

    public function testTimesAreStoredAsUtcDatetimes(): void
    {
        $this->store->saveCode($this->codeRow('h1', ['expires_at' => 1700000000]));
        $this->assertNotNull($this->pdo);
        $stored = $this->pdo->query("SELECT expires_at FROM zz_mcp_oauth_code WHERE code_hash = 'h1'");
        $this->assertNotFalse($stored);
        $this->assertSame('2023-11-14 22:13:20', $stored->fetchColumn());
    }

    public function testAMissingResourceIsStoredAsEmpty(): void
    {
        $this->store->saveCode($this->codeRow('h1', ['resource' => null]));
        $this->assertNotNull($this->pdo);
        $stored = $this->pdo->query("SELECT resource FROM zz_mcp_oauth_code WHERE code_hash = 'h1'");
        $this->assertNotFalse($stored);
        $this->assertSame('', $stored->fetchColumn());
    }

    private function dropTables(): void
    {
        foreach (self::TABLES as $table) {
            $this->pdo->exec('DROP TABLE IF EXISTS `' . self::PREFIX . $table . '`');
        }
    }

    /** @return string[] the file's statements, comments dropped */
    private static function statements(string $sql): array
    {
        $sql = (string)preg_replace('/^\s*--.*$/m', '', $sql);
        return array_values(array_filter(array_map('trim', explode(';', $sql)), function (string $s): bool {
            return $s !== '';
        }));
    }
}
