<?php

namespace App\Support;

use App\Models\AppSetting;

final class PosReceiptTemplate
{
    public const SETTING_KEY = 'pos_receipt_template';

    private const TYPES = [
        'logo', 'company', 'title', 'meta', 'divider', 'items',
        'tax-summary', 'totals', 'payment', 'custom', 'footer',
    ];

    private const REQUIRED_TYPES = ['company', 'title', 'meta', 'items', 'totals'];

    private const REPEATABLE_TYPES = ['divider', 'custom'];

    public static function defaults(): array
    {
        return [
            'paper_width' => 80,
            'blocks' => [
                self::block('logo', 'center', 'medium', false),
                self::block('company', 'center', 'small', false),
                self::block('title', 'center', 'medium', true),
                self::block('meta', 'left', 'small', false),
                self::block('divider', 'left', 'small', false),
                self::block('items', 'left', 'small', false, ['show_sku' => false, 'show_unit_price' => true]),
                self::block('tax-summary', 'left', 'small', false),
                self::block('totals', 'left', 'large', true),
                self::block('payment', 'left', 'small', false),
                self::block('footer', 'center', 'small', false, ['text' => 'ขอบคุณที่ใช้บริการ']),
            ],
        ];
    }

    public static function get(): array
    {
        $saved = json_decode((string) AppSetting::get(self::SETTING_KEY, ''), true);

        return is_array($saved) ? self::sanitize($saved) : self::defaults();
    }

    public static function sanitize(array $template): array
    {
        $requestedPaperWidth = (int) ($template['paper_width'] ?? 80);
        $paperWidth = in_array($requestedPaperWidth, [58, 80], true)
            ? $requestedPaperWidth
            : 80;
        $blocks = [];
        $fixedTypes = [];
        $usedIds = [];

        foreach (array_slice((array) ($template['blocks'] ?? []), 0, 24) as $index => $input) {
            if (! is_array($input) || ! in_array($input['type'] ?? null, self::TYPES, true)) {
                continue;
            }

            $type = $input['type'];
            if (! in_array($type, self::REPEATABLE_TYPES, true) && isset($fixedTypes[$type])) {
                continue;
            }

            $id = self::safeId((string) ($input['id'] ?? ''), $type, $index);
            if (isset($usedIds[$id])) {
                $id .= '-'.$index;
            }
            $usedIds[$id] = true;

            $blocks[] = self::block(
                $type,
                in_array($input['align'] ?? null, ['left', 'center', 'right'], true) ? $input['align'] : 'left',
                in_array($input['size'] ?? null, ['small', 'medium', 'large'], true) ? $input['size'] : 'small',
                filter_var($input['bold'] ?? false, FILTER_VALIDATE_BOOLEAN),
                [
                    'id' => $id,
                    'text' => mb_substr(trim((string) ($input['text'] ?? '')), 0, 160),
                    'show_sku' => filter_var($input['show_sku'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'show_unit_price' => filter_var($input['show_unit_price'] ?? true, FILTER_VALIDATE_BOOLEAN),
                ],
            );
            if (! in_array($type, self::REPEATABLE_TYPES, true)) {
                $fixedTypes[$type] = true;
            }
        }

        $defaults = collect(self::defaults()['blocks'])->keyBy('type');
        foreach (self::REQUIRED_TYPES as $requiredType) {
            if (! collect($blocks)->contains('type', $requiredType)) {
                $block = $defaults[$requiredType];
                if (isset($usedIds[$block['id']])) {
                    $block['id'] = 'required-'.$requiredType;
                }
                $usedIds[$block['id']] = true;
                $blocks[] = $block;
            }
        }

        return ['paper_width' => $paperWidth, 'blocks' => array_values($blocks)];
    }

    public static function isRequired(string $type): bool
    {
        return in_array($type, self::REQUIRED_TYPES, true);
    }

    private static function block(
        string $type,
        string $align,
        string $size,
        bool $bold,
        array $extra = [],
    ): array {
        return array_merge([
            'id' => $type,
            'type' => $type,
            'align' => $align,
            'size' => $size,
            'bold' => $bold,
        ], $extra);
    }

    private static function safeId(string $id, string $type, int $index): string
    {
        $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $id) ?: '';

        return mb_substr($id !== '' ? $id : $type.'-'.$index, 0, 60);
    }
}
