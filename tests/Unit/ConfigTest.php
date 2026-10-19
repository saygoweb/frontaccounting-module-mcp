<?php

namespace FA\Mcp\Tests\Unit;

use FA\Mcp\Config;
use FA\Mcp\ConfigException;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    public function testMissingFileGivesDefaults(): void
    {
        $config = Config::fromFile('/nonexistent');

        $this->assertSame('', $config->baseUrl);
        $this->assertTrue($config->oauth);
        $this->assertFalse($config->oauthEnabled());
        $this->assertTrue($config->requireTls);
        $this->assertSame(['https://claude.ai'], $config->allowedOrigins);
        $this->assertSame(3600, $config->accessTokenTtl);
        $this->assertSame(2592000, $config->refreshTokenTtl);
        $this->assertSame(50, $config->defaultListLimit);
        $this->assertTrue($config->escapeHatch);
        $this->assertTrue($config->allowDcr);
        $this->assertTrue($config->allowCimd);
        $this->assertSame(86400, $config->cimdCacheTtl);
        $this->assertSame(10, $config->dcrRateLimit);
        $this->assertFalse($config->debug);
    }

    public function testFileReturningAnArrayIsRead(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mcpcfg');
        file_put_contents($path, "<?php\nreturn ['debug' => true, 'allowed_origins' => []];\n");
        try {
            $config = Config::fromFile($path);
        } finally {
            unlink($path);
        }

        $this->assertTrue($config->debug);
        $this->assertSame([], $config->allowedOrigins);
    }

    public function testFileNotReturningAnArrayThrows(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mcpcfg');
        file_put_contents($path, "<?php\nreturn 1;\n");
        try {
            $this->expectException(ConfigException::class);
            Config::fromFile($path);
        } finally {
            unlink($path);
        }
    }

    public function testBaseUrlEnablesOauth(): void
    {
        $config = Config::fromArray(['base_url' => 'https://fa.example.com/modules/mcp']);

        $this->assertSame('https://fa.example.com/modules/mcp', $config->baseUrl);
        $this->assertTrue($config->oauthEnabled());
    }

    public function testOauthOffDisablesOauthEvenWithBaseUrl(): void
    {
        $config = Config::fromArray(['base_url' => 'http://localhost:8080/modules/mcp', 'oauth' => false]);

        $this->assertFalse($config->oauthEnabled());
    }

    /**
     * @dataProvider invalidValues
     * @param array<string, mixed> $values
     */
    public function testInvalidValuesThrow(array $values): void
    {
        $this->expectException(ConfigException::class);
        Config::fromArray($values);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public function invalidValues(): array
    {
        return [
            'unknown key' => [['secret' => 'x']],
            'base_url trailing slash' => [['base_url' => 'https://x/y/']],
            'base_url not http(s)' => [['base_url' => 'ftp://x']],
            'base_url relative' => [['base_url' => '/modules/mcp']],
            'base_url with query' => [['base_url' => 'https://x/y?a=1']],
            'base_url with fragment' => [['base_url' => 'https://x/y#f']],
            'base_url not a string' => [['base_url' => 1]],
            'int given as string' => [['access_token_ttl' => '60']],
            'ttl zero' => [['refresh_token_ttl' => 0]],
            'list limit zero' => [['default_list_limit' => 0]],
            'list limit above 1000' => [['default_list_limit' => 1001]],
            'bool given as int' => [['oauth' => 1]],
            'origins not a list' => [['allowed_origins' => 'https://claude.ai']],
            'origin not a string' => [['allowed_origins' => [1]]],
        ];
    }

    public function testListLimitBoundsAreInclusive(): void
    {
        $this->assertSame(1, Config::fromArray(['default_list_limit' => 1])->defaultListLimit);
        $this->assertSame(1000, Config::fromArray(['default_list_limit' => 1000])->defaultListLimit);
    }
}
