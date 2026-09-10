<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
#[Fillable(['booking_id','vehicle_id','status','loaded_at','delivered_at','paid_at','payment_method','paid_amount','transfer_last4','payment_document_id','note','updated_by'])]
class TransportJob extends Model { protected function casts(): array { return ['loaded_at'=>'datetime','delivered_at'=>'datetime','paid_at'=>'datetime','paid_amount'=>'decimal:2']; } public function booking(){return $this->belongsTo(SaleBooking::class,'booking_id');} public function vehicle(){return $this->belongsTo(FleetVehicle::class);} }
