<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PosReleaseTest extends TestCase
{
    private string $directory;

    private ?string $originalManifest = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = storage_path('app/pos-releases');
        File::ensureDirectoryExists($this->directory);
        $manifest = $this->directory.'/latest.json';
        $this->originalManifest = is_file($manifest) ? file_get_contents($manifest) : null;
    }

    protected function tearDown(): void
    {
        File::delete($this->directory.'/POPSTAR-POS-test.zip');
        if ($this->originalManifest === null) {
            File::delete($this->directory.'/latest.json');
        } else {
            File::put($this->directory.'/latest.json', $this->originalManifest);
        }
        parent::tearDown();
    }

    public function test_latest_manifest_is_public_and_not_cached(): void
    {
        File::put($this->directory.'/latest.json', json_encode([
            'version' => '1.2.3',
            'platforms' => ['windows-x86_64' => ['signature' => 'signed', 'url' => 'https://example.test/pos.zip']],
        ]));

        $response = $this->get('/download/pos/latest.json')
            ->assertOk()
            ->assertJsonPath('version', '1.2.3');

        $this->assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
    }

    public function test_release_file_can_be_downloaded(): void
    {
        File::put($this->directory.'/POPSTAR-POS-test.zip', 'signed updater');

        $this->get('/download/pos/releases/POPSTAR-POS-test.zip')
            ->assertDownload('POPSTAR-POS-test.zip');
    }
}
