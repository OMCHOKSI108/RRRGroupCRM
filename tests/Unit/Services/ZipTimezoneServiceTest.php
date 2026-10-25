<?php

namespace Tests\Unit\Services;

use App\Services\ZipTimezoneService;
use Tests\TestCase;

class ZipTimezoneServiceTest extends TestCase
{
    public function test_detects_eastern_timezone(): void
    {
        $this->assertEquals('America/New_York', ZipTimezoneService::detect('10001'));
        $this->assertEquals('America/New_York', ZipTimezoneService::detect('02138'));
    }

    public function test_detects_central_timezone(): void
    {
        $this->assertEquals('America/Chicago', ZipTimezoneService::detect('60601'));
        $this->assertEquals('America/Chicago', ZipTimezoneService::detect('75001'));
    }

    public function test_detects_mountain_timezone(): void
    {
        $this->assertEquals('America/Denver', ZipTimezoneService::detect('80201'));
        $this->assertEquals('America/Denver', ZipTimezoneService::detect('85001'));
    }

    public function test_detects_pacific_timezone(): void
    {
        $this->assertEquals('America/Los_Angeles', ZipTimezoneService::detect('90210'));
        $this->assertEquals('America/Los_Angeles', ZipTimezoneService::detect('98101'));
    }

    public function test_detects_alaska_and_hawaii(): void
    {
        $this->assertEquals('America/Anchorage', ZipTimezoneService::detect('99501'));
        $this->assertEquals('Pacific/Honolulu', ZipTimezoneService::detect('96801'));
    }

    public function test_detects_puerto_rico(): void
    {
        $this->assertEquals('America/Puerto_Rico', ZipTimezoneService::detect('00901'));
    }

    public function test_returns_null_for_null_or_empty_zip(): void
    {
        $this->assertNull(ZipTimezoneService::detect(null));
        $this->assertNull(ZipTimezoneService::detect(''));
        $this->assertNull(ZipTimezoneService::detect('12'));
    }

    public function test_returns_null_for_unknown_zip_prefix(): void
    {
        $this->assertNull(ZipTimezoneService::detect('00000'));
        $this->assertNull(ZipTimezoneService::detect('00100'));
    }

    public function test_detects_untrimmed_zip_code(): void
    {
        $this->assertEquals('America/Los_Angeles', ZipTimezoneService::detect(' 90210 '));
    }
}
