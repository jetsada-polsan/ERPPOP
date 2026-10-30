<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\FleetRepair;
use App\Models\FleetTrip;
use App\Models\FleetVehicle;
use App\Models\SaleBooking;
use App\Models\TransportJob;
use App\Models\TransportLoadItem;
use App\Services\Sales\BookingDeliveryService;
use App\Services\Sales\BookingLoadSheetValidator;
use App\Services\Sales\CustomerPaymentService;
use App\Support\DecimalMath;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;

class FleetController extends Controller
{
    public function board(): View
    {
        SaleBooking::where('fulfillment_type', 'delivery')->whereNotIn('delivery_status', ['cancelled'])->each(fn ($b) => TransportJob::firstOrCreate(['booking_id' => $b->id]));

        return view('fleet.board', ['jobs' => TransportJob::with(['booking.document.customer', 'vehicle'])->whereHas('booking', fn ($q) => $q->where('fulfillment_type', 'delivery'))->latest()->get(), 'vehicles' => FleetVehicle::where('status', 'active')->orderBy('registration')->get()]);
    }

    public function driver(): View
    {
        SaleBooking::where('fulfillment_type', 'delivery')->whereNotIn('delivery_status', ['cancelled'])->each(fn ($b) => TransportJob::firstOrCreate(['booking_id' => $b->id]));

        return view('fleet.driver', ['jobs' => TransportJob::with(['booking.document.customer', 'vehicle'])->whereIn('status', ['booked', 'loaded', 'in_transit', 'delivered'])->latest()->get()]);
    }

    public function loadSheet(SaleBooking $booking): View
    {
        abort_unless($booking->isDelivery(), 404);

        $job = DB::transaction(function () use ($booking) {
            $lockedBooking = SaleBooking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $job = TransportJob::firstOrCreate(['booking_id' => $lockedBooking->id]);
            $items = $lockedBooking->document->stockDocument?->items()->with('product')->get() ?? collect();
            foreach ($items as $item) {
                TransportLoadItem::firstOrCreate(
                    ['transport_job_id' => $job->id, 'stock_document_item_id' => $item->id],
                    ['planned_qty' => $item->qty],
                );
            }

            return $job;
        });

        $job->load('booking.document.customer', 'loadItems.stockItem.product');

        return view('fleet.load-sheet', [
            'job' => $job,
            'items' => $job->loadItems()->with('stockItem.product')->get(),
            'editable' => $job->status === 'booked' && $job->loaded_at === null,
        ]);
    }

    public function saveLoadSheet(Request $request, SaleBooking $booking): RedirectResponse
    {
        abort_unless($booking->isDelivery(), 404);

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.loaded_qty' => ['required', 'numeric', 'min:0'],
            'items.*.availability' => ['required', 'in:available,unavailable,partial'],
            'items.*.note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            DB::transaction(function () use ($booking, $data) {
                $lockedBooking = SaleBooking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
                if ($lockedBooking->status !== SaleBooking::STATUS_PENDING
                    || $lockedBooking->delivery_status !== SaleBooking::DELIVERY_PENDING) {
                    throw new RuntimeException('ใบจองเริ่มส่งหรือถูกใช้แล้ว จึงแก้ใบขึ้นของไม่ได้');
                }
                $job = TransportJob::where('booking_id', $booking->id)->lockForUpdate()->firstOrFail();
                if ($job->status !== 'booked' || $job->loaded_at !== null) {
                    throw new RuntimeException('รถออกจากคลังแล้ว จึงแก้ใบขึ้นของไม่ได้');
                }

                $lines = $job->loadItems()->orderBy('id')->lockForUpdate()->get();
                $submittedIds = collect(array_keys($data['items']))->map(fn ($id) => (int) $id)->sort()->values();
                $lineIds = $lines->pluck('id')->map(fn ($id) => (int) $id)->sort()->values();
                if ($lines->isEmpty() || $submittedIds->all() !== $lineIds->all()) {
                    throw new RuntimeException('รายการใบขึ้นของไม่ตรงกับใบจอง กรุณาโหลดหน้าใหม่');
                }

                foreach ($lines as $line) {
                    $row = $data['items'][$line->id];
                    $quantity = (string) $row['loaded_qty'];
                    $comparison = DecimalMath::compare($quantity, $line->planned_qty);
                    if ($comparison > 0) {
                        throw new RuntimeException('จำนวนขึ้นรถเกินจำนวนในใบจอง');
                    }

                    if ($row['availability'] === 'available' && $comparison !== 0) {
                        throw new RuntimeException('สินค้าที่เลือกว่ามีครบ ต้องขึ้นรถให้ครบตามจำนวนใบจอง');
                    }
                    if ($row['availability'] === 'unavailable' && DecimalMath::compare($quantity, 0) !== 0) {
                        throw new RuntimeException('สินค้าที่ไม่มีต้องมีจำนวนขึ้นรถเป็นศูนย์');
                    }
                    if ($row['availability'] === 'partial'
                        && (DecimalMath::compare($quantity, 0) <= 0 || $comparison >= 0)) {
                        throw new RuntimeException('สินค้าที่มีบางส่วนต้องระบุจำนวนมากกว่าศูนย์และน้อยกว่าจำนวนจอง');
                    }

                    $line->update([
                        'loaded_qty' => $quantity,
                        'availability' => $row['availability'],
                        'note' => $row['note'] ?? null,
                        'updated_by' => auth()->id(),
                    ]);
                }
            });
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()->route('fleet.load-sheet', $booking)
            ->with('success', 'บันทึกใบขึ้นของแล้ว หากสินค้าขาดให้แก้ใบจองก่อนยืนยันขึ้นรถ');
    }

    public function updateBoard(Request $request, SaleBooking $booking, CustomerPaymentService $payments, BookingDeliveryService $deliveries, BookingLoadSheetValidator $loadSheets): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:booked,loaded,in_transit,delivered,paid,cancelled'],
            'vehicle_id' => ['nullable', 'exists:fleet_vehicles,id'],
            'payment_method' => ['nullable', 'in:cash,transfer', 'required_if:status,paid'],
            'paid_amount' => ['nullable', 'numeric', 'min:0', 'required_if:status,paid'],
            'transfer_last4' => ['nullable', 'digits:4', 'required_if:payment_method,transfer'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            DB::transaction(function () use ($booking, $data, $payments, $deliveries, $loadSheets) {
                $booking = SaleBooking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
                $job = TransportJob::firstOrCreate(['booking_id' => $booking->id]);
                $job = TransportJob::whereKey($job->id)->lockForUpdate()->firstOrFail();
                $allowed = [
                    'booked' => ['booked', 'cancelled'],
                    'loaded' => ['booked'],
                    'in_transit' => ['loaded'],
                    'delivered' => ['in_transit'],
                    'paid' => ['delivered'],
                    'cancelled' => ['booked', 'loaded'],
                ];
                abort_unless(in_array($job->status, $allowed[$data['status']] ?? [], true), 422, 'ไม่สามารถข้ามลำดับสถานะขนส่งได้');

                if ($data['status'] === 'loaded') {
                    abort_unless(
                        $loadSheets->isComplete($booking, $job),
                        422,
                        'จำนวนขึ้นรถไม่ครบตามใบจอง กรุณาแก้ใบจองตามของที่มี แล้วบันทึกใบขึ้นของใหม่',
                    );
                }

                if ($data['status'] === 'delivered') {
                    abort_unless($job->loaded_at !== null, 422, 'ต้องยืนยันขึ้นรถก่อนบันทึกว่าส่งครบแล้ว');
                }

                if ($data['status'] === 'paid' && ! $job->payment_document_id) {
                    $document = $booking->confirmedDocument;
                    abort_unless($document, 422, 'ต้องออกใบขายหลังส่งครบก่อนรับเงิน');
                    $open = $document->openItem;
                    abort_unless($open, 422, 'ไม่พบยอดลูกหนี้ของใบขายนี้');
                    $receipt = $payments->create([
                        'customer_id' => $document->customer_id,
                        'branch_id' => $document->branch_id,
                        'method' => $data['payment_method'],
                        'allocations' => [['customer_open_item_id' => $open->id, 'amount' => $data['paid_amount']]],
                    ]);
                    $data['payment_document_id'] = $receipt->id;
                    $data['paid_at'] = now();
                }

                $job->fill($data + ['updated_by' => auth()->id()]);
                if ($data['status'] === 'loaded' && ! $job->loaded_at) {
                    $job->loaded_at = now();
                }
                if ($data['status'] === 'delivered') {
                    $job->delivered_at = now();
                }
                if ($data['status'] === 'paid') {
                    $job->paid_at = now();
                }
                $job->save();

                if ($data['status'] === 'delivered') {
                    $deliveries->record($booking, SaleBooking::DELIVERY_DELIVERED, 'ยืนยันส่งครบจากบอร์ดขนส่ง');
                }
            });
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'อัปเดตสถานะขนส่งแล้ว');
    }

    public function collectPayment(Request $request, SaleBooking $booking, CustomerPaymentService $payments): RedirectResponse
    {
        $data = $request->validate(['method' => 'required|in:cash,transfer', 'amount' => 'required|numeric|min:0.01', 'transfer_last4' => ['nullable', 'digits:4', 'required_if:method,transfer']]);
        $document = $booking->confirmedDocument;
        abort_unless($document, 422, 'ใบจองต้องแปลงเป็นใบขายก่อนรับเงิน');
        $open = $document->openItem;
        abort_unless($open, 422, 'ไม่พบยอดลูกหนี้ของใบขายนี้');
        try {
            $receipt = $payments->create(['customer_id' => $document->customer_id, 'branch_id' => $document->branch_id, 'method' => $data['method'], 'allocations' => [['customer_open_item_id' => $open->id, 'amount' => $data['amount']]]]);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        $job = TransportJob::firstOrCreate(['booking_id' => $booking->id]);
        $job->update(['status' => 'paid', 'paid_at' => now(), 'payment_method' => $data['method'], 'paid_amount' => $data['amount'], 'transfer_last4' => $data['transfer_last4'] ?? null, 'payment_document_id' => $receipt->id, 'updated_by' => auth()->id()]);

        return back()->with('success', "รับเงินและตัดลูกหนี้แล้ว {$receipt->doc_number}");
    }

    public function index(): View
    {
        return view('fleet.index', ['vehicles' => FleetVehicle::with('branch')->latest()->get(), 'branches' => Branch::where('is_active', true)->orderBy('code')->get(), 'trips' => FleetTrip::with('vehicle')->latest('trip_date')->limit(20)->get(), 'repairs' => FleetRepair::with('vehicle')->latest('repair_date')->limit(20)->get()]);
    }

    public function report(Request $request): View
    {
        $data = $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from', 'vehicle_id' => 'nullable|exists:fleet_vehicles,id']);
        $from = Carbon::parse($data['from'] ?? now()->startOfMonth())->startOfDay();
        $to = Carbon::parse($data['to'] ?? now())->endOfDay();
        $vehicles = FleetVehicle::with(['branch', 'trips' => fn ($q) => $q->whereBetween('trip_date', [$from->toDateString(), $to->toDateString()]), 'repairs' => fn ($q) => $q->whereBetween('repair_date', [$from->toDateString(), $to->toDateString()])])->when($data['vehicle_id'] ?? null, fn ($q, $id) => $q->whereKey($id))->orderBy('registration')->get();
        $summary = ['distance' => $vehicles->sum(fn ($v) => $v->trips->sum(fn ($t) => $t->odometer_end - $t->odometer_start)), 'fuel_liters' => $vehicles->sum(fn ($v) => $v->trips->sum('fuel_liters')), 'fuel_cost' => $vehicles->sum(fn ($v) => $v->trips->sum('fuel_cost')), 'repair_cost' => $vehicles->sum(fn ($v) => $v->repairs->sum('cost'))];

        return view('fleet.report', compact('vehicles', 'summary', 'from', 'to'));
    }

    public function vehicle(Request $request): RedirectResponse
    {
        $data = $request->validate(['branch_id' => 'nullable|exists:branches,id', 'code' => 'required|max:30|unique:fleet_vehicles,code', 'registration' => 'required|max:30|unique:fleet_vehicles,registration', 'vehicle_type' => 'required|max:80', 'brand' => 'nullable|max:80', 'model' => 'nullable|max:80', 'current_odometer' => 'required|integer|min:0']);
        FleetVehicle::create($data + ['status' => 'active']);

        return back()->with('success', 'เพิ่มรถเข้าทะเบียนแล้ว');
    }

    public function trip(Request $request): RedirectResponse
    {
        $data = $request->validate(['vehicle_id' => 'required|exists:fleet_vehicles,id', 'trip_date' => 'required|date', 'odometer_start' => 'required|integer|min:0', 'odometer_end' => 'required|integer|gte:odometer_start', 'route' => 'required|max:255', 'fuel_liters' => 'nullable|numeric|min:0', 'fuel_cost' => 'nullable|numeric|min:0']);
        $vehicle = FleetVehicle::findOrFail($data['vehicle_id']);
        abort_if($data['odometer_start'] < $vehicle->current_odometer, 422, 'เลขไมล์เริ่มต้นน้อยกว่าเลขไมล์ปัจจุบัน');
        $data['created_by'] = auth()->id();
        FleetTrip::create($data);
        $vehicle->update(['current_odometer' => $data['odometer_end']]);

        return back()->with('success', 'บันทึกการวิ่งแล้ว');
    }

    public function repair(Request $request): RedirectResponse
    {
        $data = $request->validate(['vehicle_id' => 'required|exists:fleet_vehicles,id', 'repair_date' => 'required|date', 'odometer' => 'required|integer|min:0', 'summary' => 'required|max:255', 'vendor' => 'nullable|max:150', 'cost' => 'nullable|numeric|min:0', 'next_service_date' => 'nullable|date']);
        $vehicle = FleetVehicle::findOrFail($data['vehicle_id']);
        abort_if($data['odometer'] < $vehicle->current_odometer, 422, 'เลขไมล์ตอนซ่อมน้อยกว่าเลขไมล์ปัจจุบัน');
        $data['created_by'] = auth()->id();
        FleetRepair::create($data);

        return back()->with('success', 'บันทึกประวัติซ่อมแล้ว');
    }
}
