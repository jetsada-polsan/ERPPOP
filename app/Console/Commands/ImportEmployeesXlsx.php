<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeOrgAssignment;
use App\Models\OrganizationalUnit;
use DOMDocument;
use DOMXPath;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use ZipArchive;

class ImportEmployeesXlsx extends Command
{
    protected $signature = 'employees:import-xlsx {file} {--sheet=ERP_Import_Active}';
    protected $description = 'นำเข้าแฟ้มพนักงานจาก Excel ที่ผ่านการกรองแล้ว';

    public function handle(): int
    {
        $file = (string) $this->argument('file');
        if (! is_file($file)) { $this->error("ไม่พบไฟล์ {$file}"); return self::FAILURE; }

        $rows = $this->readSheet($file, (string) $this->option('sheet'));
        if (count($rows) < 2) { $this->error('ไม่พบข้อมูลในชีต'); return self::FAILURE; }
        $headers = array_map(fn ($v) => trim((string) $v), array_shift($rows));
        $branches = Branch::all(['id','code','name_th']);
        $orgUnits = Schema::hasTable('organizational_units') ? OrganizationalUnit::pluck('id','name') : collect();
        $imported = 0;

        DB::transaction(function () use ($rows, $headers, $branches, $orgUnits, &$imported): void {
            foreach ($rows as $row) {
                $record = array_combine($headers, array_pad(array_slice($row, 0, count($headers)), count($headers), null));
                $code = trim((string) ($record['employee_code'] ?? ''));
                $name = trim((string) ($record['full_name'] ?? ''));
                if ($code === '' || $name === '') continue;
                $branchText = trim((string) ($record['branch'] ?: $record['source_section'] ?? ''));
                $branchId = $this->matchBranch($branchText, $branches);
                $status = trim((string) ($record['status'] ?? '')) ?: 'Active';
                $department = $this->blank($record['department'] ?? null);
                $position = $this->blank($record['position'] ?? null);
                $forceSingleOrg = false;
                $forceUnitManager = false;
                if ($department === 'แอดมิน') {
                    $department = 'ฝ่ายขาย';
                    $position = 'แอดมินขาย';
                } elseif ($department === 'ฝ่ายขาย-เซลล์') {
                    $department = 'ฝ่ายขาย';
                }
                if (in_array($department, ['ผู้บริหาร/กรรมการ', 'ผู้บริหาร / กรรมการ'], true)) {
                    $branchId = $branches->firstWhere('code', 'HO')?->id;
                    $branchText = 'สำนักงานใหญ่';
                }
                if ($code === 'EMP0075') {
                    $branchId = $branches->firstWhere('code', 'HO')?->id;
                    $branchText = 'สำนักงานใหญ่';
                    $department = 'สำนักงานผู้จัดการทั่วไป';
                    $position = 'ผู้จัดการทั่วไป';
                    $forceSingleOrg = true;
                    $forceUnitManager = true;
                }
                if ($code === 'EMP0035') {
                    $branchId = $branches->firstWhere('code', 'HO')?->id;
                    $branchText = 'สำนักงานใหญ่';
                    $department = 'ฝ่ายขาย';
                    $position = 'ผู้จัดการฝ่ายขาย';
                    $forceSingleOrg = true;
                    $forceUnitManager = true;
                }
                if ($code === 'EMP0089') {
                    $department = 'ปฏิบัติการหน้าร้าน';
                    $position = 'Area Manager (ทุกสาขา)';
                    $forceSingleOrg = true;
                }
                if ($code === 'EMP0020') {
                    $branchId = $branches->firstWhere('code', 'HO')?->id;
                    $branchText = 'สำนักงานใหญ่';
                    $department = 'จัดซื้อและควบคุมสต็อก';
                    $position = 'ผู้จัดการฝ่ายจัดซื้อและควบคุมสต็อก';
                    $forceSingleOrg = true;
                    $forceUnitManager = true;
                }
                if ($code === 'EMP0021') {
                    $branchId = $branches->firstWhere('code', 'HO')?->id;
                    $branchText = 'สำนักงานใหญ่';
                    $department = 'จัดซื้อและควบคุมสต็อก';
                    $position = 'เจ้าหน้าที่จัดซื้อและควบคุมสต็อก';
                    $forceSingleOrg = true;
                }
                if ($code === 'EMP0058') {
                    $department = 'คลังสินค้า';
                    $position = 'ผู้จัดการคลังสินค้า';
                    $forceSingleOrg = true;
                    $forceUnitManager = true;
                }

                $employee = Employee::updateOrCreate(['employee_code' => $code], [
                    'full_name' => $name,
                    'nickname' => $this->blank($record['nickname'] ?? null),
                    'gender' => $this->blank($record['gender'] ?? null),
                    'phone' => $this->blank($record['phone'] ?? null),
                    'alt_phone' => $this->blank($record['alt_phone'] ?? null),
                    'national_id' => $this->blank($record['national_id'] ?? null),
                    'nationality' => $this->blank($record['nationality'] ?? null),
                    'birth_date_raw' => $this->blank($record['birth_date_raw'] ?? null),
                    'address' => $this->blank($record['address'] ?? null),
                    'branch_id' => $branchId,
                    'branch_text' => $this->blank($branchText),
                    'department' => $department,
                    'position' => $position,
                    'employment_type' => $this->blank($record['employment_type'] ?? null),
                    'wage_type' => $this->blank($record['wage_type'] ?? null),
                    'wage_amount' => is_numeric($record['wage_amount'] ?? null) ? $record['wage_amount'] : null,
                    'start_date_raw' => $this->blank($record['start_date_raw'] ?? null),
                    'status' => $status,
                    'remark' => $this->blank($record['remark'] ?? null),
                    'source_section' => $this->blank($record['source_section'] ?? null),
                    'source_row' => is_numeric($record['source_row'] ?? null) ? (int) $record['source_row'] : null,
                ]);
                $orgName = match ($department) {
                    'สิบล้อ' => 'ฝ่ายจัดส่ง',
                    'Oniine Marketing' => 'ฝ่ายขาย',
                    'ผู้บริหาร/กรรมการ', 'ผู้บริหาร / กรรมการ' => 'ผู้บริหาร/กรรมการ สนญ',
                    default => $department,
                };
                if ($orgName && $orgUnits->has($orgName)) {
                    if ($forceSingleOrg) EmployeeOrgAssignment::where('employee_id', $employee->id)->delete();
                    EmployeeOrgAssignment::where('employee_id', $employee->id)->update(['is_primary'=>false]);
                    EmployeeOrgAssignment::updateOrCreate(
                        ['employee_id'=>$employee->id,'organizational_unit_id'=>$orgUnits[$orgName]],
                        ['position_title'=>$position,'is_primary'=>true]
                    );
                    if ($forceUnitManager) OrganizationalUnit::whereKey($orgUnits[$orgName])->update(['manager_employee_id'=>$employee->id]);
                }
                $imported++;
            }
        });

        $this->info("นำเข้าหรืออัปเดต {$imported} คนสำเร็จ");
        $this->table(['รายการ','จำนวน'], [
            ['พนักงานทั้งหมด', Employee::count()],
            ['ผูกสาขาแล้ว', Employee::whereNotNull('branch_id')->count()],
            ['ยังไม่ผูกสาขา', Employee::whereNull('branch_id')->count()],
        ]);
        return self::SUCCESS;
    }

    private function blank(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function matchBranch(string $text, $branches): ?int
    {
        $needle = mb_strtolower(preg_replace('/\s+/', '', $text));
        if ($needle === '') return null;
        foreach ($branches as $branch) {
            $name = mb_strtolower(preg_replace('/\s+/', '', $branch->name_th));
            if ($name !== '' && (str_contains($needle, $name) || str_contains($name, str_replace(['shop','สาขา'], '', $needle)))) return $branch->id;
        }
        $branchAliases = [
            'เจริญศรี' => 'เจริญศรี',
            'บ้านปลาดุก' => 'ปลาดุก',
            'สุรินทร์' => 'สุรินทร์',
            'วาริน' => 'วาริน',
            'ดอนกลาง' => 'ดอนกลาง',
            'อำนาจเจริญ' => 'อำนาจเจริญ',
            'บัญชี' => 'สำนักงานใหญ่',
            'คลังสินค้า' => 'สำนักงานใหญ่',
            'ฝ่ายจัดส่ง' => 'สำนักงานใหญ่',
            'แอดมิน' => 'สำนักงานใหญ่',
        ];
        foreach ($branchAliases as $sourceName => $registeredName) {
            if (str_contains($needle, mb_strtolower($sourceName))) {
                $match = $branches->first(fn ($branch) => str_contains(mb_strtolower($branch->name_th), mb_strtolower($registeredName)));
                if ($match) return $match->id;
            }
        }
        return null;
    }

    /** @return array<int,array<int,mixed>> */
    private function readSheet(string $file, string $sheetName): array
    {
        $zip = new ZipArchive();
        if ($zip->open($file) !== true) throw new RuntimeException('เปิดไฟล์ Excel ไม่ได้');
        try {
            $shared = $this->sharedStrings($zip);
            $workbook = $this->xml($zip, 'xl/workbook.xml');
            $rels = $this->xml($zip, 'xl/_rels/workbook.xml.rels');
            $relMap = [];
            foreach ((new DOMXPath($rels))->query('//*[local-name()="Relationship"]') as $rel) $relMap[$rel->getAttribute('Id')] = $rel->getAttribute('Target');
            $target = null;
            foreach ((new DOMXPath($workbook))->query('//*[local-name()="sheet"]') as $sheet) {
                if ($sheet->getAttribute('name') === $sheetName) {
                    $rid = $sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
                    $target = $relMap[$rid] ?? null; break;
                }
            }
            if (! $target) throw new RuntimeException("ไม่พบชีต {$sheetName}");
            $path = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.ltrim(str_replace('..','',$target), '/');
            $sheetXml = $this->xml($zip, $path);
            $rows = [];
            foreach ((new DOMXPath($sheetXml))->query('//*[local-name()="sheetData"]/*[local-name()="row"]') as $rowNode) {
                $row = [];
                foreach ((new DOMXPath($sheetXml))->query('./*[local-name()="c"]', $rowNode) as $cell) {
                    preg_match('/([A-Z]+)\d+/', $cell->getAttribute('r'), $m);
                    $index = $this->columnIndex($m[1] ?? 'A');
                    $valueNode = (new DOMXPath($sheetXml))->query('./*[local-name()="v"]', $cell)->item(0);
                    $value = $valueNode?->textContent ?? '';
                    if ($cell->getAttribute('t') === 's') $value = $shared[(int) $value] ?? '';
                    elseif ($cell->getAttribute('t') === 'inlineStr') $value = (new DOMXPath($sheetXml))->query('.//*[local-name()="t"]', $cell)->item(0)?->textContent ?? '';
                    $row[$index] = $value;
                }
                if ($row !== []) { ksort($row); $rows[] = array_replace(array_fill(0, max(array_keys($row)) + 1, null), $row); }
            }
            return $rows;
        } finally { $zip->close(); }
    }

    private function sharedStrings(ZipArchive $zip): array
    {
        if ($zip->locateName('xl/sharedStrings.xml') === false) return [];
        $dom = $this->xml($zip, 'xl/sharedStrings.xml'); $result = []; $xpath = new DOMXPath($dom);
        foreach ($xpath->query('//*[local-name()="si"]') as $si) {
            $text = ''; foreach ($xpath->query('.//*[local-name()="t"]', $si) as $t) $text .= $t->textContent;
            $result[] = $text;
        }
        return $result;
    }

    private function xml(ZipArchive $zip, string $path): DOMDocument
    {
        $content = $zip->getFromName($path);
        if ($content === false) throw new RuntimeException("ไม่พบ {$path}");
        $dom = new DOMDocument(); $dom->loadXML($content); return $dom;
    }

    private function columnIndex(string $letters): int
    {
        $index = 0; foreach (str_split($letters) as $letter) $index = $index * 26 + ord($letter) - 64;
        return $index - 1;
    }
}
