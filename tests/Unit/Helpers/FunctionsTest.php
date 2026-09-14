<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use Tests\TestCase;
use Illuminate\Support\Carbon;

/**
 * Two globally autoloaded helpers. `isoDate()` in particular is used by every
 * API resource, so a change in its output is a change to the wire format.
 */
class FunctionsTest extends TestCase
{
    /** @var array<string, string|null> */
    private array $originalServer = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $header) {
            $this->originalServer[$header] = $_SERVER[$header] ?? null;
            unset($_SERVER[$header]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalServer as $header => $value) {
            if ($value === null) {
                unset($_SERVER[$header]);
            } else {
                $_SERVER[$header] = $value;
            }
        }

        parent::tearDown();
    }

    public function test_iso_date_formats_a_carbon(): void
    {
        $this->assertSame(
            '2024-12-18T08:26:47+00:00',
            isoDate(Carbon::parse('2024-12-18T08:26:47Z')),
        );
    }

    public function test_iso_date_parses_a_string(): void
    {
        $this->assertSame('2024-12-18T00:00:00+00:00', isoDate('2024-12-18'));
    }

    /**
     * Resources call this on nullable columns, so null in must be null out rather
     * than "now".
     */
    public function test_iso_date_passes_null_through(): void
    {
        $this->assertNull(isoDate(null));
    }

    public function test_iso_date_preserves_the_offset_of_a_non_utc_value(): void
    {
        $tehran = Carbon::parse('2024-12-18T00:00:00', 'Asia/Tehran');

        $this->assertSame('2024-12-18T00:00:00+03:30', isoDate($tehran));
    }

    public function test_get_real_ip_prefers_the_cloudflare_header(): void
    {
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.7';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1';

        $this->assertSame('203.0.113.7', getRealIp());
    }

    public function test_get_real_ip_falls_back_to_the_forwarded_header(): void
    {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1';

        $this->assertSame('198.51.100.1', getRealIp());
    }

    /**
     * X-Forwarded-For accumulates a chain; the originating client is the first entry.
     */
    public function test_get_real_ip_takes_the_first_address_of_a_proxy_chain(): void
    {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1, 10.0.0.1, 10.0.0.2';

        $this->assertSame('198.51.100.1', getRealIp());
    }

    public function test_get_real_ip_ignores_an_empty_header(): void
    {
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '';
        $_SERVER['HTTP_X_REAL_IP'] = '203.0.113.9';

        $this->assertSame('203.0.113.9', getRealIp());
    }

    /**
     * With no proxy header at all it falls back to the framework's own view of the
     * request rather than returning a header value from a previous request.
     */
    public function test_get_real_ip_falls_back_to_the_request_when_no_header_is_present(): void
    {
        $this->assertSame(request()->ip(), getRealIp());
    }
}
