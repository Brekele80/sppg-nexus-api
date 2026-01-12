<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\PurchaseRequestController;
use App\Http\Controllers\Api\RabController;
use App\Http\Controllers\Api\RabDecisionController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\SupplierPortalController;
use App\Http\Controllers\DcReceiptController;
use App\Http\Controllers\KitchenIssueController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\AccountingPurchaseOrderPaymentController;
use App\Http\Controllers\SupplierPurchaseOrderPaymentController;

Route::get('/health', fn () => response()->json(['ok'=>true,'app'=>'SPPG Nexus']));

Route::middleware(['supabase', 'requireCompany'])->group(function () {

    Route::get('/me',[MeController::class,'show']);
    Route::get('/suppliers',[SupplierController::class,'index']);
    Route::get('/inventory',[InventoryController::class,'index']);
    Route::get('/inventory/movements',[InventoryController::class,'movements']);

    /* ================= ACCOUNTING PAYMENT ================= */
    Route::middleware(['requireRole:ACCOUNTING'])->prefix('accounting')->group(function () {
        Route::get('purchase-orders/payables',[AccountingPurchaseOrderPaymentController::class,'payables']);
        Route::post('purchase-orders/{id}/payment-proof',[AccountingPurchaseOrderPaymentController::class,'uploadProof'])
            ->middleware('idempotency');
    });

    /* ================= SUPPLIER PAYMENT CONFIRM ================= */
    Route::middleware(['requireRole:SUPPLIER'])->prefix('supplier')->group(function () {
        Route::post('purchase-orders/{id}/confirm-payment',[SupplierPurchaseOrderPaymentController::class,'confirmPayment'])
            ->middleware('idempotency');
    });

    /* ================= READ PO (CHEF + ACCOUNTING + DC) ================= */
    Route::middleware(['requireRole:CHEF,PURCHASE_CABANG,DC_ADMIN,ACCOUNTING,KA_SPPG'])->group(function () {
        Route::get('/pos/{id}', [PurchaseOrderController::class, 'show']);
    });

    /* ================= CHEF FLOW ================= */
    Route::middleware(['requireRole:CHEF'])->group(function () {
        Route::post('/prs',[PurchaseRequestController::class,'store']);
        Route::get('/prs',[PurchaseRequestController::class,'index']);
        Route::get('/prs/{id}',[PurchaseRequestController::class,'show']);
        Route::post('/prs/{id}/submit',[PurchaseRequestController::class,'submit'])->middleware('idempotency');

        Route::post('/prs/{id}/rabs',[RabController::class,'createForPr']);
        Route::get('/rabs/{id}',[RabController::class,'show']);
        Route::put('/rabs/{id}',[RabController::class,'updateDraft']);
        Route::post('/rabs/{id}/submit',[RabController::class,'submit'])->middleware('idempotency');
        Route::post('/rabs/{id}/revise',[RabController::class,'revise']);

        Route::post('/rabs/{rabId}/po',[PurchaseOrderController::class,'createFromApprovedRab']);
        Route::post('/pos/{id}/send',[PurchaseOrderController::class,'sendToSupplier'])->middleware('idempotency');
    });

    /* ================= SUPPLIER PORTAL ================= */
    Route::middleware(['requireRole:SUPPLIER'])->group(function () {
        Route::get('/supplier/profile',[SupplierPortalController::class,'index']);
        Route::get('/supplier/pos',[SupplierPortalController::class,'myPurchaseOrders']);
        Route::post('/supplier/pos/{id}/confirm',[SupplierPortalController::class,'confirm'])->middleware('idempotency');
        Route::post('/supplier/pos/{id}/reject',[SupplierPortalController::class,'reject'])->middleware('idempotency');
        Route::post('/supplier/pos/{id}/delivered',[SupplierPortalController::class,'markDelivered'])->middleware('idempotency');
    });

    /* ================= DC ================= */
    Route::middleware(['requireRole:DC_ADMIN'])->prefix('dc')->group(function () {
        Route::post('/pos/{po}/receipts',[DcReceiptController::class,'createFromPo']);
        Route::patch('/receipts/{gr}',[DcReceiptController::class,'update']);
        Route::post('/receipts/{gr}/submit',[DcReceiptController::class,'submit'])->middleware('idempotency');
        Route::post('/receipts/{gr}/receive',[DcReceiptController::class,'receive'])->middleware('idempotency');
        Route::get('/receipts/{gr}',[DcReceiptController::class,'show']);
        Route::post('/issues/{id}/approve',[KitchenIssueController::class,'approve'])->middleware('idempotency');
        Route::post('/issues/{id}/issue',[KitchenIssueController::class,'issue'])->middleware('idempotency');
        Route::post('/adjustments',[InventoryController::class,'adjust'])->middleware('idempotency');
    });
});
