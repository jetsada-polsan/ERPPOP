<?php

namespace App\Http\Controllers;

use App\Models\ChartOfAccount;
use App\Models\Document;
use App\Models\SupplierOpenItem;
use App\Services\Purchasing\SupplierNoteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SupplierNoteController extends Controller
{
    public function index(Request $request)
    {
        $branch = $request->user()->branch_id;
        return view('supplier-notes.index', [
            'notes' => Document::with('supplier')->whereHas('documentType', fn ($q) => $q->whereIn('code', ['SUPPLIER_CREDIT_NOTE', 'SUPPLIER_DEBIT_NOTE']))
                ->when($branch, fn ($q) => $q->where('branch_id', $branch))->latest('id')->paginate(30),
            'items' => SupplierOpenItem::with('supplier')->whereHas('sourceDocument', fn ($q) => $q->where('status', 'active')->when($branch, fn ($q) => $q->where('branch_id', $branch)))->orderByDesc('id')->limit(500)->get(),
            'accounts' => ChartOfAccount::where('account_type', 'expense')->orderBy('code')->get(),
        ]);
    }

    public function store(Request $request, SupplierNoteService $service)
    {
        $data = $request->validate([
            'supplier_open_item_id' => 'required|integer|exists:supplier_open_items,id',
            'account_id' => 'required|integer|exists:chart_of_accounts,id',
            'kind' => 'required|in:credit,debit', 'base_amount' => 'required|numeric|min:0.01',
            'vat_amount' => 'required|numeric|min:0', 'reason' => 'required|string|max:1000',
        ]);
        try {
            $service->create($data, $request->user());
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
        return back()->with('success', 'บันทึกเอกสารรออนุมัติแล้ว');
    }

    public function approve(Request $request, Document $note, SupplierNoteService $service)
    {
        try {
            $service->approve($note, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        return back()->with('success', 'อนุมัติและปรับเจ้าหนี้/บัญชีแล้ว');
    }

    public function reject(Request $request, Document $note)
    {
        $data = $request->validate(['reason' => 'required|string|max:1000']);
        DB::transaction(function () use ($request, $note, $data) {
            $locked = Document::whereKey($note->id)->lockForUpdate()->firstOrFail();
            abort_unless(DB::table('supplier_notes')->where('document_id', $locked->id)->exists(), 404);
            abort_unless($locked->status === 'pending_approval', 422);
            abort_if((int) $locked->created_by === (int) $request->user()->id, 403);
            abort_if($request->user()->branch_id && (int) $request->user()->branch_id !== (int) $locked->branch_id, 403);
            $locked->update(['status' => 'rejected', 'approval_note' => $data['reason'], 'approved_by' => $request->user()->id, 'approved_at' => now()]);
        });
        return back()->with('success', 'ปฏิเสธเอกสารแล้ว');
    }
}
