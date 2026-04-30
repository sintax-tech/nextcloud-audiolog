<?php
declare(strict_types=1);

namespace OCA\Audiolog\Tests\Unit\Service;

use OCA\Audiolog\Service\CryptoHelper;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

class CryptoHelperTest extends TestCase {
    private ICrypto $crypto;
    private CryptoHelper $helper;

    protected function setUp(): void {
        $this->crypto = $this->createMock(ICrypto::class);
        // Round-trip the value through the mock so the helper sees a real
        // encrypt/decrypt pair, just without OpenSSL involvement.
        $this->crypto->method('encrypt')->willReturnCallback(fn ($p) => '__enc__' . $p);
        $this->crypto->method('decrypt')->willReturnCallback(
            fn ($s) => str_starts_with($s, '__enc__') ? substr($s, 7) : $s
        );
        $this->helper = new CryptoHelper($this->crypto);
    }

    public function testEncryptEmptyStringReturnsEmpty(): void {
        $this->assertSame('', $this->helper->encrypt(''));
    }

    public function testEncryptedValueIsTaggedAndBase64(): void {
        $cipher = $this->helper->encrypt('AIza-secret');
        $this->assertStringStartsWith('enc:v1:', $cipher);
        // The part after the prefix must be valid base64.
        $payload = substr($cipher, strlen('enc:v1:'));
        $this->assertNotFalse(base64_decode($payload, true));
    }

    public function testRoundTrip(): void {
        $cipher = $this->helper->encrypt('AIza-secret');
        $this->assertSame('AIza-secret', $this->helper->decrypt($cipher));
    }

    public function testDecryptOfLegacyPlainTextValueIsPassthrough(): void {
        // Old configs stored the key in plaintext. Helper must still return
        // the value during the migration window.
        $this->assertSame('legacy-plaintext', $this->helper->decrypt('legacy-plaintext'));
    }

    public function testDecryptOfCorruptedBase64ReturnsEmpty(): void {
        $this->assertSame('', $this->helper->decrypt('enc:v1:!@#not-base64'));
    }
}
