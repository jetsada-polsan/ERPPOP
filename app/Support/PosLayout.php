<?php

namespace App\Support;

use App\Models\AppSetting;
use Illuminate\Validation\Rule;

/**
 * แหล่งความจริงเดียวของ "POS layout" ทั้งระบบ
 *
 * เดิมค่า default ถูกคัดลอกไว้สามที่ (SystemSettingController, PosController,
 * Api\PosApiController) แล้วแต่ละที่ clamp ไม่เหมือนกัน หน้า Designer จึงบันทึกค่าหนึ่ง
 * แต่หน้าขายอ่านได้อีกค่าหนึ่ง คลาสนี้รวมไว้ที่เดียว และแยกสองเรื่องออกจากกันชัดๆ
 *
 *  - components: ตำแหน่งบล็อกบน canvas 12 ช่อง (ของเดิม ใช้กับ preview และ POS Python)
 *  - runtime:    ค่าที่ "ปลอดภัยต่อการรันจริง" ซึ่งหน้าขายเอาไปทำ CSS ได้ตรงๆ
 *                โดยไม่ต้องตีความ canvas — สัดส่วนซ้าย/ขวา, จำนวนแถว/คอลัมน์สินค้า,
 *                ความหนาแน่น, ขนาดปุ่ม และการซ่อน/แสดงชิปบนแถบบน
 *
 * ทุกเมธอดที่อ่านค่าจากฐานข้อมูลต้อง "ไม่โยน exception" เพราะค่าที่ publish ไว้แล้ว
 * ต้องไม่ทำให้หน้าขายล่ม ส่วนการตรวจค่าที่ผู้ใช้เพิ่งกรอกให้ใช้ runtimeRules()
 * ในคอนโทรลเลอร์ เพื่อให้ผู้ใช้เห็นข้อความผิดพลาดแทนการถูก clamp เงียบๆ
 */
final class PosLayout
{
    public const SCHEMA = 'popcentral-pos-layout';

    public const COMPONENT_TYPES = [
        'search', 'category_tabs', 'product_grid', 'cart', 'payment',
        'customer', 'held_bills', 'numpad', 'shift_status',
    ];

    public const DENSITIES = ['compact', 'comfortable', 'roomy'];

    public const BUTTON_SIZES = ['small', 'medium', 'large'];

    /** ชิปบนแถบบนของหน้าขายที่ซ่อนได้ */
    public const TOGGLES = ['show_branch', 'show_terminal', 'show_seller', 'show_shift'];

    public const CANVAS_COLUMNS = 12;

    public const CANVAS_ROWS = 12;

    public const MIN_PANE_WIDTH = 30;

    public const MAX_PANE_WIDTH = 70;

    public const MIN_PRODUCT_ROWS = 1;

    public const MAX_PRODUCT_ROWS = 8;

    public const MIN_PRODUCT_COLUMNS = 2;

    public const MAX_PRODUCT_COLUMNS = 8;

    /** ความหนาแน่น -> ระยะห่าง/ขนาดการ์ดสินค้า (px) */
    private const DENSITY_METRICS = [
        'compact' => ['gap' => 6, 'padding' => 7, 'card' => 104, 'font' => 12],
        'comfortable' => ['gap' => 8, 'padding' => 10, 'card' => 124, 'font' => 13],
        'roomy' => ['gap' => 12, 'padding' => 14, 'card' => 148, 'font' => 15],
    ];

    /** ขนาดปุ่ม -> ความสูง/ฟอนต์/padding (px) */
    private const BUTTON_METRICS = [
        'small' => ['height' => 36, 'font' => 13, 'padding' => 7],
        'medium' => ['height' => 42, 'font' => 16, 'padding' => 9],
        'large' => ['height' => 54, 'font' => 19, 'padding' => 12],
    ];

    /** ค่า runtime เริ่มต้น = บิลเด่น สินค้ากะทัดรัด (45/55, 3 แถว) */
    public static function defaultRuntime(): array
    {
        return [
            'product_width' => 45,
            'cart_width' => 55,
            'product_rows' => 3,
            'product_columns' => 4,
            'density' => 'compact',
            'button_size' => 'small',
            'show_branch' => true,
            'show_terminal' => true,
            'show_seller' => true,
            'show_shift' => true,
        ];
    }

    public static function defaultComponents(): array
    {
        return [
            ['id' => 'search', 'type' => 'search', 'x' => 1, 'y' => 1, 'w' => 7, 'h' => 1],
            ['id' => 'category', 'type' => 'category_tabs', 'x' => 1, 'y' => 2, 'w' => 7, 'h' => 1],
            ['id' => 'products', 'type' => 'product_grid', 'x' => 1, 'y' => 3, 'w' => 7, 'h' => 5],
            ['id' => 'cart', 'type' => 'cart', 'x' => 8, 'y' => 1, 'w' => 5, 'h' => 5],
            ['id' => 'payment', 'type' => 'payment', 'x' => 8, 'y' => 6, 'w' => 5, 'h' => 2],
        ];
    }

    public static function defaults(): array
    {
        return [
            'schema' => self::SCHEMA,
            'version' => 1,
            'layout_version' => 1,
            'canvas' => ['columns' => self::CANVAS_COLUMNS, 'rows' => self::CANVAS_ROWS],
            'components' => self::defaultComponents(),
            'runtime' => self::defaultRuntime(),
        ];
    }

    /** layout ที่ publish แล้ว — ตัวที่หน้าขายและเครื่อง POS ต้องใช้ */
    public static function published(): array
    {
        $layout = self::decode(self::setting('pos_layout_published'));
        $version = (int) self::setting('pos_layout_version');

        return self::normalize($layout, $version > 0 ? $version : null);
    }

    /** แบบร่างที่ยังไม่ publish — ถ้าไม่มีให้ถอยไปใช้ตัวที่ publish แล้ว */
    public static function draft(): array
    {
        $draft = self::decode(self::setting('pos_layout_draft'));

        return $draft === null ? self::published() : self::normalize($draft);
    }

    public static function publishedVersion(): int
    {
        return (int) self::setting('pos_layout_version');
    }

    public static function publishedAt(): ?string
    {
        return self::setting('pos_layout_published_at');
    }

    /**
     * หน้าขายบนเบราว์เซอร์ต้องขึ้นได้แม้ฐานข้อมูลล่ม เพราะเป็นหน้าจอเดียวที่แคชเชียร์เห็น
     * ตอนระบบมีปัญหา — layout เป็นแค่เรื่องหน้าตา จึงถอยไปใช้ค่าเริ่มต้นดีกว่าโยน 500
     */
    private static function setting(string $key): ?string
    {
        try {
            return AppSetting::get($key);
        } catch (\Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * ทำให้ค่าที่อ่านมาใช้งานได้เสมอ ไม่ว่าจะเขียนมาจากรุ่นไหน
     * ไม่มีทางโยน exception เพราะถูกเรียกตอน render หน้าขาย
     */
    public static function normalize(mixed $value, ?int $layoutVersion = null): array
    {
        $value = is_array($value) ? $value : [];
        $components = self::componentsFrom($value['components'] ?? null);
        if ($components === []) {
            $components = self::defaultComponents();
        }

        $version = max(1, (int) ($value['version'] ?? 1));

        return [
            'schema' => self::SCHEMA,
            'version' => $version,
            'layout_version' => $layoutVersion ?? $version,
            'canvas' => ['columns' => self::CANVAS_COLUMNS, 'rows' => self::CANVAS_ROWS],
            'components' => $components,
            'runtime' => self::normalizeRuntime($value['runtime'] ?? null),
        ];
    }

    /** คืนเฉพาะ component ที่ใช้ได้จริง (อาจว่างได้ เพื่อให้คอนโทรลเลอร์เตือนผู้ใช้เองได้) */
    public static function componentsFrom(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $components = [];
        foreach (array_values($value) as $component) {
            if (! is_array($component) || ! in_array($component['type'] ?? '', self::COMPONENT_TYPES, true)) {
                continue;
            }

            $x = self::clamp($component['x'] ?? 1, 1, self::CANVAS_COLUMNS);
            $y = self::clamp($component['y'] ?? 1, 1, self::CANVAS_ROWS);
            $id = preg_replace('/[^a-z0-9_-]/i', '', (string) ($component['id'] ?? $component['type']));

            $components[] = [
                // id ว่างเปล่าแปลว่า client ส่งอักขระที่ใช้ไม่ได้มาล้วนๆ ถอยไปใช้ type แทน
                'id' => $id !== '' ? $id : (string) $component['type'],
                'type' => (string) $component['type'],
                'x' => $x,
                'y' => $y,
                // กันบล็อกล้นขอบขวา/ล่างของ canvas ตั้งแต่ตอนบันทึก ไม่ใช่ตอน render
                'w' => self::clamp($component['w'] ?? 3, 1, self::CANVAS_COLUMNS + 1 - $x),
                'h' => self::clamp($component['h'] ?? 2, 1, self::CANVAS_ROWS + 1 - $y),
            ];
        }

        return $components;
    }

    /** ค่า runtime ที่ใช้ทำ CSS ได้เลย ค่าผิดรูปจะถูกแทนด้วย default ไม่ใช่ทำให้พัง */
    public static function normalizeRuntime(mixed $value): array
    {
        $defaults = self::defaultRuntime();
        $value = is_array($value) ? $value : [];

        $product = self::clamp($value['product_width'] ?? $defaults['product_width'], self::MIN_PANE_WIDTH, self::MAX_PANE_WIDTH);
        $cart = self::clamp($value['cart_width'] ?? (100 - $product), self::MIN_PANE_WIDTH, self::MAX_PANE_WIDTH);

        // สองฝั่งต้องเต็มแถวเสมอ ถ้ารวมกันไม่ครบ 100 ให้เกลี่ยตามสัดส่วนที่ผู้ใช้ตั้งใจ
        $total = $product + $cart;
        if ($total !== 100) {
            $product = self::clamp((int) round($product / $total * 100), self::MIN_PANE_WIDTH, self::MAX_PANE_WIDTH);
        }
        $cart = 100 - $product;

        return [
            'product_width' => $product,
            'cart_width' => $cart,
            'product_rows' => self::clamp($value['product_rows'] ?? $defaults['product_rows'], self::MIN_PRODUCT_ROWS, self::MAX_PRODUCT_ROWS),
            'product_columns' => self::clamp($value['product_columns'] ?? $defaults['product_columns'], self::MIN_PRODUCT_COLUMNS, self::MAX_PRODUCT_COLUMNS),
            'density' => in_array($value['density'] ?? null, self::DENSITIES, true) ? $value['density'] : $defaults['density'],
            'button_size' => in_array($value['button_size'] ?? null, self::BUTTON_SIZES, true) ? $value['button_size'] : $defaults['button_size'],
            'show_branch' => self::bool($value['show_branch'] ?? $defaults['show_branch']),
            'show_terminal' => self::bool($value['show_terminal'] ?? $defaults['show_terminal']),
            'show_seller' => self::bool($value['show_seller'] ?? $defaults['show_seller']),
            'show_shift' => self::bool($value['show_shift'] ?? $defaults['show_shift']),
        ];
    }

    /** กฎตรวจค่าที่ผู้ใช้เพิ่งกรอกในหน้า Designer (ผิดแล้วต้องเห็นข้อความ ไม่ใช่ถูก clamp เงียบๆ) */
    public static function runtimeRules(): array
    {
        return [
            'runtime' => ['required', 'array'],
            'runtime.product_width' => ['required', 'integer', 'min:'.self::MIN_PANE_WIDTH, 'max:'.self::MAX_PANE_WIDTH],
            'runtime.cart_width' => ['required', 'integer', 'min:'.self::MIN_PANE_WIDTH, 'max:'.self::MAX_PANE_WIDTH],
            'runtime.product_rows' => ['required', 'integer', 'min:'.self::MIN_PRODUCT_ROWS, 'max:'.self::MAX_PRODUCT_ROWS],
            'runtime.product_columns' => ['required', 'integer', 'min:'.self::MIN_PRODUCT_COLUMNS, 'max:'.self::MAX_PRODUCT_COLUMNS],
            'runtime.density' => ['required', Rule::in(self::DENSITIES)],
            'runtime.button_size' => ['required', Rule::in(self::BUTTON_SIZES)],
            'runtime.show_branch' => ['nullable', 'boolean'],
            'runtime.show_terminal' => ['nullable', 'boolean'],
            'runtime.show_seller' => ['nullable', 'boolean'],
            'runtime.show_shift' => ['nullable', 'boolean'],
        ];
    }

    public static function runtimeMessages(): array
    {
        $panes = 'ต้องอยู่ระหว่าง '.self::MIN_PANE_WIDTH.'% ถึง '.self::MAX_PANE_WIDTH.'%';

        return [
            'runtime.product_width.min' => 'ความกว้างฝั่งสินค้า'.$panes,
            'runtime.product_width.max' => 'ความกว้างฝั่งสินค้า'.$panes,
            'runtime.cart_width.min' => 'ความกว้างฝั่งบิล'.$panes,
            'runtime.cart_width.max' => 'ความกว้างฝั่งบิล'.$panes,
            'runtime.product_rows.min' => 'จำนวนแถวสินค้าต้องอยู่ระหว่าง '.self::MIN_PRODUCT_ROWS.' ถึง '.self::MAX_PRODUCT_ROWS,
            'runtime.product_rows.max' => 'จำนวนแถวสินค้าต้องอยู่ระหว่าง '.self::MIN_PRODUCT_ROWS.' ถึง '.self::MAX_PRODUCT_ROWS,
            'runtime.product_columns.min' => 'จำนวนคอลัมน์สินค้าต้องอยู่ระหว่าง '.self::MIN_PRODUCT_COLUMNS.' ถึง '.self::MAX_PRODUCT_COLUMNS,
            'runtime.product_columns.max' => 'จำนวนคอลัมน์สินค้าต้องอยู่ระหว่าง '.self::MIN_PRODUCT_COLUMNS.' ถึง '.self::MAX_PRODUCT_COLUMNS,
            'runtime.density.in' => 'ความหนาแน่นต้องเป็น compact, comfortable หรือ roomy',
            'runtime.button_size.in' => 'ขนาดปุ่มต้องเป็น small, medium หรือ large',
        ];
    }

    /**
     * แนบตัวแปร CSS ที่คำนวณแล้วไปกับ payload ของ API
     * ทำให้ client (หน้าขายบนเว็บ) ไม่ต้องมีตาราง density/button ของตัวเองให้ค่าเพี้ยนกัน
     */
    public static function withCss(array $layout): array
    {
        $layout['css'] = self::cssVariables($layout['runtime'] ?? []);

        return $layout;
    }

    public static function densityMetrics(): array
    {
        return self::DENSITY_METRICS;
    }

    public static function buttonMetrics(): array
    {
        return self::BUTTON_METRICS;
    }

    /** ตัวแปร CSS ที่หน้าขายใช้ตรงๆ — ที่เดียวที่แปลง runtime เป็นหน่วย px/fr */
    public static function cssVariables(array $runtime): array
    {
        $runtime = self::normalizeRuntime($runtime);
        $density = self::DENSITY_METRICS[$runtime['density']];
        $button = self::BUTTON_METRICS[$runtime['button_size']];

        return [
            '--pos-product-fr' => $runtime['product_width'].'fr',
            '--pos-cart-fr' => $runtime['cart_width'].'fr',
            '--pos-product-columns' => (string) $runtime['product_columns'],
            '--pos-product-rows' => (string) $runtime['product_rows'],
            '--pos-card-min-height' => $density['card'].'px',
            '--pos-card-font-size' => $density['font'].'px',
            '--pos-grid-gap' => $density['gap'].'px',
            '--pos-grid-padding' => $density['padding'].'px',
            '--pos-button-min-height' => $button['height'].'px',
            '--pos-button-font-size' => $button['font'].'px',
            '--pos-button-padding' => $button['padding'].'px',
        ];
    }

    /** รูปแบบพร้อมวางใน <style> หรือ attribute style */
    public static function cssVariableString(array $runtime): string
    {
        $declarations = [];
        foreach (self::cssVariables($runtime) as $name => $value) {
            $declarations[] = $name.': '.$value.';';
        }

        return implode(' ', $declarations);
    }

    private static function decode(?string $json): ?array
    {
        if ($json === null || $json === '') {
            return null;
        }
        $value = json_decode($json, true);

        return is_array($value) ? $value : null;
    }

    private static function clamp(mixed $value, int $min, int $max): int
    {
        return max($min, min($max, (int) $value));
    }

    private static function bool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
    }
}
