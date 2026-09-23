<?php

namespace App\Http\Controllers;

use App\Models\MemberLineAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class LineWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $body = $request->getContent();
        $signature = (string) $request->header('x-line-signature', '');
        $secret = (string) config('services.line.channel_secret');
        $expected = base64_encode(hash_hmac('sha256', $body, $secret, true));
        if ($secret === '' || $signature === '' || ! hash_equals($expected, $signature)) {
            return response()->json(['success' => false, 'message' => 'Invalid LINE signature'], 401);
        }

        foreach ((json_decode($body, true, 512, JSON_THROW_ON_ERROR)['events'] ?? []) as $event) {
            if (($event['type'] ?? null) !== 'message' || ($event['message']['type'] ?? null) !== 'text') {
                continue;
            }
            $lineUserId = $event['source']['userId'] ?? null;
            if (! $lineUserId) {
                continue;
            }
            $account = MemberLineAccount::with('member')
                ->where('line_user_id', $lineUserId)->where('is_active', true)->first();
            if ($account && str_contains(mb_strtolower((string) ($event['message']['text'] ?? '')), 'แต้ม')) {
                $this->reply($event['replyToken'] ?? null, 'แต้มคงเหลือของคุณ: '.number_format((float) $account->member->points, 2).' แต้ม');
            }
        }

        return response()->json(['success' => true]);
    }

    private function reply(?string $replyToken, string $text): void
    {
        if (! $replyToken || ! config('services.line.channel_access_token')) {
            return;
        }
        Http::withToken(config('services.line.channel_access_token'))->timeout(10)
            ->post('https://api.line.me/v2/bot/message/reply', [
                'replyToken' => $replyToken,
                'messages' => [['type' => 'text', 'text' => $text]],
            ]);
    }
}
