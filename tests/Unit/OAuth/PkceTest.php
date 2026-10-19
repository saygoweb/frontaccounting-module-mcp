<?php

namespace FA\Mcp\Tests\Unit\OAuth;

use FA\Mcp\OAuth\Pkce;
use PHPUnit\Framework\TestCase;

class PkceTest extends TestCase
{
    public function testRfc7636AppendixB(): void
    {
        $this->assertTrue(Pkce::verify(
            'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk',
            'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM'
        ));
    }

    public function testWrongVerifier(): void
    {
        $this->assertFalse(Pkce::verify(
            'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXX',
            'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM'
        ));
    }

    public function testShortVerifierRejected(): void
    {
        $verifier = str_repeat('a', 42);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $this->assertFalse(Pkce::verify($verifier, $challenge));
    }

    public function testVerifierOutsideAlphabetRejected(): void
    {
        $verifier = str_repeat('a', 42) . '+';
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $this->assertFalse(Pkce::verify($verifier, $challenge));
    }
}
