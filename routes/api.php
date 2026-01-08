<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\PurchaseRequestController;
use App\Http\Controllers\Api\RabController;
use App\Http\Controllers\Api\RabDecisionController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\SupplierPortalController;
use App\Http\Controllers\DcReceiptController;
use App\Http\Controllers\KitchenIssueController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\Api\SupplierController;

// Public health check
Route::get('/health', fn () => response()->json(['ok' => true, 'app' => 'SPPG Nexus']));

// All authenticated endpoints
Route::middleware(['supabase'])->group(function () {

    Route::get('/me', [MeController::class, 'show']);
    Route::get('/suppliers', [SupplierController::class, 'index']);

    // ================= Inventory read
    // If ALL roles may read inventory:
    Route::get('/inventory', [InventoryController::class, 'index']);
    Route::get('/inventory/movements', [InventoryController::class, 'movements']);

    // ================= CHEF: PR / RAB / PO creation + sending PO
    Route::middleware(['requireRole:CHEF'])->group(function () {

        // PR
        Route::post('/prs', [PurchaseRequestController::class, 'store']);
        Route::get('/prs', [PurchaseRequestController::class, 'index']);
        Route::get('/prs/{id}', [PurchaseRequestController::class, 'show']);
        Route::post('/prs/{id}/submit', [PurchaseRequestController::class, 'submit'])->middleware('idempotency');

        // RAB
        Route::post('/prs/{id}/rabs', [RabController::class, 'createForPr']);
        Route::get('/rabs/{id}', [RabController::class, 'show']);
        Route::put('/rabs/{id}', [RabController::class, 'updateDraft']);
        Route::post('/rabs/{id}/submit', [RabController::class, 'submit'])->middleware('idempotency');
        Route::post('/rabs/{id}/revise', [RabController::class, 'revise']);

        // Decision read (optional for CHEF)
        Route::get('/rabs/{id}/decisions', [RabDecisionController::class, 'index']);

        // PO
        Route::post('/rabs/{rabId}/po', [PurchaseOrderController::class, 'createFromApprovedRab']);
        Route::post('/pos/{id}/send', [PurchaseOrderController::class, 'sendToSupplier'])->middleware('idempotency');
        Route::get('/pos/{id}', [PurchaseOrderController::class, 'show']);
    });

    // ================= Approvers (KA_SPPG / ACCOUNTING / DC_ADMIN) for decisions
    Route::middleware(['requireRole:KA_SPPG,ACCOUNTING,DC_ADMIN'])->group(function () {
        Route::post('/rabs/{id}/decisions', [RabDecisionController::class, 'store'])->middleware('idempotency');
    });

    // ================= Supplier Portal (SUPPLIER only)
    Route::middleware(['requireRole:SUPPLIER'])->group(function () {
        Route::get('/supplier/profile', [SupplierPortalController::class, 'index']);
        Route::get('/supplier/pos', [SupplierPortalController::class, 'myPurchaseOrders']);
        Route::post('/supplier/pos/{id}/confirm', [SupplierPortalController::class, 'confirm'])->middleware('idempotency');
        Route::post('/supplier/pos/{id}/reject', [SupplierPortalController::class, 'reject'])->middleware('idempotency');
        Route::post('/supplier/pos/{id}/delivered', [SupplierPortalController::class, 'markDelivered'])->middleware('idempotency');
    });

    // ================= Kitchen Issue Requests (CHEF)
    Route::middleware(['requireRole:CHEF'])->group(function () {
        Route::post('/kitchen/issues', [KitchenIssueController::class, 'create']);
        Route::post('/kitchen/issues/{id}/submit', [KitchenIssueController::class, 'submit'])->middleware('idempotency');
    });

    // ================= DC-only actions
    Route::middleware(['requireRole:DC_ADMIN'])->prefix('dc')->group(function () {
        // Goods Receipt
        Route::post('/pos/{po}/receipts', [DcReceiptController::class,'createFromPo']);
        Route::patch('/receipts/{gr}', [DcReceiptController::class,'update']);
        Route::post('/receipts/{gr}/submit', [DcReceiptController::class,'submit'])->middleware('idempotency');
        Route::post('/receipts/{gr}/receive', [DcReceiptController::class,'receive'])->middleware('idempotency');
        Route::get('/receipts/{gr}', [DcReceiptController::class, 'show']);

        // DC issue flow
        Route::post('/issues/{id}/approve', [KitchenIssueController::class, 'approve']);
        Route::post('/issues/{id}/issue', [KitchenIssueController::class, 'issue'])->middleware('idempotency');

        // Adjustments
        Route::post('/adjustments', [InventoryController::class, 'adjust'])->middleware('idempotency');
    });
});
