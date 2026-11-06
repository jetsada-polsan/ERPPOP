<?php

namespace App\Http\Controllers;

use App\Services\Inventory\ScaleBarcodeService;
use App\Services\Inventory\ScalePluRenumber;
use Illuminate\Http\Request;

class ScalePluPlanController extends Controller
{
    public function preview(Request $request, ScalePluRenumber $service)
    {
        $data = $request->validate(['selected' => ['required', 'array'], 'selected.*' => ['nullable', 'integer']]);
        $id = $service->preview($data['selected']);

        return redirect()->route('scale-prices.index', ['plan' => $id, 'section' => 'plan']);
    }

    public function edit(Request $request, int $plan, ScalePluRenumber $service)
    {
        $data = $request->validate(['numbers' => ['required', 'array'], 'numbers.*' => ['required', 'regex:/^801[0-9]{3}$/D'], 'locked' => ['sometimes', 'array'], 'locked.*' => ['accepted']]);
        $service->edit($plan, $data['numbers'], $data['locked'] ?? [], $request->boolean('reorder'));

        return redirect()->route('scale-prices.index', ['plan' => $plan, 'section' => 'plan']);
    }

    public function apply(Request $request, int $plan, ScalePluRenumber $service)
    {
        $request->validate(['confirmed' => ['required', 'accepted'], 'revision' => ['required', 'string', 'size:64']]);
        $service->apply($plan, $request->boolean('accept_warnings'), $request->input('revision'));

        return redirect()->route('scale-prices.index', ['plan' => $plan, 'section' => 'plan'])->with('success', 'ใช้แผนแล้ว ตั้งเครื่องชั่งตามไฟล์และซิงก์สินค้า POS ใหม่');
    }

    public function rollback(Request $request, int $plan, ScalePluRenumber $service)
    {
        $request->validate(['confirmed' => ['required', 'accepted']]);
        $service->rollback($plan);

        return redirect()->route('scale-prices.index', ['plan' => $plan, 'section' => 'plan'])->with('success', 'คืนเลขเดิมแล้ว ซิงก์ POS และตั้งเครื่องชั่งกลับด้วย');
    }

    public function export(int $plan, ScalePluRenumber $service)
    {
        $p = $service->plan($plan);
        abort_unless($p->status === 'applied', 409);
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, ['PLU', 'รหัสเครื่อง 3 หลัก', 'รหัสป้าย 5 หลัก', 'ชื่อสั้น', 'ราคา/กก.', 'flag'], ',', '"', '');
        foreach ($p->rows as $r) {
            $name = mb_substr($r['name'], 0, 30);
            if (preg_match('/^[=+@\-\t\r]/', $name)) {
                $name = "'".$name;
            }
            fputcsv($stream, [$r['plu'], substr($r['plu'], -3), substr($r['plu'], 1), $name, number_format($r['price'], 2, '.', ''), 8], ',', '"', '');
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="scale-plan-'.$plan.'.csv"']);
    }

    public function labels(int $plan, ScalePluRenumber $service, ScaleBarcodeService $barcodes)
    {
        $p = $service->plan($plan);
        abort_unless($p->status === 'applied', 409);
        $labels = collect($p->rows)->map(function ($r) use ($barcodes) {
            $r['label'] = $barcodes->fromTotalPrice($r['plu'], (float) $r['price']);
            $r['svg'] = $barcodes->svg($r['label']);

            return $r;
        });

        return view('scale-prices.plan-labels', compact('labels'));
    }
}
