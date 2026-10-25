<?php

namespace Tests\Unit\Services;

use App\Services\TotpService;
use Tests\TestCase;

class TotpServiceTest extends TestCase
{
    protected TotpService $totpService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->totpService = new TotpService();
    }

    public function test_generate_secret_returns_valid_base32_length(): void
    {
        $secret = $this->totpService->generateSecret(32);
        $this->assertEquals(32, strlen($secret));
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
    }

    public function test_generate_recovery_codes_count_and_format(): void
    {
        $codes = $this->totpService->generateRecoveryCodes(8);
        $this->assertCount(8, $codes);
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[A-F0-9]{8}-[A-F0-9]{8}$/', $code);
        }
    }

    public function test_get_qr_uri_format(): void
    {
        $secret = 'JBSWY3DPEHPK3PXP';
        $uri = $this->totpService->getQrUri($secret, 'test@example.com', 'TestCRM');

        $this->assertStringStartsWith('otpauth://totp/TestCRM:test%40example.com', $uri);
        $this->assertStringContainsString('secret=JBSWY3DPEHPK3PXP', $uri);
        $this->assertStringContainsString('issuer=TestCRM', $uri);
    }

    public function test_verify_fails_with_invalid_code(): void
    {
        $secret = $this->totpService->generateSecret();
        $this->assertFalse($this->totpService->verify($secret, '000000'));
        $this->assertFalse($this->totpService->verify($secret, 'abcdef'));
    }
}
