<?php
namespace App\Http\Controllers;
use App\Models\Branch;
use App\Models\FleetRepair;
use App\Models\FleetTrip;
use App\Models\FleetVehicle;
use App\Models\SaleBooking;
use App\Models\TransportJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FleetController extends Controller
{
    public function board(): View
    {
        SaleBooking::where('fulfillment_type','delivery')->whereNotIn('delivery_status',['cancelled'])->each(fn($b)=>TransportJob::firstOrCreate(['booking_id'=>$b->id]));
        return view('fleet.board', ['jobs'=>TransportJob::with(['booking.document.customer','vehicle'])->whereHas('booking', fn($q)=>$q->where('fulfillment_type','delivery'))->latest()->get(), 'vehicles'=>FleetVehicle::where('status','active')->orderBy('registration')->get()]);
    }
    public function updateBoard(Request $request, SaleBooking $booking): RedirectResponse
    {
        $data=$request->validate(['status'=>'required|in:booked,loaded,in_transit,delivered,paid,cancelled','vehicle_id'=>'nullable|exists:fleet_vehicles,id','payment_method'=>['nullable','in:cash,transfer','required_if:status,paid'],'paid_amount'=>['nullable','numeric','min:0','required_if:status,paid'],'transfer_last4'=>['nullable','digits:4','required_if:payment_method,transfer'],'note'=>'nullable|max:1000']);
        $job=TransportJob::firstOrCreate(['booking_id'=>$booking->id]); $job->fill($data+['updated_by'=>auth()->id()]);
        if ($data['status']==='loaded' && ! $job->loaded_at) $job->loaded_at=now(); if ($data['status']==='delivered') { $job->delivered_at=now(); $booking->update(['delivery_status'=>'delivered','delivered_at'=>$job->delivered_at]); } if ($data['status']==='paid') $job->paid_at=now(); $job->save();
        return back()->with('success','อัปเดตสถานะขนส่งแล้ว');
    }
    public function index(): View { return view('fleet.index', ['vehicles'=>FleetVehicle::with('branch')->latest()->get(), 'branches'=>Branch::where('is_active',true)->orderBy('code')->get(), 'trips'=>FleetTrip::with('vehicle')->latest('trip_date')->limit(20)->get(), 'repairs'=>FleetRepair::with('vehicle')->latest('repair_date')->limit(20)->get()]); }
    public function vehicle(Request $request): RedirectResponse { $data=$request->validate(['branch_id'=>'nullable|exists:branches,id','code'=>'required|max:30|unique:fleet_vehicles,code','registration'=>'required|max:30|unique:fleet_vehicles,registration','vehicle_type'=>'required|max:80','brand'=>'nullable|max:80','model'=>'nullable|max:80','current_odometer'=>'required|integer|min:0']); FleetVehicle::create($data+['status'=>'active']); return back()->with('success','เพิ่มรถเข้าทะเบียนแล้ว'); }
    public function trip(Request $request): RedirectResponse { $data=$request->validate(['vehicle_id'=>'required|exists:fleet_vehicles,id','trip_date'=>'required|date','odometer_start'=>'required|integer|min:0','odometer_end'=>'required|integer|gte:odometer_start','route'=>'required|max:255','fuel_liters'=>'nullable|numeric|min:0','fuel_cost'=>'nullable|numeric|min:0']); $data['created_by']=auth()->id(); FleetTrip::create($data); FleetVehicle::whereKey($data['vehicle_id'])->update(['current_odometer'=>$data['odometer_end']]); return back()->with('success','บันทึกการวิ่งแล้ว'); }
    public function repair(Request $request): RedirectResponse { $data=$request->validate(['vehicle_id'=>'required|exists:fleet_vehicles,id','repair_date'=>'required|date','odometer'=>'required|integer|min:0','summary'=>'required|max:255','vendor'=>'nullable|max:150','cost'=>'nullable|numeric|min:0','next_service_date'=>'nullable|date']); $data['created_by']=auth()->id(); FleetRepair::create($data); return back()->with('success','บันทึกประวัติซ่อมแล้ว'); }
}
