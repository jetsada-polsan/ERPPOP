<?php

namespace App\Http\Controllers;

use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    // กระดิ่งแจ้งเตือน header: รายการตามภาระหน้าที่ (permission) ของผู้ใช้ที่ล็อกอิน
    public function index(NotificationService $service): JsonResponse
    {
        $items = $service->forUser(Auth::user());

        return response()->json([
            'items' => $items,
            'total' => array_sum(array_column($items, 'count')),
        ]);
    }
}
