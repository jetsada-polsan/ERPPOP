<?php

namespace App\Services\Sales;

use App\Models\Document;
use App\Models\Member;
use App\Models\MemberPointRule;
use App\Models\MemberPointTransaction;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Converts POS bills to member points (แต้มทอง) and points back to baht
 * discounts (แลกแต้มเป็นส่วนลด). Earned points = floor(amount / baht_per_point)
 * × the highest active multiplier campaign (แต้มทวีคูณ), if any.
 */
class MemberPointService
{
    public function activeEarnRule(): ?MemberPointRule
    {
        return MemberPointRule::runningToday()
            ->where('rule_type', 'earn')
            ->orderByDesc('id')
            ->first();
    }

    public function currentMultiplier(): float
    {
        $multiplier = MemberPointRule::runningToday()
            ->where('rule_type', 'multiplier')
            ->max('multiplier');

        return $multiplier ? max(1.0, (float) $multiplier) : 1.0;
    }

    public function pointsForAmount(float $amount): float
    {
        $rule = $this->activeEarnRule();
        if (! $rule || (float) $rule->baht_per_point <= 0) {
            return 0.0;
        }

        return floor($amount / (float) $rule->baht_per_point) * $this->currentMultiplier();
    }

    // Baht value of 1 point when redeeming; 0 disables redemption.
    public function pointValueBaht(): float
    {
        return (float) ($this->activeEarnRule()?->point_value_baht ?? 0);
    }

    /**
     * Deduct redeemed points and add earned points for a posted sale, with
     * a ledger row per movement. Returns the points earned.
     */
    public function settle(Member $member, Document $document, float $redeemPoints): float
    {
        return DB::transaction(function () use ($member, $document, $redeemPoints) {
            $member = Member::whereKey($member->getKey())->lockForUpdate()->first();
            if (! $member) {
                throw new RuntimeException('ไม่พบสมาชิก กรุณาเลือกสมาชิกใหม่');
            }

            if ($redeemPoints > 0) {
                if ((float) $member->points + 0.0001 < $redeemPoints) {
                    throw new RuntimeException('แต้มสะสมไม่พอ กรุณาตรวจสอบยอดแต้มอีกครั้ง');
                }

                $member->decrement('points', $redeemPoints);
                MemberPointTransaction::create([
                    'member_id' => $member->id,
                    'document_id' => $document->id,
                    'direction' => 'redeem',
                    'points' => $redeemPoints,
                    'balance_after' => (float) $member->fresh()->points,
                    'note' => 'แลกแต้มเป็นส่วนลดบิล ' . $document->doc_number,
                ]);
            }

            $earned = $this->pointsForAmount((float) $document->total_amount);
            if ($earned > 0) {
                $member->increment('points', $earned);
                MemberPointTransaction::create([
                    'member_id' => $member->id,
                    'document_id' => $document->id,
                    'direction' => 'earn',
                    'points' => $earned,
                    'balance_after' => (float) $member->fresh()->points,
                    'note' => 'สะสมแต้มจากบิล ' . $document->doc_number,
                ]);
            }

            return $earned;
        });
    }
}
