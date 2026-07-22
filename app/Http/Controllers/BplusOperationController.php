<?php

namespace App\Http\Controllers;

use App\Models\ApprovalRequest;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\CashBook;
use App\Models\PosPreparationJob;
use App\Models\PosReceipt;
use App\Models\PosTerminal;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchasePlan;
use App\Models\QrPaymentConfig;
use App\Models\ShowPriceDevice;
use App\Models\Supplier;
use App\Models\VatRate;
use App\Services\Purchasing\ReplenishmentService;
use App\Services\Sales\DocumentNumberGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class BplusOperationController extends Controller
{
    public function posPreparation(): View
    {
        return view('bplus-ops.pos-preparation', [
            'jobs' => PosPreparationJob::with(['branch', 'terminal'])->latest()->paginate(30),
            'branches' => Branch::orderBy('code')->get(),
            'terminals' => PosTerminal::with('branch')->orderBy('code')->get(),
        ]);
    }

    public function storePosPreparation(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'job_no' => ['required', 'string', 'max:40', 'unique:pos_preparation_jobs,job_no'],
            'job_type' => ['required', 'string', 'max:40'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'pos_terminal_id' => ['nullable', 'integer', 'exists:pos_terminals,id'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'status' => ['required', 'string', 'max:30'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        PosPreparationJob::create($data);

        return redirect()->route('bplus.pos-preparation')->with('success', 'บันทึกงานเตรียมข้อมูล POS แล้ว');
    }

    public function posWorkbench(Request $request): View
    {
        $selectedDate = Carbon::parse(
            $request->query('date', now('Asia/Bangkok')->toDateString()),
            'Asia/Bangkok'
        )->toDateString();
        $lockedBranchId = auth()->user()?->branchScopeId();
        $selectedBranchId = $lockedBranchId ?: ($request->integer('branch_id') ?: null);

        $branches = Branch::query()
            ->when($lockedBranchId, fn ($query) => $query->whereKey($lockedBranchId))
            ->orderBy('code')
            ->get();

        $receipts = PosReceipt::with(['terminal.branch', 'shift.branch'])
            ->whereDate('receipt_date', $selectedDate)
            ->when($selectedBranchId, fn ($query) => $query->where(function ($w) use ($selectedBranchId) {
                $w->whereHas('terminal', fn ($terminal) => $terminal->where('branch_id', $selectedBranchId))
                    ->orWhereHas('shift', fn ($shift) => $shift->where('branch_id', $selectedBranchId));
            }))
            ->latest('receipt_date')
            ->limit(20)
            ->get();

        return view('bplus-ops.pos-workbench', [
            'receipts' => $receipts,
            'branches' => $branches,
            'selectedDate' => $selectedDate,
            'selectedBranchId' => $selectedBranchId,
            'lockedBranchId' => $lockedBranchId,
        ]);
    }

    public function purchasePlanning(Request $request, ReplenishmentService $replenishment): View
    {
        $lockedBranchId = auth()->user()?->branchScopeId();
        $branchId = $lockedBranchId ?: ($request->integer('branch_id') ?: Branch::orderBy('id')->value('id'));
        $salesDays = max(7, min(365, $request->integer('sales_days', 30)));
        $safetyDays = max(0, min(90, $request->integer('safety_days', 7)));

        return view('bplus-ops.purchase-planning', [
            'plans' => PurchasePlan::with(['product', 'supplier'])->latest()->paginate(30),
            'products' => Product::where('is_active', true)->orderBy('sku_code')->limit(300)->get(),
            'suppliers' => Supplier::orderBy('code')->limit(200)->get(),
            'branches' => Branch::query()->when($lockedBranchId, fn ($query) => $query->whereKey($lockedBranchId))->orderBy('code')->get(['id', 'code', 'name_th']),
            'branchId' => $branchId,
            'salesDays' => $salesDays,
            'safetyDays' => $safetyDays,
            'suggestions' => $branchId ? $replenishment->suggestions($branchId, $salesDays, $safetyDays) : collect(),
        ]);
    }

    public function generatePurchaseRequisitions(Request $request, DocumentNumberGenerator $numbers): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'items.*.qty' => ['required', 'numeric', 'min:0.0001'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
        ]);
        $lockedBranchId = auth()->user()?->branchScopeId();
        abort_if($lockedBranchId && $lockedBranchId !== (int) $data['branch_id'], 403, 'สร้างใบขอซื้อได้เฉพาะสาขาของตนเอง');

        $orders = DB::transaction(function () use ($data, $numbers) {
            return collect($data['items'])->groupBy(fn ($item) => (string) ($item['supplier_id'] ?? 'none'))
                ->map(function ($items) use ($data, $numbers) {
                    $supplierId = $items->first()['supplier_id'] ?? null;
                    $order = PurchaseOrder::create([
                        'doc_number' => $numbers->nextPurchaseOrder($data['branch_id']),
                        'branch_id' => $data['branch_id'], 'supplier_id' => $supplierId,
                        'doc_date' => now()->toDateString(), 'status' => 'requested',
                        'requested_by' => auth()->id(), 'note' => 'สร้างจากคำแนะนำเติมเต็มสินค้า',
                    ]);
                    $total = 0;
                    foreach ($items as $item) {
                        $price = (float) ($item['unit_price'] ?? 0);
                        PurchaseOrderItem::create([
                            'purchase_order_id' => $order->id, 'product_id' => $item['product_id'],
                            'qty' => $item['qty'], 'unit_price' => $price,
                            'note' => 'Replenishment suggestion',
                        ]);
                        $total += (float) $item['qty'] * $price;
                    }
                    $order->update(['total_amount' => round($total, 2)]);

                    return $order;
                });
        });

        return redirect()->route('purchase-orders.index')
            ->with('success', 'สร้างใบขอซื้อจากแผนเติมเต็ม '.$orders->count().' ใบแล้ว กรุณาตรวจและส่งอนุมัติ');
    }

    public function storePurchasePlan(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'plan_no' => ['required', 'string', 'max:40', 'unique:purchase_plans,plan_no'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'suggested_qty' => ['nullable', 'numeric', 'min:0'],
            'target_stock_qty' => ['nullable', 'numeric', 'min:0'],
            'status' => ['required', 'string', 'max:30'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $data['suggested_qty'] = $data['suggested_qty'] ?? 0;
        $data['target_stock_qty'] = $data['target_stock_qty'] ?? 0;
        PurchasePlan::create($data);

        return redirect()->route('bplus.purchase-planning')->with('success', 'บันทึกแผนจัดซื้อแล้ว');
    }

    public function approvals(): View
    {
        return view('bplus-ops.approvals', [
            'requests' => ApprovalRequest::latest()->paginate(40),
        ]);
    }

    public function storeApproval(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'request_no' => ['required', 'string', 'max:40', 'unique:approval_requests,request_no'],
            'approval_type' => ['required', 'string', 'max:40'],
            'subject' => ['required', 'string', 'max:150'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'status' => ['required', 'string', 'max:30'],
            'requested_by' => ['nullable', 'string', 'max:100'],
            'approved_by' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $data['amount'] = $data['amount'] ?? 0;
        ApprovalRequest::create($data);

        return redirect()->route('bplus.approvals')->with('success', 'บันทึกคำขออนุมัติแล้ว');
    }

    public function finance(): View
    {
        return view('bplus-ops.finance', [
            'cashBooks' => CashBook::with('branch')->orderByDesc('entry_date')->paginate(40),
            'bankAccounts' => BankAccount::orderBy('bank_name')->get(),
            'branches' => Branch::orderBy('code')->get(),
        ]);
    }

    public function storeCashBook(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'entry_date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:2000'],
            'debit' => ['nullable', 'numeric', 'min:0'],
            'credit' => ['nullable', 'numeric', 'min:0'],
            'balance' => ['nullable', 'numeric'],
        ]);
        $data['debit'] = $data['debit'] ?? 0;
        $data['credit'] = $data['credit'] ?? 0;
        $data['balance'] = $data['balance'] ?? ($data['debit'] - $data['credit']);
        CashBook::create($data);

        return redirect()->route('bplus.finance')->with('success', 'บันทึกสมุดเงินสดแล้ว');
    }

    public function tax(): View
    {
        $vatSales = DB::table('pos_receipts')
            ->selectRaw('coalesce(sum(net_sales),0) as net_sales, coalesce(sum(vat_amount),0) as vat_amount')
            ->first();

        return view('bplus-ops.tax', [
            'vatRates' => VatRate::orderByDesc('effective_from')->paginate(30),
            'vatSales' => $vatSales,
        ]);
    }

    public function storeVatRate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'rate_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ]);
        VatRate::create($data);

        return redirect()->route('bplus.tax')->with('success', 'บันทึกอัตราภาษีแล้ว');
    }

    public function qrPayments(): View
    {
        return view('bplus-ops.qr-payments', [
            'configs' => QrPaymentConfig::with('bankAccount')->orderBy('code')->paginate(40),
            'bankAccounts' => BankAccount::orderBy('bank_name')->get(),
        ]);
    }

    public function destroyQrPayment(QrPaymentConfig $qrPaymentConfig): RedirectResponse
    {
        $name = $qrPaymentConfig->name;
        $qrPaymentConfig->delete();

        return redirect()->route('bplus.qr-payments')->with('success', "ลบ \"{$name}\" แล้ว");
    }

    public function updateQrPayment(Request $request, QrPaymentConfig $qrPaymentConfig): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'qr_type' => ['required', 'string', 'max:40'],
            'bank_account_id' => ['nullable', 'integer', 'exists:bank_accounts,id'],
            'merchant_ref' => ['required', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $data['is_active'] = $request->boolean('is_active', true);
        $qrPaymentConfig->update($data);

        return redirect()->route('bplus.qr-payments')->with('success', "บันทึก PromptPay ID สำหรับ {$qrPaymentConfig->name} แล้ว");
    }

    public function storeQrPayment(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'unique:qr_payment_configs,code'],
            'name' => ['required', 'string', 'max:150'],
            'qr_type' => ['required', 'string', 'max:40'],
            'bank_account_id' => ['nullable', 'integer', 'exists:bank_accounts,id'],
            'merchant_ref' => ['nullable', 'string', 'max:100'],
            'payload_template' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $data['is_active'] = $request->boolean('is_active', true);
        QrPaymentConfig::create($data);

        return redirect()->route('bplus.qr-payments')->with('success', 'บันทึก QR/EDC แล้ว');
    }

    public function showPrice(): View
    {
        return view('bplus-ops.show-price', [
            'devices' => ShowPriceDevice::with('branch')->orderBy('code')->paginate(40),
            'branches' => Branch::orderBy('code')->get(),
        ]);
    }

    public function storeShowPrice(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'unique:show_price_devices,code'],
            'name' => ['required', 'string', 'max:150'],
            'device_type' => ['required', 'string', 'max:40'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'ip_address' => ['nullable', 'string', 'max:80'],
            'status' => ['required', 'string', 'max:30'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        ShowPriceDevice::create($data);

        return redirect()->route('bplus.show-price')->with('success', 'บันทึกอุปกรณ์หน้าร้านแล้ว');
    }
}
