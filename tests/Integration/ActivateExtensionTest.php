<?php

namespace FA\Mcp\Tests\Integration;

use FA\GraphQL\Config;
use FA\GraphQL\Fa\Bootstrap;
use FA\GraphQL\Fa\CompanyContext;
use FA\GraphQL\Fa\FaMessages;
use FA\GraphQL\Fa\FaSession;
use FA\Mcp\Http\RootRewriteCheck;
use PHPUnit\Framework\TestCase;

/**
 * hooks_mcp::activate_extension() in-process, with FrontAccounting loaded the
 * way graphql's own ActivateExtensionTest loads it.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ActivateExtensionTest extends TestCase
{
    /** A company id no install uses, for a scratch installed_extensions.php. */
    private const SCRATCH = 97;

    private string $root;

    /** FrontAccounting's root .htaccess before a test replaced it, or null. */
    private ?string $htaccess = null;

    protected function setUp(): void
    {
        if (!class_exists(Bootstrap::class)) {
            $this->markTestSkipped('No GraphQL module beside this one: run in the FrontAccounting CI image.');
        }
        $this->root = Bootstrap::defaultRoot();
        if (!is_file($this->root . '/config_db.php')) {
            $this->markTestSkipped('No FrontAccounting install at ' . $this->root . ': run in the FA CI image.');
        }
        (new FaSession(Config::fromArray(['secret' => str_repeat('x', 32)])))->openCompany(0);
        // What admin/inst_module.php has loaded when it calls the hook.
        Bootstrap::includeFa('admin/db/maintenance_db.inc');
        require_once dirname(__DIR__, 2) . '/hooks.php';
        FaMessages::reset();
    }

    protected function tearDown(): void
    {
        if ($this->htaccess !== null) {
            file_put_contents($this->root . '/.htaccess', $this->htaccess);
        }
        $dir = $this->root . '/company/' . self::SCRATCH;
        if (is_file($dir . '/installed_extensions.php')) {
            unlink($dir . '/installed_extensions.php');
        }
        if (is_dir($dir)) {
            rmdir($dir);
        }
        CompanyContext::reset();
    }

    public function testActivationForCompanyZeroHasTheThreeTables(): void
    {
        $this->assertTrue((bool) (new \hooks_mcp())->activate_extension(0, false), implode("\n", FaMessages::errors()));
        $this->assertTrue((bool) (new \hooks_mcp())->activate_extension(0, true), 'check only: the tables are there');

        $c = $GLOBALS['db_connections'][0];
        $pdo = new \PDO("mysql:host={$c['host']};dbname={$c['dbname']}", $c['dbuser'], $c['dbpassword']);
        $tables = $pdo->query("SHOW TABLES LIKE '" . $c['tbpref'] . "mcp\\_oauth\\_%'")->fetchAll(\PDO::FETCH_COLUMN);
        sort($tables);
        $this->assertSame(
            [$c['tbpref'] . 'mcp_oauth_client', $c['tbpref'] . 'mcp_oauth_code', $c['tbpref'] . 'mcp_oauth_refresh'],
            $tables
        );
    }

    /** tools/init.sh's config_mcp.php serves OAuth at http://localhost/modules/mcp. */
    private function rootHtaccess(string $contents): void
    {
        $this->htaccess = (string) file_get_contents($this->root . '/.htaccess');
        file_put_contents($this->root . '/.htaccess', $contents);
    }

    public function testActivationWithoutTheRootRuleWarnsAndGoesAhead(): void
    {
        $this->rootHtaccess("Options -Indexes\n");

        $this->assertTrue((bool) (new \hooks_mcp())->activate_extension(0, false), implode("\n", FaMessages::errors()));
        $warnings = FaMessages::warnings();
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString(RootRewriteCheck::RULE, $warnings[0]);
    }

    public function testActivationWithTheRootRuleDoesNotWarn(): void
    {
        $this->rootHtaccess("RewriteEngine On\n" . RootRewriteCheck::RULE . "\n");

        $this->assertTrue((bool) (new \hooks_mcp())->activate_extension(0, false), implode("\n", FaMessages::errors()));
        $this->assertSame([], FaMessages::warnings());
    }

    public function testRefusedWhenGraphQlIsNotActiveForTheCompany(): void
    {
        $installed_extensions = [];
        include $this->root . '/company/0/installed_extensions.php';
        $found = false;
        foreach ($installed_extensions as $id => $ext) {
            if ($ext['package'] === 'graphql') {
                $installed_extensions[$id]['active'] = false;
                $found = true;
            }
        }
        $this->assertTrue($found, 'graphql is installed in company 0');
        mkdir($this->root . '/company/' . self::SCRATCH);
        file_put_contents(
            $this->root . '/company/' . self::SCRATCH . '/installed_extensions.php',
            "<?php\n\n\$installed_extensions = " . var_export($installed_extensions, true) . ";\n"
        );

        $this->assertFalse((new \hooks_mcp())->activate_extension(self::SCRATCH, false));
        $errors = FaMessages::errors();
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('GraphQL module', $errors[0]);
    }

    public function testRefusedWhenTheCompanyHasNoExtensionList(): void
    {
        $this->assertFalse((new \hooks_mcp())->activate_extension(self::SCRATCH + 1, true));
        $this->assertStringContainsString('GraphQL module', implode("\n", FaMessages::errors()));
    }

    public function testTheUpdateFileCreatesTheTablesInTheCompanysPrefix(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/sql/update_1.0.sql');
        foreach (['client', 'code', 'refresh'] as $table) {
            $this->assertStringContainsString('`0_mcp_oauth_' . $table . '`', $sql);
        }
        $this->assertStringNotContainsString('mcp_oauth_rate', $sql);
    }
}
