<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_guests_can_open_the_public_portal(): void
    {
        $response = $this->get('/');

        $response->assertOk()->assertSee('ศูนย์รวมระบบงาน');
    }
}
