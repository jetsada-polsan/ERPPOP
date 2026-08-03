<?php

use App\Http\Controllers\Api\PosApiController;
use App\Http\Controllers\Api\LegacyBackofficeSummaryController;
use App\Http\Controllers\PosController;
use Illuminate\Support\Facades\Route;

// API สำหรับ POS desktop (Tauri) — auth ด้วย Bearer device token (AuthenticatePosDevice)
// ไม่ใช้ session/CSRF. ตรรกะขาย/กะ ใช้ร่วมกับ PosController เดิม (device login แทน cashier user)
Route::prefix('pos')->middleware('pos.device')->name('api.pos.')->group(function () {
    Route::get('/ping', [PosApiController::class, 'ping'])->name('ping');
    Route::get('/cashiers', [PosApiController::class, 'cashiers'])->name('cashiers');
    Route::post('/cashier/login', [PosApiController::class, 'cashierLogin'])->name('cashier.login');
    Route::post('/cashier/pin', [PosApiController::class, 'changeCashierPin'])->name('cashier.pin');
    Route::get('/products', [PosController::class, 'products'])->name('products');
    Route::get('/promotions', [PosController::class, 'promotions'])->name('promotions');
    Route::get('/members', [PosController::class, 'members'])->name('members');
    Route::get('/shift', [PosController::class, 'activeShift'])->name('shift');
    Route::post('/shift/open', [PosController::class, 'openShift'])->name('shift.open');
    Route::post('/shift/close', [PosController::class, 'closeShift'])->name('shift.close');
    Route::post('/shift/cash-movement', [PosController::class, 'recordCashMovement'])->name('shift.cash-movement');
    Route::get('/held-bills', [PosController::class, 'heldBills'])->name('held-bills.index');
    Route::post('/held-bills', [PosController::class, 'holdBill'])->name('held-bills.store');
    Route::post('/held-bills/{heldBill}/resume', [PosController::class, 'resumeHeldBill'])->name('held-bills.resume');
    Route::post('/checkout', [PosApiController::class, 'checkout'])->name('checkout');
    Route::post('/receipt/void', [PosApiController::class, 'voidReceipt'])->name('receipt.void');
    Route::post('/receipt/return', [PosApiController::class, 'returnReceipt'])->name('receipt.return');
});

Route::post('/legacy-backoffice/summary', [LegacyBackofficeSummaryController::class, 'store'])->name('api.legacy-backoffice.summary');
