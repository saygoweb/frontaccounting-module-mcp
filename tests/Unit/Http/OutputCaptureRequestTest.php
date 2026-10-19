<?php

namespace FA\Mcp\Tests\Unit\Http;

use FA\Mcp\Http\OutputCapture;
use PHPUnit\Framework\TestCase;

/**
 * OutputCapture's request-wide capture: start()/end() as index.php uses them,
 * afterEmit(), and onShutdown() for a request that ends before its response.
 * Each test runs in a process of its own: the capture is static, and start()
 * registers a shutdown function.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class OutputCaptureRequestTest extends TestCase
{
    private string $log = '';

    protected function setUp(): void
    {
        $this->log = (string) tempnam(sys_get_temp_dir(), 'mcp-log');
        ini_set('error_log', $this->log);
    }

    protected function tearDown(): void
    {
        @unlink($this->log);
    }

    private function logged(): string
    {
        return (string) file_get_contents($this->log);
    }

    public function testEndReturnsAndLogsWhatWasPrintedSinceStart(): void
    {
        $this->expectOutputString('');
        $level = ob_get_level();
        OutputCapture::start();
        echo 'printed';
        ob_start();
        echo ' nested';

        $this->assertSame('printed nested', OutputCapture::end());
        $this->assertSame($level, ob_get_level());
        $this->assertStringContainsString('mcp: discarded 14 bytes of output: printed nested', $this->logged());
    }

    public function testEndWithNothingPrintedLogsNothing(): void
    {
        OutputCapture::start();
        $this->assertSame('', OutputCapture::end());
        $this->assertSame('', $this->logged());
    }

    public function testEndWithoutStartIsANoOp(): void
    {
        $level = ob_get_level();
        $this->assertSame('', OutputCapture::end());
        $this->assertSame($level, ob_get_level());
    }

    public function testStartTwiceOpensOneCapture(): void
    {
        $level = ob_get_level();
        OutputCapture::start();
        OutputCapture::start();
        $this->assertSame($level + 1, ob_get_level());
        OutputCapture::end();
        $this->assertSame($level, ob_get_level());
    }

    public function testLongOutputIsLoggedOnlyInPart(): void
    {
        OutputCapture::start();
        echo str_repeat('x', OutputCapture::LOGGED_BYTES + 10);
        OutputCapture::end();
        $logged = $this->logged();
        $this->assertStringContainsString((OutputCapture::LOGGED_BYTES + 10) . ' bytes of output: ', $logged);
        $this->assertStringContainsString(' [...]', $logged);
        $this->assertStringNotContainsString(str_repeat('x', OutputCapture::LOGGED_BYTES + 1), $logged);
    }

    public function testAfterEmitDropsAndLogsLaterOutput(): void
    {
        ob_start();
        OutputCapture::afterEmit();
        echo 'too late';
        ob_end_flush();
        $this->assertSame('', ob_get_clean());
        $this->assertStringContainsString(
            'discarded, after the response, 8 bytes of output: too late',
            $this->logged()
        );
    }

    public function testShutdownBeforeEndAnswersTheFixedErrorBody(): void
    {
        ob_start();
        OutputCapture::start();
        echo 'half a response';
        OutputCapture::onShutdown();
        ob_end_flush();
        $this->assertSame(OutputCapture::BODY, ob_get_clean());
        $logged = $this->logged();
        $this->assertStringContainsString('the request ended before its response', $logged);
        $this->assertStringContainsString('15 bytes of output: half a response', $logged);
    }

    public function testShutdownAfterEndDoesNothing(): void
    {
        OutputCapture::start();
        OutputCapture::end();
        ob_start();
        OutputCapture::onShutdown();
        $this->assertSame('', ob_get_clean());
    }
}
