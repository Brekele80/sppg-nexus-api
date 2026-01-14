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
use App\Http\Controllers\NotificationController;

/*
|--------------------------------------------------------------------------
| Public
|--------------------------------------------------------------------------
*/
Route::get('/health', fn () => response()->json(['ok' => true, 'app' => 'SPPG Nexus']));

/*
|--------------------------------------------------------------------------
| Authenticated only (NO company header)
|--------------------------------------------------------------------------
*/
Route::middleware(['supabase'])->group(function () {
    Route::get('/me', [MeController::class, 'show']);
});

/*
|--------------------------------------------------------------------------
| Authenticated + Company Scoped (TENANT SAFE ZONE)
|--------------------------------------------------------------------------
*/
Route::middleware(['supabase', 'requireCompany'])->group(function () {

    // ---------------- READ ----------------
    Route::get('/suppliers', [SupplierController::class, 'index']);
    Route::get('/notifications', [NotificationController::class, 'index']);

    Route::get('/inventory', [InventoryController::class, 'index']);
    Route::get('/inventory/movements', [InventoryController::class, 'movements']);
    Route::get('/inventory/lots', [InventoryController::class, 'lots']);
    Route::get('/inventory/items/{itemId}/lots', [InventoryController::class, 'lotsByItem']);

    // Read PO
    Route::middleware(['requireRole:CHEF,ACCOUNTING,DC_ADMIN'])->group(function () {
        Route::get('/pos/{id}', [PurchaseOrderController::class, 'show'])->whereUuid('id');
    });

    // ---------------- IDEMPOTENT MUTATION ZONE ----------------
    Route::middleware(['idempotency'])->group(function () {

        // ===== Notifications
        Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead'])->whereUuid('id');
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);

        // ===== CHEF
        Route::middleware(['requireRole:CHEF'])->group(function () {
            Route::post('/prs', [PurchaseRequestController::class, 'store']);
            Route::post('/prs/{id}/submit', [PurchaseRequestController::class, 'submit'])->whereUuid('id');

            Route::post('/kitchen/issues', [KitchenIssueController::class, 'create']);
            Route::post('/kitchen/issues/{id}/submit', [KitchenIssueController::class, 'submit'])->whereUuid('id');
        });

        // ===== PURCHASE
        Route::middleware(['requireRole:PURCHASE_CABANG'])->group(function () {
            Route::post('/prs/{id}/rabs', [RabController::class, 'createForPr'])->whereUuid('id');
            Route::post('/rabs/{id}/submit', [RabController::class, 'submit'])->whereUuid('id');
            Route::post('/rabs/{id}/revise', [RabController::class, 'revise'])->whereUuid('id');

            Route::post('/rabs/{rabId}/po', [PurchaseOrderController::class, 'createFromApprovedRab'])->whereUuid('rabId');
            Route::post('/pos/{id}/send', [PurchaseOrderController::class, 'sendToSupplier'])->whereUuid('id');
        });

        // ===== DECISIONS
        Route::middleware(['requireRole:KA_SPPG,ACCOUNTING,DC_ADMIN'])->group(function () {
            Route::post('/rabs/{id}/decisions', [RabDecisionController::class, 'store'])->whereUuid('id');
        });

        // ===== ACCOUNTING
        Route::middleware(['requireRole:ACCOUNTING'])->prefix('accounting')->group(function () {
            Route::post('purchase-orders/{id}/payment-proof', [AccountingPurchaseOrderPaymentController::class, 'uploadProof'])->whereUuid('id');
        });

        // ===== SUPPLIER
        Route::middleware(['requireRole:SUPPLIER'])->prefix('supplier')->group(function () {
            Route::post('purchase-orders/{id}/confirm-payment', [SupplierPurchaseOrderPaymentController::class, 'confirmPayment'])->whereUuid('id');
            Route::post('pos/{id}/confirm', [SupplierPortalController::class, 'confirm'])->whereUuid('id');
            Route::post('pos/{id}/reject', [SupplierPortalController::class, 'reject'])->whereUuid('id');
            Route::post('pos/{id}/delivered', [SupplierPortalController::class, 'markDelivered'])->whereUuid('id');
        });

        // ===== DC ADMIN
        Route::middleware(['requireRole:DC_ADMIN'])->prefix('dc')->group(function () {
            Route::post('/pos/{po}/receipts', [DcReceiptController::class, 'createFromPo'])->whereUuid('po');
            Route::patch('/receipts/{gr}', [DcReceiptController::class, 'update'])->whereUuid('gr');
            Route::post('/receipts/{gr}/submit', [DcReceiptController::class, 'submit'])->whereUuid('gr');
            Route::post('/receipts/{gr}/receive', [DcReceiptController::class, 'receive'])->whereUuid('gr');

            Route::post('/issues/{id}/approve', [KitchenIssueController::class, 'approve'])->whereUuid('id');
            Route::post('/issues/{id}/issue', [KitchenIssueController::class, 'issue'])->whereUuid('id');

            Route::post('/adjustments', [InventoryController::class, 'adjust']);
        });
    });

    // DC read
    Route::middleware(['requireRole:DC_ADMIN'])->prefix('dc')->group(function () {
        Route::get('/receipts/{gr}', [DcReceiptController::class, 'show'])->whereUuid('gr');
    });

    // Supplier read
    Route::middleware(['requireRole:SUPPLIER'])->group(function () {
        Route::get('/supplier/profile', [SupplierPortalController::class, 'index']);
        Route::get('/supplier/pos', [SupplierPortalController::class, 'myPurchaseOrders']);
    });

    // Purchase reads
    Route::middleware(['requireRole:PURCHASE_CABANG'])->group(function () {
        Route::get('/prs', [PurchaseRequestController::class, 'index']);
        Route::get('/prs/{id}', [PurchaseRequestController::class, 'show'])->whereUuid('id');
        Route::get('/rabs/{id}', [RabController::class, 'show'])->whereUuid('id');
        Route::put('/rabs/{id}', [RabController::class, 'updateDraft'])->whereUuid('id');
    });
});
