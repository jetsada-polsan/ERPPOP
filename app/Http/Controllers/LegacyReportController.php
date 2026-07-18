<?php

namespace App\Http\Controllers;

use DOMDocument;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

class LegacyReportController extends Controller
{
    public function index(Request $request): View
    {
        $reports = collect($this->loadReports());
        $q = trim((string) $request->query('q', ''));
        $category = (string) $request->query('category', 'all');
        $status = (string) $request->query('status', 'enabled');
        $perPage = (int) $request->query('per_page', 50);
        if (! in_array($perPage, [25, 50, 100, 200], true)) {
            $perPage = 50;
        }

        $categories = $this->categories();
        $counts = ['all' => $reports->count()];
        foreach ($categories as $key => $meta) {
            $counts[$key] = $reports->where('category', $key)->count();
        }

        $filtered = $reports
            ->when($status === 'enabled', fn ($items) => $items->where('enabled', true))
            ->when($status === 'disabled', fn ($items) => $items->where('enabled', false))
            ->when($category !== 'all', fn ($items) => $items->where('category', $category))
            ->when($q !== '', fn ($items) => $items->filter(function ($report) use ($q) {
                $haystack = $report['code'].' '.$report['name'].' '.$report['rpt_file'].' '.$report['sql'];

                return stripos($haystack, $q) !== false;
            }))
            ->values();

        $page = LengthAwarePaginator::resolveCurrentPage();
        $rows = new LengthAwarePaginator(
            $filtered->forPage($page, $perPage)->values(),
            $filtered->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return view('legacy-reports.index', [
            'rows' => $rows,
            'q' => $q,
            'selectedCategory' => $category,
            'selectedStatus' => $status,
            'perPage' => $perPage,
            'categories' => $categories,
            'counts' => $counts,
            'sourcePath' => $this->reportFilePath(),
            'totalReports' => $reports->count(),
            'enabledReports' => $reports->where('enabled', true)->count(),
            'sqlReports' => $reports->filter(fn ($report) => $report['has_sql'])->count(),
        ]);
    }

    private function loadReports(): array
    {
        $path = $this->reportFilePath();
        if (! is_file($path)) {
            return [];
        }

        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTMLFile($path);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $reports = [];
        foreach ($dom->getElementsByTagName('tr') as $row) {
            $cells = $row->getElementsByTagName('td');
            if ($cells->length < 32) {
                continue;
            }

            $value = fn (int $index) => trim(html_entity_decode($cells->item($index)?->textContent ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $code = $value(7);
            $name = $value(8);
            if ($code === '-' && $name === '-') {
                continue;
            }

            $sql = $value(10);
            $reports[] = [
                'key' => $value(0),
                'group' => $value(1),
                'abs_index' => $value(2),
                'enabled' => strtoupper($value(6)) === 'Y',
                'code' => $code,
                'name' => $name,
                'rpt_file' => $value(9),
                'sql' => $sql,
                'has_sql' => $sql !== '',
                'orientation' => $value(14),
                'page_size' => $value(16),
                'last_update' => $value(31),
                'category' => $this->guessCategory($code, $name, $value(9), $sql),
            ];
        }

        usort($reports, fn ($a, $b) => [$a['category'], $a['group'], $a['abs_index'], $a['code']] <=> [$b['category'], $b['group'], $b['abs_index'], $b['code']]);

        return $reports;
    }

    private function reportFilePath(): string
    {
        $candidates = [
            env('LEGACY_REPORTFILE_PATH'),
            base_path('../legacy-files/REPORTFILE_202508021451.html'),
            base_path('legacy-files/REPORTFILE_202508021451.html'),
            'G:\\My Drive\\desktop\\POPSTARFROZEN.COM\\REPORTFILE_202508021451.html',
        ];

        foreach ($candidates as $path) {
            if ($path && is_file($path)) {
                return $path;
            }
        }

        return base_path('legacy-files/REPORTFILE_202508021451.html');
    }

    private function categories(): array
    {
        return [
            'pos' => ['label' => 'POS', 'icon' => 'bi-cart-check-fill', 'tone' => 'teal'],
            'sales' => ['label' => 'ขาย / ใบจอง', 'icon' => 'bi-receipt-cutoff', 'tone' => 'blue'],
            'inventory' => ['label' => 'สินค้า / คลัง', 'icon' => 'bi-box-seam-fill', 'tone' => 'cyan'],
            'purchase' => ['label' => 'ซื้อ / ผู้ขาย', 'icon' => 'bi-basket-fill', 'tone' => 'amber'],
            'finance' => ['label' => 'การเงิน / ภาษี', 'icon' => 'bi-cash-coin', 'tone' => 'green'],
            'member' => ['label' => 'สมาชิก', 'icon' => 'bi-person-vcard-fill', 'tone' => 'indigo'],
            'price' => ['label' => 'ราคา / โปรโมชัน', 'icon' => 'bi-tags-fill', 'tone' => 'orange'],
            'master' => ['label' => 'ข้อมูลตั้งต้น', 'icon' => 'bi-database-fill-gear', 'tone' => 'slate'],
            'document' => ['label' => 'เอกสารอื่น', 'icon' => 'bi-files', 'tone' => 'purple'],
            'other' => ['label' => 'อื่น ๆ', 'icon' => 'bi-grid-3x3-gap-fill', 'tone' => 'gray'],
        ];
    }

    private function guessCategory(string $code, string $name, string $file, string $sql): string
    {
        $text = $code.' '.$name.' '.$file.' '.$sql;
        $has = fn (array $needles) => collect($needles)->contains(fn ($needle) => stripos($text, $needle) !== false);

        if (str_starts_with(strtoupper($code), 'POS') || $has(['POS', 'PSH_', 'PSD_', 'เครื่อง POS'])) {
            return 'pos';
        }
        if ($has(['สมาชิก', 'MEMBER', 'MBTYPE', 'MB_'])) {
            return 'member';
        }
        if ($has(['ป้ายราคา', 'ราคา', 'นาทีทอง', 'แคมเปญ', 'โปรโมชัน', 'HOTPRICE', 'PRICETAG', 'ARPRICETAB', 'ARPLU'])) {
            return 'price';
        }
        if ($has(['ขาย', 'ใบจอง', 'ลูกหนี้', 'รับคืน', 'ใบเสร็จ', 'ARFILE', 'TRANSTKH', 'TRANSTKD', 'AROE', 'ARDETAIL'])) {
            return 'sales';
        }
        if ($has(['ซื้อ', 'ผู้ขาย', 'เจ้าหนี้', 'APFILE', 'APDETAIL', 'PURC'])) {
            return 'purchase';
        }
        if ($has(['คลัง', 'สินค้า', 'สต็อก', 'คงเหลือ', 'เคลื่อนไหว', 'โอน', 'ตรวจนับ', 'SKUMASTER', 'GOODSMASTER', 'SKUMOVE', 'WAREHOUSE'])) {
            return 'inventory';
        }
        if ($has(['บัญชี', 'ภาษี', 'ชำระ', 'เงินสด', 'ธนาคาร', 'VAT', 'WHT', 'ACCOUNTCHART', 'GL'])) {
            return 'finance';
        }
        if ($has(['รายชื่อ', 'รหัส', 'ประเภท', 'หน่วย', 'หมวด', 'ยี่ห้อ', 'BRAND', 'ICCAT', 'UOFQTY'])) {
            return 'master';
        }
        if ($has(['เอกสาร', 'ใบ'])) {
            return 'document';
        }

        return 'other';
    }
}

