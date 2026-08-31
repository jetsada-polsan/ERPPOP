<?php

use App\Http\Controllers\Api\PosApiController;
use App\Http\Controllers\Api\OcrDocumentController;
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
    Route::post('/auth-events', [PosApiController::class, 'authEvents'])->name('auth-events');
    Route::post('/admin/authorize', [PosApiController::class, 'authorizeAdmin'])->name('admin.authorize');
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

Route::prefix('ocr')->middleware('auth')->name('api.ocr.')->group(function () {
    Route::get('/documents', [OcrDocumentController::class, 'index'])->name('documents.index');
    Route::post('/documents', [OcrDocumentController::class, 'store'])->name('documents.store');
    Route::get('/documents/{document}', [OcrDocumentController::class, 'show'])->name('documents.show');
    Route::get('/documents/{document}/file', [OcrDocumentController::class, 'file'])->name('documents.file');
    Route::post('/documents/{document}/process', [OcrDocumentController::class, 'process'])->name('documents.process');
    Route::post('/documents/{document}/review', [OcrDocumentController::class, 'review'])->name('documents.review');
    Route::post('/documents/{document}/approve', [OcrDocumentController::class, 'approve'])->name('documents.approve');
    Route::post('/documents/{document}/reject', [OcrDocumentController::class, 'reject'])->name('documents.reject');
    Route::post('/documents/{document}/post-to-goods-receipt', [OcrDocumentController::class, 'postToGoodsReceipt'])->name('documents.post');
});
