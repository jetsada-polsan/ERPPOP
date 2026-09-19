<?php

namespace App\Services\Documents;

use App\Models\Document;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class DocumentLifecycleService
{
    public function submit(Document $document, int $userId): Document
    {
        if (! in_array($document->status, ['draft', 'rejected'], true)) {
            throw new RuntimeException('เอกสารนี้ไม่อยู่ในสถานะที่ส่งตรวจได้');
        }

        return DB::transaction(function () use ($document, $userId): Document {
            $document->update([
                'status' => 'pending_approval',
                'submitted_by' => $userId,
                'submitted_at' => now(),
                'approved_by' => null,
                'approved_at' => null,
            ]);

            return $document->refresh();
        });
    }

    public function approve(Document $document, int $userId, ?string $note = null): Document
    {
        if ($document->status !== 'pending_approval') {
            throw new RuntimeException('เอกสารนี้ยังไม่อยู่ในสถานะรออนุมัติ');
        }
        if ((int) $document->submitted_by === $userId) {
            throw new RuntimeException('ผู้ส่งตรวจไม่สามารถอนุมัติเอกสารของตนเองได้');
        }

        return DB::transaction(function () use ($document, $userId, $note): Document {
            $document->update([
                'status' => 'approved',
                'approved_by' => $userId,
                'approved_at' => now(),
                'approval_note' => $note,
            ]);

            return $document->refresh();
        });
    }

    public function reject(Document $document, int $userId, string $note): Document
    {
        if ($document->status !== 'pending_approval') {
            throw new RuntimeException('เอกสารนี้ยังไม่อยู่ในสถานะรอตีกลับ');
        }

        return DB::transaction(function () use ($document, $userId, $note): Document {
            $document->update([
                'status' => 'rejected',
                'approved_by' => $userId,
                'approved_at' => now(),
                'approval_note' => $note,
            ]);

            return $document->refresh();
        });
    }
}
