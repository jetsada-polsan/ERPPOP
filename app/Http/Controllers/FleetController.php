<?php
namespace App\Http\Controllers;
use App\Models\Branch;
use App\Models\FleetRepair;
use App\Models\FleetTrip;
use App\Models\FleetVehicle;
use App\Models\SaleBooking;
use App\Models\TransportJob;
use App\Models\TransportLoadItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Support\Carbon;
use App\Services\Sales\CustomerPaymentService;

class FleetController extends Controller
{
    public function board(): View
    {
        SaleBooking::where('fulfillment_type','delivery')->whereNotIn('delivery_status',['cancelled'])->each(fn($b)=>TransportJob::firstOrCreate(['booking_id'=>$b->id]));
        return view('fleet.board', ['jobs'=>TransportJob::with(['booking.document.customer','vehicle'])->whereHas('booking', fn($q)=>$q->where('fulfillment_type','delivery'))->latest()->get(), 'vehicles'=>FleetVehicle::where('status','active')->orderBy('registration')->get()]);
    }
    public function driver(): View
    {
        SaleBooking::where('fulfillment_type','delivery')->whereNotIn('delivery_status',['cancelled'])->each(fn($b)=>TransportJob::firstOrCreate(['booking_id'=>$b->id]));
        return view('fleet.driver', ['jobs'=>TransportJob::with(['booking.document.customer','vehicle'])->whereIn('status',['booked','loaded','in_transit','delivered'])->latest()->get()]);
    }
    public function loadSheet(SaleBooking $booking): View
    {
        abort_unless($booking->isDelivery(),404);
        $job=TransportJob::firstOrCreate(['booking_id'=>$booking->id]);
        $items=$booking->document->stockDocument?->items()->with('product')->get() ?? collect();
        foreach($items as $item) TransportLoadItem::firstOrCreate(['transport_job_id'=>$job->id,'stock_document_item_id'=>$item->id],['planned_qty'=>$item->qty]);
        return view('fleet.load-sheet',['job'=>$job->load('booking.document.customer','loadItems.stockItem.product'),'items'=>$job->loadItems()->with('stockItem.product')->get()]);
    }
    public function saveLoadSheet(Request $request, SaleBooking $booking): RedirectResponse
    {
        abort_unless($booking->isDelivery(),404); $job=TransportJob::firstOrCreate(['booking_id'=>$booking->id]);
        $data=$request->validate(['items'=>'required|array','items.*.loaded_qty'=>'required|numeric|min:0','items.*.availability'=>'required|in:available,unavailable,partial','items.*.note'=>'nullable|max:500']);
        foreach($data['items'] as $id=>$row) { $line=$job->loadItems()->whereKey($id)->firstOrFail(); abort_if($row['loaded_qty']>$line->planned_qty,422,'จำนวนขึ้นรถเกินจำนวนในใบจอง'); $line->update($row+['updated_by'=>auth()->id()]); }
        return redirect()->route('fleet.load-sheet',$booking)->with('success','บันทึกใบขึ้นของแล้ว สามารถพิมพ์ใบส่งของได้');
    }
    public function updateBoard(Request $request, SaleBooking $booking, CustomerPaymentService $payments): RedirectResponse
    {
        $data=$request->validate(['status'=>'required|in:booked,loaded,in_transit,delivered,paid,cancelled','vehicle_id'=>'nullable|exists:fleet_vehicles,id','payment_method'=>['nullable','in:cash,transfer','required_if:status,paid'],'paid_amount'=>['nullable','numeric','min:0','required_if:status,paid'],'transfer_last4'=>['nullable','digits:4','required_if:payment_method,transfer'],'note'=>'nullable|max:1000']);
        $job=TransportJob::firstOrCreate(['booking_id'=>$booking->id]);
        if ($data['status']==='paid' && ! $job->payment_document_id) {
            $document=$booking->confirmedDocument; abort_unless($document,422,'ใบจองต้องแปลงเป็นใบขายก่อนรับเงิน'); $open=$document->openItem; abort_unless($open,422,'ไม่พบยอดลูกหนี้ของใบขายนี้');
            try { $receipt=$payments->create(['customer_id'=>$document->customer_id,'branch_id'=>$document->branch_id,'method'=>$data['payment_method'],'allocations'=>[['customer_open_item_id'=>$open->id,'amount'=>$data['paid_amount']]]]); } catch (\RuntimeException $e) { return back()->with('error',$e->getMessage()); }
            $data['payment_document_id']=$receipt->id; $data['paid_at']=now();
        }
        $job->fill($data+['updated_by'=>auth()->id()]);
        if ($data['status']==='loaded' && ! $job->loaded_at) $job->loaded_at=now(); if ($data['status']==='delivered') { $job->delivered_at=now(); $booking->update(['delivery_status'=>'delivered','delivered_at'=>$job->delivered_at]); } if ($data['status']==='paid') $job->paid_at=now(); $job->save();
        return back()->with('success','อัปเดตสถานะขนส่งแล้ว');
    }
    public function collectPayment(Request $request, SaleBooking $booking, CustomerPaymentService $payments): RedirectResponse
    {
        $data=$request->validate(['method'=>'required|in:cash,transfer','amount'=>'required|numeric|min:0.01','transfer_last4'=>['nullable','digits:4','required_if:method,transfer']]);
        $document=$booking->confirmedDocument; abort_unless($document,422,'ใบจองต้องแปลงเป็นใบขายก่อนรับเงิน');
        $open=$document->openItem; abort_unless($open,422,'ไม่พบยอดลูกหนี้ของใบขายนี้');
        try { $receipt=$payments->create(['customer_id'=>$document->customer_id,'branch_id'=>$document->branch_id,'method'=>$data['method'],'allocations'=>[['customer_open_item_id'=>$open->id,'amount'=>$data['amount']]]]); } catch (\RuntimeException $e) { return back()->with('error',$e->getMessage()); }
        $job=TransportJob::firstOrCreate(['booking_id'=>$booking->id]); $job->update(['status'=>'paid','paid_at'=>now(),'payment_method'=>$data['method'],'paid_amount'=>$data['amount'],'transfer_last4'=>$data['transfer_last4']??null,'payment_document_id'=>$receipt->id,'updated_by'=>auth()->id()]);
        return back()->with('success',"รับเงินและตัดลูกหนี้แล้ว {$receipt->doc_number}");
    }
    public function index(): View { return view('fleet.index', ['vehicles'=>FleetVehicle::with('branch')->latest()->get(), 'branches'=>Branch::where('is_active',true)->orderBy('code')->get(), 'trips'=>FleetTrip::with('vehicle')->latest('trip_date')->limit(20)->get(), 'repairs'=>FleetRepair::with('vehicle')->latest('repair_date')->limit(20)->get()]); }
    public function report(Request $request): View
    {
        $data=$request->validate(['from'=>'nullable|date','to'=>'nullable|date|after_or_equal:from','vehicle_id'=>'nullable|exists:fleet_vehicles,id']);
        $from=Carbon::parse($data['from']??now()->startOfMonth())->startOfDay(); $to=Carbon::parse($data['to']??now())->endOfDay();
        $vehicles=FleetVehicle::with(['branch','trips'=>fn($q)=>$q->whereBetween('trip_date',[$from->toDateString(),$to->toDateString()]),'repairs'=>fn($q)=>$q->whereBetween('repair_date',[$from->toDateString(),$to->toDateString()])])->when($data['vehicle_id']??null,fn($q,$id)=>$q->whereKey($id))->orderBy('registration')->get();
        $summary=['distance'=>$vehicles->sum(fn($v)=>$v->trips->sum(fn($t)=>$t->odometer_end-$t->odometer_start)),'fuel_liters'=>$vehicles->sum(fn($v)=>$v->trips->sum('fuel_liters')),'fuel_cost'=>$vehicles->sum(fn($v)=>$v->trips->sum('fuel_cost')),'repair_cost'=>$vehicles->sum(fn($v)=>$v->repairs->sum('cost'))];
        return view('fleet.report',compact('vehicles','summary','from','to'));
    }
    public function vehicle(Request $request): RedirectResponse { $data=$request->validate(['branch_id'=>'nullable|exists:branches,id','code'=>'required|max:30|unique:fleet_vehicles,code','registration'=>'required|max:30|unique:fleet_vehicles,registration','vehicle_type'=>'required|max:80','brand'=>'nullable|max:80','model'=>'nullable|max:80','current_odometer'=>'required|integer|min:0']); FleetVehicle::create($data+['status'=>'active']); return back()->with('success','เพิ่มรถเข้าทะเบียนแล้ว'); }
    public function trip(Request $request): RedirectResponse { $data=$request->validate(['vehicle_id'=>'required|exists:fleet_vehicles,id','trip_date'=>'required|date','odometer_start'=>'required|integer|min:0','odometer_end'=>'required|integer|gte:odometer_start','route'=>'required|max:255','fuel_liters'=>'nullable|numeric|min:0','fuel_cost'=>'nullable|numeric|min:0']); $vehicle=FleetVehicle::findOrFail($data['vehicle_id']); abort_if($data['odometer_start']<$vehicle->current_odometer,422,'เลขไมล์เริ่มต้นน้อยกว่าเลขไมล์ปัจจุบัน'); $data['created_by']=auth()->id(); FleetTrip::create($data); $vehicle->update(['current_odometer'=>$data['odometer_end']]); return back()->with('success','บันทึกการวิ่งแล้ว'); }
    public function repair(Request $request): RedirectResponse { $data=$request->validate(['vehicle_id'=>'required|exists:fleet_vehicles,id','repair_date'=>'required|date','odometer'=>'required|integer|min:0','summary'=>'required|max:255','vendor'=>'nullable|max:150','cost'=>'nullable|numeric|min:0','next_service_date'=>'nullable|date']); $vehicle=FleetVehicle::findOrFail($data['vehicle_id']); abort_if($data['odometer']<$vehicle->current_odometer,422,'เลขไมล์ตอนซ่อมน้อยกว่าเลขไมล์ปัจจุบัน'); $data['created_by']=auth()->id(); FleetRepair::create($data); return back()->with('success','บันทึกประวัติซ่อมแล้ว'); }
}
