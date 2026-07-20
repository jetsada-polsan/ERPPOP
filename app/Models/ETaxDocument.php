<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['document_id', 'document_uuid', 'status', 'payload_path', 'payload_hash', 'provider_reference', 'provider_message', 'prepared_at', 'sent_at', 'accepted_at'])]
class ETaxDocument extends Model
{
    protected $table = 'etax_documents';

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    protected function casts(): array
    {
        return ['prepared_at' => 'datetime', 'sent_at' => 'datetime', 'accepted_at' => 'datetime'];
    }
}
