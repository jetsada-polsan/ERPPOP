<?php
namespace App\Http\Controllers;
use App\Models\Branch;
use App\Models\FleetRepair;
use App\Models\FleetTrip;
use App\Models\FleetVehicle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FleetController extends Controller
{
    public function index(): View { return view('fleet.index', ['vehicles'=>FleetVehicle::with('branch')->latest()->get(), 'branches'=>Branch::where('is_active',true)->orderBy('code')->get(), 'trips'=>FleetTrip::with('vehicle')->latest('trip_date')->limit(20)->get(), 'repairs'=>FleetRepair::with('vehicle')->latest('repair_date')->limit(20)->get()]); }
    public function vehicle(Request $request): RedirectResponse { $data=$request->validate(['branch_id'=>'nullable|exists:branches,id','code'=>'required|max:30|unique:fleet_vehicles,code','registration'=>'required|max:30|unique:fleet_vehicles,registration','vehicle_type'=>'required|max:80','brand'=>'nullable|max:80','model'=>'nullable|max:80','current_odometer'=>'required|integer|min:0']); FleetVehicle::create($data+['status'=>'active']); return back()->with('success','เพิ่มรถเข้าทะเบียนแล้ว'); }
    public function trip(Request $request): RedirectResponse { $data=$request->validate(['vehicle_id'=>'required|exists:fleet_vehicles,id','trip_date'=>'required|date','odometer_start'=>'required|integer|min:0','odometer_end'=>'required|integer|gte:odometer_start','route'=>'required|max:255','fuel_liters'=>'nullable|numeric|min:0','fuel_cost'=>'nullable|numeric|min:0']); $data['created_by']=auth()->id(); FleetTrip::create($data); FleetVehicle::whereKey($data['vehicle_id'])->update(['current_odometer'=>$data['odometer_end']]); return back()->with('success','บันทึกการวิ่งแล้ว'); }
    public function repair(Request $request): RedirectResponse { $data=$request->validate(['vehicle_id'=>'required|exists:fleet_vehicles,id','repair_date'=>'required|date','odometer'=>'required|integer|min:0','summary'=>'required|max:255','vendor'=>'nullable|max:150','cost'=>'nullable|numeric|min:0','next_service_date'=>'nullable|date']); $data['created_by']=auth()->id(); FleetRepair::create($data); return back()->with('success','บันทึกประวัติซ่อมแล้ว'); }
}
