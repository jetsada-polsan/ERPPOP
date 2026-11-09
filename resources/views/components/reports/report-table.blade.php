@props(['columns', 'rows', 'empty' => 'ไม่พบข้อมูล'])
@include('reports.partials.result-table', compact('columns', 'rows', 'empty'))
