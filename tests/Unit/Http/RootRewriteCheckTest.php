<?php

namespace FA\Mcp\Tests\Unit\Http;

use FA\Mcp\Config;
use FA\Mcp\Http\RootRewriteCheck;
use PHPUnit\Framework\TestCase;

class RootRewriteCheckTest extends TestCase
{
    private string $htaccess;

    protected function setUp(): void
    {
        $this->htaccess = (string) tempnam(sys_get_temp_dir(), 'htaccess');
    }

    protected function tearDown(): void
    {
        if (is_file($this->htaccess)) {
            unlink($this->htaccess);
        }
    }

    private static function oauth(string $baseUrl = 'https://fa.example.com/modules/mcp'): Config
    {
        return Config::fromArray(['base_url' => $baseUrl]);
    }

    public function testTheRuleItselfIsWhatTheCheckLooksFor(): void
    {
        file_put_contents($this->htaccess, "RewriteEngine On\n" . RootRewriteCheck::RULE . "\n");
        $this->assertNull(RootRewriteCheck::problem(self::oauth(), $this->htaccess));
    }

    public function testTheRuleInsideIfModuleWithOtherFlagsCounts(): void
    {
        file_put_contents($this->htaccess, "<IfModule mod_rewrite.c>\n\tRewriteEngine On\n\tRewriteRule "
            . '^\.well-known/(oauth-authorization-server|openid-configuration|oauth-protected-resource)'
            . "/modules/mcp/?$ modules/mcp/.well-known/$1 [L,QSA]\n</IfModule>\n");
        $this->assertNull(RootRewriteCheck::problem(self::oauth(), $this->htaccess));
    }

    public function testARootHtaccessWithoutTheRuleIsAProblemThatQuotesIt(): void
    {
        file_put_contents($this->htaccess, "Options -Indexes\n");
        $problem = RootRewriteCheck::problem(self::oauth(), $this->htaccess);
        $this->assertNotNull($problem);
        $this->assertStringContainsString(RootRewriteCheck::RULE, $problem);
    }

    public function testACommentedOutRuleIsAProblem(): void
    {
        file_put_contents($this->htaccess, '# ' . RootRewriteCheck::RULE . "\n");
        $this->assertNotNull(RootRewriteCheck::problem(self::oauth(), $this->htaccess));
    }

    public function testNoRootHtaccessIsAProblem(): void
    {
        unlink($this->htaccess);
        $this->assertNotNull(RootRewriteCheck::problem(self::oauth(), $this->htaccess));
    }

    /** @return array<string, array{0: Config}> */
    public function noRuleNeeded(): array
    {
        return [
            'OAuth off' => [Config::fromArray(['base_url' => 'https://fa.example.com/modules/mcp', 'oauth' => false])],
            'no base_url' => [Config::fromArray([])],
            'FrontAccounting below the origin root' => [self::oauth('https://example.com/fa/modules/mcp')],
        ];
    }

    /** @dataProvider noRuleNeeded */
    public function testNoProblemWhenFrontAccountingsRootHtaccessCannotOrNeedNotServeIt(Config $config): void
    {
        file_put_contents($this->htaccess, "Options -Indexes\n");
        $this->assertNull(RootRewriteCheck::problem($config, $this->htaccess));
    }
}
