<?php

namespace App\Http\Controllers;

use App\Models\MemberLineAccount;
use App\Models\PosReceipt;
use Carbon\Carbon;
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

        $payload = json_decode($body, true);
        foreach (($payload['events'] ?? []) as $event) {
            if (($event['type'] ?? null) !== 'message' || ($event['message']['type'] ?? null) !== 'text') {
                continue;
            }
            $lineUserId = $event['source']['userId'] ?? null;
            if (! $lineUserId) {
                continue;
            }
            $account = MemberLineAccount::with('member')
                ->where('line_user_id', $lineUserId)->where('is_active', true)->first();
            $text = trim((string) ($event['message']['text'] ?? ''));
            $phone = preg_replace('/\D+/', '', $text);
            if (! $account && preg_match('/^0\d{9}$/', $phone)) {
                $member = \App\Models\Member::where('phone_normalized', $phone)->where('is_active', true)->first();
                if ($member) {
                    $existingLink = MemberLineAccount::with('member')
                        ->where('member_id', $member->id)
                        ->where('is_active', true)
                        ->where('line_user_id', '!=', $lineUserId)
                        ->first();
                    if ($existingLink) {
                        $this->reply($event['replyToken'] ?? null, "⚠️ เบอร์นี้ผูกกับบัญชี LINE อื่นอยู่แล้ว\nกรุณาติดต่อเจ้าหน้าที่เพื่อยืนยันและเปลี่ยนบัญชีครับ");
                        continue;
                    }
                    MemberLineAccount::updateOrCreate(
                        ['line_user_id' => $lineUserId],
                        ['member_id' => $member->id, 'linked_at' => now(), 'unlinked_at' => null, 'is_active' => true],
                    );
                    $account = MemberLineAccount::with('member')->where('line_user_id', $lineUserId)->first();
                    $this->reply($event['replyToken'] ?? null, '🎉🔗 ผูกสมาชิกสำเร็จแล้วครับ\nพิมพ์ \'แต้ม\' เพื่อเช็กคะแนนสะสม ⭐');
                    continue;
                }
            }
            if (! $account) {
                if (str_contains(mb_strtolower($text), 'ผูกสมาชิก')) {
                    $this->reply($event['replyToken'] ?? null, "🔗⭐ วิธีผูกสมาชิก\nกรุณาพิมพ์เบอร์โทรศัพท์สมาชิก 10 หลัก เช่น 0967281037\nระบบจะตรวจสอบและผูกบัญชี LINE ให้โดยอัตโนมัติครับ");
                    continue;
                }
                $this->reply($event['replyToken'] ?? null, "🌟 สวัสดีครับ PopstarCenter Member\nกรุณาผูกสมาชิกกับเบอร์โทรศัพท์ก่อนใช้งานนะครับ\nพิมพ์ 'แต้ม' เพื่อเช็กคะแนนหลังผูกสมาชิกแล้ว ⭐");
                continue;
            }

            if (str_contains(mb_strtolower($text), 'แต้ม')) {
                $this->reply($event['replyToken'] ?? null, '⭐ แต้มคงเหลือของคุณ: '.number_format((float) $account->member->points, 2).' แต้ม 🎉');
                continue;
            }

            if (str_contains(mb_strtolower($text), 'ผูกสมาชิก')) {
                $this->reply($event['replyToken'] ?? null, "🔗✅ บัญชี LINE นี้ผูกกับสมาชิกแล้วครับ\nสมาชิก: {$account->member->name}\nหากต้องการเปลี่ยนสมาชิก กรุณาติดต่อเจ้าหน้าที่ 💬");
                continue;
            }

            if (str_contains(mb_strtolower($text), 'วิธีใช้')) {
                $this->reply($event['replyToken'] ?? null, "📖✨ วิธีใช้งาน PopstarCenter Member\n1. ⭐ กด 'เช็กแต้ม' เพื่อดูแต้มคงเหลือ\n2. 🛍️ กด 'ยอดซื้อสะสม' เพื่อดูยอดซื้อของเดือนนี้\n3. 🔗 ใช้ 'ผูกสมาชิก' เพื่อเชื่อมบัญชี LINE กับเบอร์สมาชิก\n4. 💬 กด 'ติดต่อเรา' หากต้องการสอบถามเจ้าหน้าที่");
                continue;
            }

            if (str_contains(mb_strtolower($text), 'ติดต่อ')) {
                $this->reply($event['replyToken'] ?? null, "💬📞 ติดต่อ PopstarCenter\nกรุณาพิมพ์รายละเอียดที่ต้องการสอบถามได้เลยครับ\nเจ้าหน้าที่จะตรวจสอบและติดต่อกลับในเวลาทำการ 😊");
                continue;
            }

            if (str_contains(mb_strtolower($text), 'โปรโมชั่น')) {
                $this->replyMessages($event['replyToken'] ?? null, [
                    [
                        'type' => 'image',
                        'originalContentUrl' => 'https://erp.popstarcenter.com/images/line-promotion-monthly.png',
                        'previewImageUrl' => 'https://erp.popstarcenter.com/images/line-promotion-monthly.png',
                    ],
                    [
                        'type' => 'text',
                        'text' => "🎁🐔 โปรโมชั่นเดือนนี้\nน่องไก่ลดพิเศษสำหรับสมาชิก PopstarCenter ⭐\nสอบถามราคาและเงื่อนไขได้ที่สาขาหรือพิมพ์ 'ติดต่อเรา' 💬",
                    ],
                ]);
                continue;
            }

            if (preg_match('/ยอดซื้อ\s+(\d{4})-(\d{2})/u', $text, $monthMatch)) {
                $month = Carbon::createFromFormat('Y-m', $monthMatch[1].'-'.$monthMatch[2])->startOfMonth();
                $monthStart = $month->copy()->startOfMonth();
                $monthEnd = $month->copy()->endOfMonth();
                $purchaseTotal = (float) PosReceipt::query()
                    ->where('member_id', $account->member_id)
                    ->where('status', 'completed')
                    ->whereBetween('receipt_date', [$monthStart, $monthEnd])
                    ->sum('net_sales');

                $this->reply(
                    $event['replyToken'] ?? null,
                    '🛍️ ยอดซื้อสะสมเดือน '.$this->thaiMonth($month).' '.$month->year.': ฿'.number_format($purchaseTotal, 2)."\n⭐ แต้มคงเหลือ: ".number_format((float) $account->member->points, 2).' แต้ม',
                );
                continue;
            }

            if (str_contains(mb_strtolower($text), 'ยอดซื้อ')) {
                $quickItems = [];
                for ($i = 0; $i < 6; $i++) {
                    $month = now()->startOfMonth()->subMonths($i);
                    $quickItems[] = [
                        'type' => 'action',
                        'action' => [
                            'type' => 'message',
                            'label' => $this->thaiMonth($month),
                            'text' => 'ยอดซื้อ '.$month->format('Y-m'),
                        ],
                    ];
                }
                $this->replyMessages($event['replyToken'] ?? null, [[
                    'type' => 'text',
                    'text' => '🗓️🛍️ กรุณาเลือกเดือนที่ต้องการดูยอดซื้อสะสม ⭐',
                    'quickReply' => ['items' => $quickItems],
                ]]);
                continue;
            }

            $this->reply($event['replyToken'] ?? null, "🌟 รับข้อความแล้วครับ\nเลือกเมนูด้านล่าง หรือพิมพ์ 'แต้ม' เพื่อเช็กคะแนนสะสม ⭐");
        }

        return response()->json(['success' => true]);
    }

    private function reply(?string $replyToken, string $text): void
    {
        $this->replyMessages($replyToken, [['type' => 'text', 'text' => $text]]);
    }

    private function replyMessages(?string $replyToken, array $messages): void
    {
        if (! $replyToken || ! config('services.line.channel_access_token')) {
            return;
        }
        Http::withToken(config('services.line.channel_access_token'))->timeout(10)
            ->post('https://api.line.me/v2/bot/message/reply', [
                'replyToken' => $replyToken,
                'messages' => $messages,
            ]);
    }

    private function thaiMonth(Carbon $month): string
    {
        return ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'][$month->month - 1];
    }
}
