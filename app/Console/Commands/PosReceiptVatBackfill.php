<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * POS receipts used to copy VAT from the till, which never sent it, so pos_receipts.vat_amount
 * and pos_receipt_items.vat_amount were stored as 0. The linked cash-sale document already holds
 * the VAT that GL posted (per product is_vat, no-VAT books). This copies that figure across.
 *
 * Read-only by default. Touches only VAT columns; never totals, payments, stock or GL.
 */
class PosReceiptVatBackfill extends Command
{
    protected $signature = 'erp:pos-receipt-vat-backfill {--apply : Write the changes (default is a dry run)} {--from= : Only receipts on/after this date (Y-m-d)}';

    protected $description = 'Copy server-computed VAT from POS sale documents onto POS receipts and receipt lines';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $query = DB::table('pos_receipts as r')
            ->join('documents as d', 'd.id', '=', 'r.document_id')
            ->select('r.id', 'r.receipt_no', 'r.vat_amount as receipt_vat', 'r.net_sales', 'd.id as document_id', 'd.vat_amount as document_vat', 'd.total_amount')
            ->orderBy('r.id');
        if ($this->option('from')) {
            $query->whereDate('r.receipt_date', '>=', $this->option('from'));
        }

        $receipts = 0;
        $lines = 0;
        $skipped = [];
        $before = 0.0;
        $after = 0.0;

        foreach ($query->cursor() as $row) {
            if ($row->document_vat === null) {
                $skipped[] = $row->receipt_no.' (เอกสารขายยังไม่มี VAT)';

                continue;
            }
            $newVat = round((float) $row->document_vat, 2);
            $lineVat = DB::table('stock_document_items as i')
                ->join('stock_documents as s', 's.id', '=', 'i.stock_document_id')
                ->where('s.document_id', $row->document_id)
                ->orderBy('i.seq')
                ->pluck('i.vat_amount')
                ->values();
            $receiptLines = DB::table('pos_receipt_items')->where('pos_receipt_id', $row->id)->orderBy('seq')->get(['id', 'vat_amount']);
            if ($receiptLines->count() !== $lineVat->count()) {
                $skipped[] = $row->receipt_no.' (จำนวนบรรทัดไม่ตรงเอกสาร '.$receiptLines->count().'/'.$lineVat->count().')';

                continue;
            }

            $headerChanged = abs((float) $row->receipt_vat - $newVat) >= 0.005;
            $changedLines = $receiptLines->filter(fn ($line, $i) => abs((float) $line->vat_amount - (float) $lineVat[$i]) >= 0.00005);
            if (! $headerChanged && $changedLines->isEmpty()) {
                continue;
            }

            $receipts++;
            $lines += $changedLines->count();
            $before += (float) $row->receipt_vat;
            $after += $newVat;

            if ($apply) {
                DB::transaction(function () use ($row, $newVat, $receiptLines, $lineVat) {
                    DB::table('pos_receipts')->where('id', $row->id)->update(['vat_amount' => $newVat]);
                    foreach ($receiptLines as $i => $line) {
                        DB::table('pos_receipt_items')->where('id', $line->id)->update(['vat_amount' => round((float) $lineVat[$i], 4)]);
                    }
                });
            }
        }

        $this->info(($apply ? 'แก้แล้ว' : 'DRY RUN (ยังไม่แก้)').": บิล {$receipts} ใบ, บรรทัด {$lines} บรรทัด");
        $this->info('VAT รวมของบิลที่เปลี่ยน: เดิม '.number_format($before, 2).' → ใหม่ '.number_format($after, 2));
        foreach ($skipped as $note) {
            $this->warn('ข้าม: '.$note);
        }
        if (! $apply && $receipts > 0) {
            $this->line('ตรวจแล้วรันซ้ำด้วย --apply');
        }

        return self::SUCCESS;
    }
}
