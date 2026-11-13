<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The PDF reports (customers, stock, GPS, transfers...) and the dealer notification icon load images from
 * public/images. A missing file does not crash anything: the logo just silently disappears. These tests make
 * that visible.
 */
class BrandAssetsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    public function test_every_image_the_code_loads_from_public_images_exists(): void
    {
        $missing = [];
        $found = 0;
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root . '/app', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            preg_match_all("/public_path\\('(images\\/[^']+)'\\)/", file_get_contents($file->getPathname()), $m);
            foreach ($m[1] as $path) {
                $found++;
                if (! is_file($this->root . '/public/' . $path)) {
                    $missing[] = $path . ' (used in ' . basename($file->getPathname()) . ')';
                }
            }
        }

        $this->assertGreaterThan(0, $found, 'the scan must find the report logo references');
        $this->assertSame([], $missing, 'these images are loaded by the code but are not in public/images');
    }

    public function test_the_report_logo_is_a_real_small_png(): void
    {
        $path = $this->root . '/public/images/logo.png';

        $this->assertFileExists($path);
        $info = getimagesize($path);
        $this->assertSame(IMAGETYPE_PNG, $info[2], 'dompdf cannot draw WebP, so the report logo must be a PNG');
        $this->assertLessThanOrEqual(80 * 1024, filesize($path), 'it is embedded in every PDF as base64, so it must stay small');
        $this->assertGreaterThanOrEqual(128, $info[0]);
    }

    public function test_the_dealer_notification_icon_exists(): void
    {
        $this->assertFileExists($this->root . '/public/images/logo.png', 'resources/views/dealer/dashboard.blade.php uses /images/logo.png as the push icon');
    }
}