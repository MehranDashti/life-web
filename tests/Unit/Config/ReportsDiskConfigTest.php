<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use Tests\TestCase;

/**
 * The reports disk claims, in a comment, that the storage backend is a
 * configuration change. It previously declared only `driver` and `root`, so
 * selecting `s3` resolved a disk with no bucket and no credentials and the
 * promised switch could not work. These assertions are what make the claim
 * checkable rather than aspirational.
 */
class ReportsDiskConfigTest extends TestCase
{
    /**
     * Every key the s3 driver reads when it builds a client.
     *
     * @var array<int, string>
     */
    private const array S3_KEYS = [
        'key',
        'secret',
        'region',
        'bucket',
        'endpoint',
        'use_path_style_endpoint',
    ];

    public function test_the_reports_disk_declares_every_key_the_s3_driver_requires(): void
    {
        $disk = $this->reportsDisk();

        foreach (self::S3_KEYS as $key) {
            $this->assertArrayHasKey(
                $key,
                $disk,
                "The reports disk is missing '{$key}', so REPORT_DISK_DRIVER=s3 cannot resolve a usable client.",
            );
        }
    }

    public function test_the_reports_disk_defaults_to_the_local_driver_rooted_in_storage(): void
    {
        $disk = $this->reportsDisk();

        $this->assertSame('local', $disk['driver']);
        $this->assertSame(storage_path('app/reports'), $disk['root']);
    }

    public function test_the_reports_disk_throws_rather_than_silently_losing_a_workbook(): void
    {
        $this->assertTrue(
            $this->reportsDisk()['throw'],
            'A failed write must surface: a run marked succeeded with no readable file is worse than a failed run.',
        );
    }

    /**
     * The resolved reports disk configuration.
     *
     * @return array<string, mixed>
     */
    private function reportsDisk(): array
    {
        $disk = config('filesystems.disks.reports');

        $this->assertIsArray($disk);

        return $disk;
    }
}
