<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LineWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_signature_is_rejected_before_processing(): void
    {
        config(['services.line.channel_secret' => 'test-secret']);
        $body = json_encode(['events' => []], JSON_THROW_ON_ERROR);

        $this->call('POST', '/api/line/webhook', [], [], [], ['HTTP_X_LINE_SIGNATURE' => 'bad'], $body)
            ->assertUnauthorized();
    }

    public function test_valid_signature_is_accepted(): void
    {
        config(['services.line.channel_secret' => 'test-secret']);
        $body = json_encode(['events' => []], JSON_THROW_ON_ERROR);
        $signature = base64_encode(hash_hmac('sha256', $body, 'test-secret', true));

        $this->call('POST', '/api/line/webhook', [], [], [], ['HTTP_X_LINE_SIGNATURE' => $signature], $body)
            ->assertOk()->assertJson(['success' => true]);
    }
}
