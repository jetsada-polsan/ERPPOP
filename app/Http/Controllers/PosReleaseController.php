<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use App\Support\PosReleaseManifest;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class PosReleaseController extends Controller
{
    // ใช้ POS ตัวเดียวคือ JET POS Python (PopCentral POS) ที่ build จากโปรเจกต์นี้
    // POS Vue/Tauri รุ่นเดิมปิดการดาวน์โหลดและการอัปเดตแล้ว ลิงก์เดิมพาไปตัว Python แทน
    public function downloadLatest(): Response
    {
        return redirect()->route('python-pos.download');
    }

    public function latest(): JsonResponse
    {
        abort(410, 'POS Vue/Tauri เลิกใช้แล้ว ติดตั้ง JET POS Python จาก /download/python-pos');
    }

    public function download(string $filename): BinaryFileResponse
    {
        abort(410, 'POS Vue/Tauri เลิกใช้แล้ว ติดตั้ง JET POS Python จาก /download/python-pos');
    }
}
