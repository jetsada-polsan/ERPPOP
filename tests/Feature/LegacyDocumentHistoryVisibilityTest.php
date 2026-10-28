<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class LegacyDocumentHistoryVisibilityTest extends TestCase
{
    public function test_legacy_bplus_document_history_is_no_longer_exposed(): void
    {
        $this->assertFalse(Route::has('documents.legacy-show'));
        $this->assertFalse(Route::has('legacy-reports.index'));
        $this->assertFalse(Route::has('bookings.legacy-show'));
        $this->assertFalse(Route::has('legacy-mappings.index'));
        $this->assertFalse(Route::has('legacy-backoffice-sales.index'));

        $this->get('/documents/legacy/2474118')->assertNotFound();
        $this->get('/legacy-reports')->assertNotFound();
        $this->get('/bookings/legacy/2474118')->assertNotFound();
        $this->get('/legacy-mappings')->assertNotFound();
        $this->get('/legacy-backoffice-sales')->assertNotFound();
        $this->postJson('/api/legacy-backoffice/summary', [])->assertNotFound();
    }
}
