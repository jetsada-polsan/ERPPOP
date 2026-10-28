<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\PosTerminal;
use App\Services\Pos\PosAdminOverview;
use App\Support\PythonPosInstaller;
use Illuminate\View\View;

/**
 * หน้าเดียวสำหรับดูแลเครื่อง POS: ความพร้อมรายสาขา, เพิ่มเครื่อง, สถานะ sync, PIN คนขาย และฮาร์ดแวร์
 * ปุ่มต่าง ๆ ส่งไป action เดิมใน SystemSettingController ซึ่งพากลับมาหน้านี้
 */
class PosAdminController extends Controller
{
    public function index(PosAdminOverview $overview): View
    {
        return view('pos-admin.index', [
            'branches' => $overview->branches(),
            'devices' => $overview->devices(),
            'issues' => $overview->issues(),
            'overview' => $overview,
            'terminals' => PosTerminal::with('branch:id,code,name_th')->orderBy('code')->get(),
            'installer' => PythonPosInstaller::current(),
            'pinMode' => AppSetting::get('pos_passwordless_login') === '1' ? 'selection' : 'pin',
            'onlineMinutes' => PosAdminOverview::ONLINE_MINUTES,
        ]);
    }
}
