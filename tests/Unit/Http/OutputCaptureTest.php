<?php

namespace FA\Mcp\Tests\Unit\Http;

use FA\Mcp\Http\OutputCapture;
use PHPUnit\Framework\TestCase;

/**
 * OutputCapture::around(), the capture McpAction runs the protocol inside.
 * index.php's start()/end() pair is driven by the http suite.
 */
class OutputCaptureTest extends TestCase
{
    private string $log = '';
    /** @var string|false */
    private $previousLog = false;

    protected function setUp(): void
    {
        $this->log = (string) tempnam(sys_get_temp_dir(), 'mcp-log');
        $this->previousLog = ini_set('error_log', $this->log);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', (string) $this->previousLog);
        @unlink($this->log);
    }

    public function testReturnsTheResultAndDropsWhatWasPrinted(): void
    {
        $this->expectOutputString('');
        $level = ob_get_level();
        $result = OutputCapture::around(function (): string {
            echo 'Notice: x';
            return 'the result';
        });
        $this->assertSame('the result', $result);
        $this->assertSame($level, ob_get_level());
        $this->assertStringContainsString(
            'mcp: discarded 9 bytes of output: Notice: x',
            (string) file_get_contents($this->log)
        );
    }

    public function testNothingPrintedLogsNothing(): void
    {
        OutputCapture::around(function (): int {
            return 1;
        });
        $this->assertSame('', (string) file_get_contents($this->log));
    }

    public function testBuffersLeftOpenAreClosedInPrintOrder(): void
    {
        $this->expectOutputString('');
        $level = ob_get_level();
        OutputCapture::around(function (): void {
            echo 'a';
            ob_start();
            echo 'b';
            ob_start();
            echo 'c';
        });
        $this->assertSame($level, ob_get_level());
        $this->assertStringContainsString('3 bytes of output: abc', (string) file_get_contents($this->log));
    }

    public function testTheBufferIsClosedWhenTheCallableThrows(): void
    {
        $this->expectOutputString('');
        $level = ob_get_level();
        try {
            OutputCapture::around(function (): void {
                echo 'partial';
                throw new \RuntimeException('boom');
            });
            $this->fail('the exception passes through');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }
        $this->assertSame($level, ob_get_level());
        $this->assertStringContainsString('partial', (string) file_get_contents($this->log));
    }

    /**
     * A buffer opened without PHP_OUTPUT_HANDLER_REMOVABLE cannot be closed:
     * it is emptied and left, instead of looping forever. In a PHP of its own,
     * since such a buffer outlives the test (and PHPUnit's own process
     * isolation loops on it).
     */
    public function testANonRemovableBufferDoesNotLoopForever(): void
    {
        $script = 'require ' . var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true) . ';'
            . 'ini_set("error_log", "/dev/null");'
            . '\\FA\\Mcp\\Http\\OutputCapture::around(function () {'
            . ' ob_start(null, 0, PHP_OUTPUT_HANDLER_CLEANABLE | PHP_OUTPUT_HANDLER_FLUSHABLE); echo "stuck"; });'
            . 'fwrite(STDERR, "returned at level " . ob_get_level());';
        $command = 'timeout 20 ' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' 2>&1';
        exec($command, $lines, $status);
        $this->assertSame(0, $status, implode("\n", $lines));
        $this->assertStringContainsString('returned at level', implode("\n", $lines));
        $this->assertStringNotContainsString('stuck', implode("\n", $lines));
    }
}
