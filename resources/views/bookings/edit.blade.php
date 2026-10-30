@extends('layout')

@section('title', "แก้ไขใบจอง {$booking->document->doc_number} - PopCentral")
@section('page-title', 'แก้ไขใบจอง / ขายเชื่อ')
@section('page-subtitle', "แก้ไขข้อมูลใบจอง {$booking->document->doc_number} โดยคงเลขที่เอกสารเดิม")

@section('content')
<div class="doc-shell" x-data="docEntryPage({
    partyType: 'customer',
    partyLabel: 'ลูกค้า',
    partyRequired: true,
    defaultBranchId: @js($initialBooking['branch_id']),
    defaultSalesUserId: @js($initialBooking['sales_user_id']),
    defaultSalesUserName: @js($initialBooking['sales_user_name']),
    defaultSalesAreaId: @js($initialBooking['sales_area_id']),
    initialPartyId: @js($initialBooking['customer_id']),
    initialPartyQuery: @js($initialBooking['customer_query']),
    initialSalesAreaId: @js($initialBooking['sales_area_id']),
    initialSalesUserId: @js($initialBooking['sales_user_id']),
    initialItems: @js($initialBooking['items']),
    openInitially: true,
    salesUserFromParty: false,
    salesAreaFromParty: true,
    showStock: true,
    salesAreas: @js($salesAreas->map(fn ($area) => [
        'id' => $area->id,
        'area_type' => $area->area_type,
        'branch_id' => $area->branch_id,
    ])->values()),
})">
    <div class="doc-toolbar">
        <div class="doc-toolbar-title">
            <span class="doc-mark"><i class="bi bi-pencil-square"></i></span>
            <div>
                <h2 class="h5 fw-bold mb-0">แก้ไขใบจอง {{ $booking->document->doc_number }}</h2>
                <div class="text-muted small">เลขที่ใบจองจะคงเดิม การแก้ไขจะปรับยอดจองสินค้าให้ตรงกับรายการใหม่</div>
            </div>
        </div>
        <a href="{{ route('bookings.show', $booking) }}" class="btn btn-light border rounded-pill px-4">
            <i class="bi bi-arrow-left me-1"></i> กลับไปใบจอง
        </a>
    </div>

    @include('documents.partials.entry-modal', [
        'title' => 'แก้ไขใบจองสินค้า',
        'subtitle' => 'แก้ไขลูกค้า สายการขาย และรายการสินค้า — เลขที่เอกสารไม่เปลี่ยน',
        'action' => route('bookings.update', $booking),
        'formMethod' => 'PUT',
        'documentNumberLabel' => $initialBooking['number'],
        'documentDateLabel' => $initialBooking['date'],
        'initialRemark' => $initialBooking['remark'],
        'fulfillmentType' => $initialBooking['fulfillment_type'],
        'deliveryDueAt' => $initialBooking['delivery_due_at'],
        'branches' => $branches,
        'salesAreas' => $salesAreas,
        'partyType' => 'customer',
        'partyLabel' => 'ลูกค้า',
        'partyRequired' => true,
        'showSalesman' => true,
        'showSalesArea' => true,
        'salesUsers' => $salesUsers,
        'itemTitle' => 'รายการจอง',
        'submitLabel' => 'บันทึกการแก้ไข',
        'remarkPlaceholder' => 'เงื่อนไขส่งของ / หมายเหตุการจอง',
    ])
</div>
@endsection

@include('documents.partials.entry-assets')
