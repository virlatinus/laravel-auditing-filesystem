<?php

namespace VirLatinus\Auditing\Drivers\Tests\Local;

use VirLatinus\Auditing\Drivers\FilesystemDriver;
use VirLatinus\Auditing\Drivers\Tests\Models\Article;
use Carbon\Carbon;
use Orchestra\Testbench\TestCase;

class FilesystemDriverTest extends TestCase
{
    private const FS_DIR = 'audit/sub';

    protected function getEnvironmentSetUp($app): void
    {
        $app->config->set('filesystems.disks', [
            'local' => [
                'driver' => 'local',
                'root' => env('LOCAL_STORAGE_PATH', storage_path('app')),
            ]
        ]);

        $app->config->set('audit.drivers.filesystem.dir', self::FS_DIR);
    }

    protected function useDailyRotation($app): void
    {
        $app->config->set('audit.drivers.filesystem.rotation', 'daily');
    }

    protected function useHourlyRotation($app): void
    {
        $app->config->set('audit.drivers.filesystem.rotation', 'hourly');
    }

    protected function useUnsupportedRotation($app): void
    {
        $app->config->set('audit.drivers.filesystem.rotation', 'unsupported');
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach (glob(storage_path(sprintf('app/%s/*', self::FS_DIR))) as $auditFile) {
            @unlink($auditFile);
        }

        @rmdir(storage_path(sprintf('app/%s', self::FS_DIR)));
    }

    public function testAuditSingleUnbuffered(): void
    {
        $auditable = new Article();

        $driver = app(FilesystemDriver::class);

        $driver->audit($auditable);

        $auditFile = storage_path(sprintf('app/%s/audit.csv', self::FS_DIR));

        $this->assertFileExists($auditFile);

        $contents = file($auditFile);

        // Header and first audit
        $this->assertCount(2, $contents);

        $driver->audit($auditable);
        $contents = file($auditFile);

        // Appends the data
        $this->assertCount(3, $contents);
    }

    public function testAuditSingleBuffered(): void
    {
        $auditable = new Article();

        $driver = app(FilesystemDriver::class);
        $driver->bufferStart();

        $driver->audit($auditable);

        $auditFile = storage_path(sprintf('app/%s/audit.csv', self::FS_DIR));

        $this->assertFalse(file_exists($auditFile));

        for ($i = 0; $i < 255; ++$i) {
            $driver->audit($auditable);
            $this->assertFalse(file_exists($auditFile));
        }

        $this->assertFalse(file_exists($auditFile));

        $driver->bufferFlush();

        $this->assertFileExists($auditFile);
        $contents = file($auditFile);

        $this->assertCount(257, $contents);
    }

    /**
     * @environment-setup useDailyRotation
     */
    public function testAuditDailyUnbuffered(): void
    {
        $auditable = new Article();

        $driver = app(FilesystemDriver::class);

        $driver->audit($auditable);

        $format = (new \DateTime('now'))->format('Y-m-d');
        $auditFile = storage_path(sprintf('app/%s/audit-%s.csv', self::FS_DIR, $format));
        $this->assertFileExists($auditFile);

        $contents = file($auditFile);

        // Header and first audit
        $this->assertCount(2, $contents);

        $driver->audit($auditable);
        $contents = file($auditFile);

        // Appends the data
        $this->assertCount(3, $contents);
    }

    /**
     * @environment-setup useHourlyRotation
     */
    public function testAuditHourlyUnbuffered(): void
    {
        $auditable = new Article();

        $driver = app(FilesystemDriver::class);

        $driver->audit($auditable);

        $format = (new \DateTime('now'))->format('Y-m-d-H');
        $auditFile = storage_path(sprintf('app/%s/audit-%s-00-00.csv', self::FS_DIR, $format));
        $this->assertFileExists($auditFile);

        $contents = file($auditFile);

        // Header and first audit
        $this->assertCount(2, $contents);

        $driver->audit($auditable);
        $contents = file($auditFile);

        // Appends the data
        $this->assertCount(3, $contents);
    }

    /**
     * @environment-setup useUnsupportedRotation
     */
    public function testAuditUnsupported(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(FilesystemDriver::class);
    }

    public function testPrune(): void
    {
        $auditable = new Article();

        $driver = app(FilesystemDriver::class);

        $result = $driver->prune($auditable);

        $this->assertFalse($result);
    }
}
