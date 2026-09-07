<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
#[Fillable(['vehicle_id','repair_date','odometer','summary','vendor','cost','next_service_date','note','created_by'])]
class FleetRepair extends Model { protected $casts=['repair_date'=>'date','next_service_date'=>'date']; }
