<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PosReleaseTest extends TestCase
{
    private string $directory;

    private string $pythonDirectory;

    private string $jetPythonDirectory;

    private ?string $originalManifest = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = storage_path('app/pos-releases');
        $this->pythonDirectory = storage_path('app/pos-python-releases');
        $this->jetPythonDirectory = storage_path('app/jet-pos-python-releases');
        File::ensureDirectoryExists($this->directory);
        File::ensureDirectoryExists($this->pythonDirectory);
        File::ensureDirectoryExists($this->jetPythonDirectory);
        $manifest = $this->directory.'/latest.json';
        $this->originalManifest = is_file($manifest) ? file_get_contents($manifest) : null;
    }

    protected function tearDown(): void
    {
        File::delete($this->directory.'/POPSTAR-POS-test.zip');
        File::delete($this->directory.'/PopCentral-POS-UAT-99.98.97-setup.exe');
        File::delete($this->pythonDirectory.'/PopCentral-POS-UAT-99.98.98-setup.exe');
        File::delete($this->jetPythonDirectory.'/JET-POS-Python-UAT-99.98.99.exe');
        if ($this->originalManifest === null) {
            File::delete($this->directory.'/latest.json');
        } else {
            File::put($this->directory.'/latest.json', $this->originalManifest);
        }
        parent::tearDown();
    }

    public function test_python_download_prefers_the_highest_version_across_legacy_folders(): void
    {
        File::put($this->directory.'/PopCentral-POS-UAT-99.98.97-setup.exe', 'legacy');
        File::put($this->pythonDirectory.'/PopCentral-POS-UAT-99.98.98-setup.exe', 'new');

        $this->get('/download/python-pos')
            ->assertOk()
            ->assertDownload('PopCentral-POS-UAT-99.98.98-setup.exe');
    }

    // POS ตัวเดียวคือ JET POS Python (apps/pos-python) — ลิงก์ UAT เดิมพาไปตัวนี้
    public function test_old_jet_python_uat_link_sends_people_to_the_current_python_pos(): void
    {
        File::put($this->jetPythonDirectory.'/JET-POS-Python-UAT-99.98.99.exe', 'jet python uat');

        $this->get('/download/jet-pos-python')->assertRedirect(route('python-pos.download'));
        $this->get('/download/pos')->assertRedirect(route('python-pos.download'));
    }

    // POS Vue/Tauri เลิกใช้: ไม่ประกาศรุ่นอัปเดตให้เครื่องเดิมอีก
    public function test_retired_vue_tauri_update_manifest_is_gone(): void
    {
        File::put($this->directory.'/latest.json', json_encode([
            'version' => '1.2.3',
            'platforms' => ['windows-x86_64' => ['signature' => 'signed', 'url' => 'https://example.test/pos.zip']],
        ]));

        $this->get('/download/pos/latest.json')->assertStatus(410);
    }

    public function test_retired_vue_tauri_release_files_are_not_served(): void
    {
        File::put($this->directory.'/POPSTAR-POS-test.zip', 'signed updater');

        $this->get('/download/pos/releases/POPSTAR-POS-test.zip')->assertStatus(410);
    }
}
