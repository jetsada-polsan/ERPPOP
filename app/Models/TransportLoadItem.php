<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
#[Fillable(['transport_job_id','stock_document_item_id','planned_qty','loaded_qty','availability','note','updated_by'])]
class TransportLoadItem extends Model { protected $casts=['planned_qty'=>'decimal:8','loaded_qty'=>'decimal:8']; public function job(){return $this->belongsTo(TransportJob::class,'transport_job_id');} public function stockItem(){return $this->belongsTo(StockDocumentItem::class,'stock_document_item_id');} }
