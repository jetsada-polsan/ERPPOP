<?php

namespace App\Http\Controllers;

use App\Models\ProductScanLog;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * บันทึกผลการสแกนจากหน้าเว็บ (POS / คลังมือถือ)
 *
 * หน้าเว็บค้นสินค้าผ่าน endpoint ค้นหาปกติ จึงต้องส่งผลมาบันทึกแยก
 * การบันทึกล้มเหลวต้องไม่ทำให้การขายหรือรับเข้าหยุด
 */
class ProductScanLogController extends Controller
{
    public const CONTEXTS = ['pos', 'wh_receive', 'wh_po', 'wh_stock', 'wh_count'];

    public const SOURCES = ['camera', 'image', 'keyboard', 'manual'];

    public const RESULTS = ['matched', 'not_found'];

    public function store(Request $request): Response
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:100'],
            'context' => ['required', 'string', 'in:'.implode(',', self::CONTEXTS)],
            'source' => ['nullable', 'string', 'in:'.implode(',', self::SOURCES)],
            'result' => ['required', 'string', 'in:'.implode(',', self::RESULTS)],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $context = $data['context'];
        if ($request->routeIs('pos.*') && $context !== 'pos') {
            $context = 'pos';
        }
        if ($request->routeIs('wh.*') && $context === 'pos') {
            $context = 'wh_stock';
        }

        try {
            ProductScanLog::create([
                'user_id' => $request->user()?->id,
                'branch_id' => $data['branch_id'] ?? null,
                'product_id' => $data['result'] === 'matched' ? ($data['product_id'] ?? null) : null,
                'context' => $context,
                'code' => trim($data['code']),
                'source' => $data['source'] ?? 'manual',
                'result' => $data['result'],
            ]);
        } catch (\Throwable) {
            // Audit must never block a sale or a receipt.
        }

        return response()->noContent();
    }
}
