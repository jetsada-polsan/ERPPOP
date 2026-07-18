<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\Cheque;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ChequeController extends Controller
{
    public function index(Request $request): View
    {
        $direction = $request->query('direction', 'in') === 'out' ? 'out' : 'in';
        $status = $request->query('status');
        $q = trim((string) $request->query('q', ''));
        $from = $request->query('from');
        $to = $request->query('to');

        $base = Cheque::where('direction', $direction)
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($from, fn ($query) => $query->whereDate('cheque_date', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('cheque_date', '<=', $to))
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('cheque_no', 'ilike', "%{$q}%")
                ->orWhere('bank_name', 'ilike', "%{$q}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name_th', 'ilike', "%{$q}%"))
                ->orWhereHas('supplier', fn ($s) => $s->where('name_th', 'ilike', "%{$q}%"))
            ));

        $cheques = (clone $base)->with(['customer', 'supplier', 'bankAccount', 'branch'])
            ->orderBy('cheque_date')->orderByDesc('id')
            ->paginate(50)->withQueryString();

        // สรุปสถานะของฝั่งที่ดูอยู่ (ไม่ติด filter สถานะ เพื่อใช้เป็นแท็บ)
        $statusCounts = Cheque::where('direction', $direction)
            ->selectRaw('status, COUNT(*) AS c, SUM(amount) AS total')
            ->groupBy('status')->get()->keyBy('status');

        $dueSoon = Cheque::where('direction', $direction)
            ->whereIn('status', ['on_hand', 'deposited', 'issued'])
            ->whereBetween('cheque_date', [now()->toDateString(), now()->addDays(7)->toDateString()])
            ->count();

        return view('cheques.index', [
            'cheques' => $cheques,
            'direction' => $direction,
            'status' => $status,
            'statusCounts' => $statusCounts,
            'dueSoon' => $dueSoon,
            'filters' => ['q' => $q, 'from' => $from, 'to' => $to],
            'bankAccounts' => BankAccount::orderBy('bank_name')->get(),
            'branches' => Branch::orderBy('code')->get(['id', 'code', 'name_th']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'direction' => ['required', 'in:in,out'],
            'cheque_no' => ['required', 'string', 'max:40'],
            'bank_name' => ['nullable', 'string', 'max:100'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'bank_account_id' => ['nullable', 'integer', 'exists:bank_accounts,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'cheque_date' => ['required', 'date'],
            'remark' => ['nullable', 'string', 'max:500'],
        ], [
            'cheque_no.required' => 'กรุณากรอกเลขที่เช็ค',
            'amount.required' => 'กรุณากรอกจำนวนเงิน',
            'cheque_date.required' => 'กรุณาระบุวันที่บนเช็ค',
        ]);

        $data['status'] = $data['direction'] === 'in' ? 'on_hand' : 'issued';
        $cheque = Cheque::create($data);

        return redirect()->route('cheques.index', ['direction' => $cheque->direction])
            ->with('success', 'บันทึกเช็ค '.$cheque->cheque_no.' เข้าทะเบียนแล้ว');
    }

    // เช็ครับ: นำฝากเข้าบัญชีธนาคารของเรา
    public function deposit(Request $request, Cheque $cheque): RedirectResponse
    {
        abort_unless($cheque->direction === 'in' && $cheque->status === 'on_hand', 422, 'สถานะเช็คไม่ถูกต้อง');
        $data = $request->validate(
            ['bank_account_id' => ['required', 'integer', 'exists:bank_accounts,id']],
            ['bank_account_id.required' => 'กรุณาเลือกบัญชีที่นำฝาก'],
        );

        $cheque->update([
            'status' => 'deposited',
            'bank_account_id' => $data['bank_account_id'],
            'deposited_at' => now()->toDateString(),
        ]);

        return back()->with('success', "นำฝากเช็ค {$cheque->cheque_no} แล้ว");
    }

    // เช็คผ่าน / ตัดบัญชีสำเร็จ
    public function clear(Cheque $cheque): RedirectResponse
    {
        abort_unless(in_array($cheque->status, ['deposited', 'issued', 'on_hand'], true), 422, 'สถานะเช็คไม่ถูกต้อง');

        $cheque->update(['status' => 'cleared', 'cleared_at' => now()->toDateString()]);

        return back()->with('success', "เช็ค {$cheque->cheque_no} ผ่านเรียบร้อย");
    }

    // เช็ครับคืน/เด้ง - ต้องกลับไปตามหนี้ลูกค้าต่อ
    public function bounce(Request $request, Cheque $cheque): RedirectResponse
    {
        abort_unless($cheque->direction === 'in' && ! $cheque->isFinal(), 422, 'สถานะเช็คไม่ถูกต้อง');

        $cheque->update([
            'status' => 'bounced',
            'remark' => trim(($cheque->remark ? $cheque->remark.' | ' : '').'เช็คคืน '.now()->thaiDate().($request->input('reason') ? ': '.$request->input('reason') : '')),
        ]);

        return back()->with('success', "บันทึกเช็คคืน {$cheque->cheque_no} แล้ว — อย่าลืมติดตามหนี้จากลูกค้าต่อ");
    }

    public function cancel(Cheque $cheque): RedirectResponse
    {
        abort_if($cheque->isFinal(), 422, 'เช็คนี้ปิดรายการไปแล้ว');

        $cheque->update(['status' => 'cancelled']);

        return back()->with('success', "ยกเลิกเช็ค {$cheque->cheque_no} แล้ว");
    }
}
