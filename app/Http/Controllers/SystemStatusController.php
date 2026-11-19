<?php

namespace App\Http\Controllers;

use App\Services\SystemStatusService;
use Illuminate\View\View;

class SystemStatusController extends Controller
{
    public function index(SystemStatusService $status): View
    {
        return view('system.status', [
            'cards' => $status->snapshot(),
            'checkedAt' => now(),
        ]);
    }
}
