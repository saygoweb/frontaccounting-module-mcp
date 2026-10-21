<?php

namespace FA\Mcp\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * hooks_mcp::refresh_own_version() with FrontAccounting's base hooks class and
 * extension functions stubbed. The hook is global and snake_case by contract,
 * so each test runs in its own process.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class RefreshOwnVersionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/mcp-hook-' . uniqid('', true);
        mkdir($this->root . '/modules/mcp/_init', 0777, true);
        $GLOBALS['path_to_root'] = $this->root;
        $GLOBALS['fa_ext_written'] = array();
        $GLOBALS['fa_ext_list'] = array(
            array('package' => 'graphql', 'type' => 'extension', 'version' => '1.0'),
            array('package' => 'mcp', 'type' => 'extension', 'version' => '2.4-1.0.0'),
            array('package' => 'other', 'type' => 'extension', 'version' => '7'),
        );
        if (!class_exists('hooks', false)) {
            eval('class hooks { public $module_name; }');
            eval('function get_company_extensions() { return $GLOBALS["fa_ext_list"]; }');
            eval('function update_extensions($exts) { $GLOBALS["fa_ext_written"][] = $exts; return true; }');
        }
        require_once dirname(__DIR__, 2) . '/hooks.php';
    }

    protected function tearDown(): void
    {
        $init = $this->root . '/modules/mcp/_init';
        foreach (glob($init . '/*') ?: array() as $f) {
            unlink($f);
        }
        rmdir($init);
        rmdir($this->root . '/modules/mcp');
        rmdir($this->root . '/modules');
        rmdir($this->root);
    }

    private function writeConfig(string $text): void
    {
        file_put_contents($this->root . '/modules/mcp/_init/config', $text);
    }

    public function testSetsOnlyThisModulesVersion(): void
    {
        $this->writeConfig("Name: MCP server\nVersion: 2.4-9.9.9\n");
        $before = $GLOBALS['fa_ext_list'];

        (new \hooks_mcp())->refresh_own_version();

        $this->assertCount(1, $GLOBALS['fa_ext_written']);
        $written = $GLOBALS['fa_ext_written'][0];
        $this->assertSame('2.4-9.9.9', $written[1]['version']);
        $this->assertSame($before[0], $written[0]);
        $this->assertSame($before[2], $written[2]);
        $this->assertSame(serialize($before[0]), serialize($written[0]));
        $this->assertSame(serialize($before[2]), serialize($written[2]));
    }

    public function testNoConfigWritesNothing(): void
    {
        (new \hooks_mcp())->refresh_own_version();
        $this->assertSame(array(), $GLOBALS['fa_ext_written']);
    }

    public function testConfigWithoutVersionWritesNothing(): void
    {
        $this->writeConfig("Name: MCP server\n");
        (new \hooks_mcp())->refresh_own_version();
        $this->assertSame(array(), $GLOBALS['fa_ext_written']);
    }

    public function testMatchingVersionWritesNothing(): void
    {
        $this->writeConfig("Version: 2.4-1.0.0\n");
        (new \hooks_mcp())->refresh_own_version();
        $this->assertSame(array(), $GLOBALS['fa_ext_written']);
    }
}
