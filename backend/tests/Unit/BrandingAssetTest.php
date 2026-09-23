<?php

namespace Tests\Unit;

use App\Helpers\BrandingAsset;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BrandingAssetTest extends TestCase
{
    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_resolves_file_that_exists_only_under_public_path(): void
    {
        $name = 'branding_asset_legacy_'.uniqid().'.png';
        $legacy = public_path($name);
        file_put_contents($legacy, 'legacy-only');
        $this->cleanup[] = $legacy;

        $this->assertSame($legacy, BrandingAsset::resolve($name));
    }

    public function test_public_disk_is_preferred_over_public_path(): void
    {
        $name = 'branding_asset_pref_'.uniqid().'.png';
        $legacy = public_path($name);
        file_put_contents($legacy, 'legacy');
        $this->cleanup[] = $legacy;

        Storage::disk('public')->put($name, 'from-disk');
        $diskPath = Storage::disk('public')->path($name);
        $this->cleanup[] = $diskPath;

        $this->assertSame($diskPath, BrandingAsset::resolve($name));
    }

    public function test_basename_strips_directory_traversal(): void
    {
        $name = 'evil.png';
        $legacy = public_path($name);
        file_put_contents($legacy, 'x');
        $this->cleanup[] = $legacy;

        $this->assertSame($legacy, BrandingAsset::resolve('../evil.png'));
    }

    public function test_store_uploaded_writes_to_public_disk_not_public_path(): void
    {
        $name = 'system_logo_test_'.uniqid().'.png';
        $tmp = tempnam(sys_get_temp_dir(), 'brand');
        file_put_contents($tmp, 'png-bytes');
        $this->cleanup[] = $tmp;

        $upload = new UploadedFile($tmp, 'logo.png', 'image/png', UPLOAD_ERR_OK, true);
        BrandingAsset::storeUploaded($upload, $name);

        $diskPath = Storage::disk('public')->path($name);
        $this->cleanup[] = $diskPath;

        $this->assertFileExists($diskPath);
        $this->assertFileDoesNotExist(public_path($name));
    }
}
