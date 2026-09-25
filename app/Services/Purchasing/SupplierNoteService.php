<?php

namespace App\Services\Purchasing;

use App\Models\ChartOfAccount;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\GlJournal;
use App\Models\Supplier;
use App\Models\SupplierLedger;
use App\Models\SupplierOpenItem;
use App\Models\User;
use App\Services\Sales\DocumentNumberGenerator;
use App\Support\DecimalMath;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SupplierNoteService
{
    public function create(array $data, User $actor): Document
    {
        if (! $actor->hasPermission('purchasing.manage')) {
            throw new RuntimeException('ไม่มีสิทธิ์จัดซื้อ');
        }
        return DB::transaction(function () use ($data, $actor) {
            $item = SupplierOpenItem::findOrFail($data['supplier_open_item_id']);
            $source = $item->sourceDocument;
            if (! $source || $source->status !== 'active' || ($actor->branch_id && (int) $actor->branch_id !== (int) $source->branch_id)) {
                throw new RuntimeException('ใบซื้ออ้างอิงไม่พร้อมใช้งานหรืออยู่ต่างสาขา');
            }
            if (! in_array($data['kind'], ['credit', 'debit'], true)) {
                throw new RuntimeException('ประเภทเอกสารไม่ถูกต้อง');
            }
            $account = ChartOfAccount::findOrFail($data['account_id']);
            if ($account->account_type !== 'expense') {
                throw new RuntimeException('เลือกบัญชีค่าใช้จ่ายหรือต้นทุนสำหรับปรับมูลค่า');
            }
            $base = DecimalMath::round($data['base_amount'], 2);
            $vat = DecimalMath::round($data['vat_amount'] ?? 0, 2);
            if (DecimalMath::compare($base, 0) <= 0 || DecimalMath::compare($vat, 0) < 0 || trim($data['reason']) === '') {
                throw new RuntimeException('กรอกจำนวนเงินและเหตุผลให้ครบ');
            }
            $total = DecimalMath::add($base, $vat);
            if ($data['kind'] === 'credit' && DecimalMath::compare($total, $item->balance_amount) > 0) {
                throw new RuntimeException('ยอดลดหนี้เกินยอดเจ้าหนี้คงค้าง');
            }
            $code = $data['kind'] === 'credit' ? 'SUPPLIER_CREDIT_NOTE' : 'SUPPLIER_DEBIT_NOTE';
            $doc = Document::create([
                'document_type_id' => DocumentType::where('code', $code)->sole()->id,
                'branch_id' => $source->branch_id, 'supplier_id' => $item->supplier_id,
                'doc_number' => app(DocumentNumberGenerator::class)->next($code, $source->branch_id),
                'doc_date' => now()->toDateString(), 'reference' => $source->doc_number,
                'status' => 'pending_approval', 'created_by' => $actor->id,
                'total_amount' => $total, 'subtotal_amount' => $base, 'vat_amount' => $vat,
                'total_items' => 1, 'remark' => $data['reason'],
            ]);
            DB::table('supplier_notes')->insert([
                'document_id' => $doc->id, 'supplier_open_item_id' => $item->id,
                'account_id' => $account->id, 'kind' => $data['kind'],
                'base_amount' => $base, 'vat_amount' => $vat, 'created_at' => now(), 'updated_at' => now(),
            ]);
            return $doc;
        });
    }

    public function approve(Document $document, User $actor): Document
    {
        if (! $actor->hasPermission('finance.note.approve')) {
            throw new RuntimeException('ไม่มีสิทธิ์อนุมัติใบเพิ่มลดหนี้');
        }
        return DB::transaction(function () use ($document, $actor) {
            $doc = Document::whereKey($document->id)->lockForUpdate()->firstOrFail();
            app(\App\Services\Documents\ApprovalPolicyService::class)->authorize($doc->documentType->code, $doc->total_amount,
                (int) $doc->branch_id, $doc->created_by, $actor, 'finance.note.approve');
            if ($doc->status !== 'pending_approval' || (int) $doc->created_by === (int) $actor->id
                || ($actor->branch_id && (int) $actor->branch_id !== (int) $doc->branch_id)) {
                throw new RuntimeException('สถานะหรือผู้อนุมัติไม่ถูกต้อง');
            }
            $note = DB::table('supplier_notes')->where('document_id', $doc->id)->firstOrFail();
            Supplier::whereKey($doc->supplier_id)->lockForUpdate()->firstOrFail();
            $item = SupplierOpenItem::whereKey($note->supplier_open_item_id)->lockForUpdate()->firstOrFail();
            $credit = $note->kind === 'credit';
            $amount = $doc->total_amount;
            if ($credit && DecimalMath::compare($amount, $item->balance_amount) > 0) {
                throw new RuntimeException('ยอดคงค้างเปลี่ยนไป กรุณาตรวจใบลดหนี้ใหม่');
            }
            $ap = ChartOfAccount::where('default_role', ChartOfAccount::ROLE_AP)->sole();
            $vat = DecimalMath::compare($note->vat_amount, 0) > 0
                ? ChartOfAccount::where('default_role', ChartOfAccount::ROLE_VAT_INPUT)->sole() : null;
            foreach ([[$ap->id, $amount, $credit], [$note->account_id, $note->base_amount, ! $credit], [$vat?->id, $note->vat_amount, ! $credit]] as [$account, $value, $debit]) {
                if (! $account || DecimalMath::compare($value, 0) === 0) {
                    continue;
                }
                GlJournal::create(['document_id' => $doc->id, 'account_id' => $account,
                    'debit' => $debit ? $value : 0, 'credit' => $debit ? 0 : $value,
                    'entry_date' => $doc->doc_date, 'remark' => $doc->doc_number]);
            }
            $balance = $credit ? DecimalMath::subtract($item->balance_amount, $amount) : DecimalMath::add($item->balance_amount, $amount);
            $item->update(['balance_amount' => $balance, 'status' => DecimalMath::compare($balance, 0) === 0 ? 'cleared' : 'partial',
                'cleared_at' => DecimalMath::compare($balance, 0) === 0 ? now() : null]);
            $last = SupplierLedger::where('supplier_id', $doc->supplier_id)->latest('id')->value('balance_after') ?? 0;
            SupplierLedger::create(['supplier_id' => $doc->supplier_id, 'document_id' => $doc->id,
                'entry_type' => $credit ? 'debit' : 'credit', 'amount' => $amount,
                'balance_after' => $credit ? DecimalMath::subtract($last, $amount) : DecimalMath::add($last, $amount), 'entry_date' => $doc->doc_date]);
            $doc->update(['status' => 'active', 'approved_by' => $actor->id, 'approved_at' => now()]);
            return $doc->fresh();
        });
    }
}
