<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
#[Fillable(['vehicle_id','driver_id','trip_date','odometer_start','odometer_end','route','fuel_liters','fuel_cost','note','created_by'])]
class FleetTrip extends Model { protected $casts=['trip_date'=>'date']; }
