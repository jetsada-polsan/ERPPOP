<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\Document;
use App\Services\Accounting\CashTransferService;
use App\Support\FinanceBranches;
use App\Support\ListDateRange;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * ฝากเงินสดเข้าธนาคาร และถอนเงินสดจากธนาคาร
 *
 * เป็นแหล่งที่หกของสมุดเงินสด ถ้าไม่มีหน้าจอนี้ เงินสดที่เอาไปฝากจะไม่ถูกบันทึก
 * แล้วยอดเงินสดคงเหลือจะสูงกว่าของจริงไปเรื่อย ๆ
 */
class CashTransferController extends Controller
{
    public function index(Request $request): View
    {
        $branches = FinanceBranches::available($request->user());
        $branchId = $branches->count() === 1 ? (int) $branches->first()->id : null;
        $q = trim((string) $request->query('q', ''));
        $dateRange = ListDateRange::fromRequest($request, 'doc_date');

        return view('cash-transfers.index', [
            'transfers' => Document::query()
                ->with(['branch', 'documentType'])
                ->whereHas('documentType', fn ($query) => $query->whereIn('code', [CashTransferService::DEPOSIT, CashTransferService::WITHDRAWAL]))
                // ผู้ใช้ที่สังกัดสาขาเห็นเฉพาะสาขาตัวเอง เหมือนกติกาของรายงาน
                ->whereIn('branch_id', $branches->pluck('id'))
                ->when($q !== '', fn ($query) => $query->where('doc_number', 'ilike', "%{$q}%"))
                ->tap(fn ($query) => $dateRange->apply($query))
                ->orderByDesc('doc_date')->orderByDesc('id')
                ->paginate(25)
                ->withQueryString(),
            'branches' => $branches,
            'bankAccounts' => BankAccount::orderBy('bank_name')->get(),
            'defaultBranchId' => $branchId,
            'dateRange' => $dateRange,
            'q' => $q,
        ]);
    }

    public function store(Request $request, CashTransferService $service): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:'.CashTransferService::DEPOSIT.','.CashTransferService::WITHDRAWAL],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'bank_account_id' => ['required', 'integer', 'exists:bank_accounts,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'transfer_date' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:100'],
            'remark' => ['nullable', 'string', 'max:500'],
        ]);

        abort_unless(FinanceBranches::allows($request->user(), (int) $data['branch_id']), 403);

        try {
            $document = $service->create($data['type'], $data);
        } catch (RuntimeException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()->route('cash-transfers.index')->with('success', sprintf(
            '%s %s บาท เรียบร้อย เลขที่ %s — เข้าสมุดเงินสดและ GL แล้ว',
            $data['type'] === CashTransferService::DEPOSIT ? 'ฝากเงินสด' : 'ถอนเงินสด',
            number_format((float) $data['amount'], 2),
            $document->doc_number,
        ));
    }
}
