<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
#[Fillable(['branch_id','code','registration','vehicle_type','brand','model','current_odometer','status','note'])]
class FleetVehicle extends Model { public function branch(){return $this->belongsTo(Branch::class);} public function trips(){return $this->hasMany(FleetTrip::class,'vehicle_id');} public function repairs(){return $this->hasMany(FleetRepair::class,'vehicle_id');} }
