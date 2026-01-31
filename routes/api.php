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
use App\Http\Controllers\AuditController;

use App\Http\Controllers\StockAdjustmentController;
use App\Http\Controllers\StockAdjustmentAttachmentController;

use App\Http\Controllers\KitchenOutController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\InventoryItemController;
use App\Http\Controllers\InventoryLotController;
use App\Http\Controllers\InventoryMovementVoidController;
use App\Http\Controllers\AuditExportController;

use App\Http\Controllers\DC\MenuController;

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

    /*
    |--------------------------------------------------------------------------
    | READ (no idempotency required)
    |--------------------------------------------------------------------------
    */

    // Suppliers / Notifications
    Route::get('/suppliers', [SupplierController::class, 'index']);
    Route::get('/notifications', [NotificationController::class, 'index']);

    // Read-only audit (admin roles only)
    Route::middleware(['requireRole:ACCOUNTING,KA_SPPG,DC_ADMIN'])->group(function () {
        Route::get('/audit', [AuditController::class, 'index']);
        Route::prefix('accounting')->group(function () {
            Route::get('/journal/preview', [\App\Http\Controllers\Accounting\JournalPreviewController::class, 'preview']);
            Route::get('/exports/gl', [\App\Http\Controllers\Accounting\GlExportController::class, 'export']);
        });
    });

    // Inventory reads
    Route::get('/inventory', [InventoryController::class, 'index']);
    Route::get('/inventory/movements', [InventoryController::class, 'movements']);
    Route::get('/inventory/lots', [InventoryController::class, 'lots']);
    Route::get('/inventory/items/{itemId}/lots', [InventoryController::class, 'lotsByItem']);

    Route::prefix('dc')->group(function () {
        Route::get('/branches', [BranchController::class, 'index']);
        Route::get('/inventory-items', [InventoryItemController::class, 'index']);
        Route::get('/inventory-lots', [InventoryLotController::class, 'index']);

        Route::get('/menus', [MenuController::class, 'index']);
        Route::get('/menus/{id}', [MenuController::class, 'show']);
    });

    // Read PO
    Route::middleware(['requireRole:CHEF,ACCOUNTING,DC_ADMIN'])->group(function () {
        Route::get('/pos/{id}', [PurchaseOrderController::class, 'show'])->whereUuid('id');
    });

    // Supplier READ (must not be under idempotency)
    Route::middleware(['requireRole:SUPPLIER'])->prefix('supplier')->group(function () {
        Route::get('pos', [SupplierPortalController::class, 'myPurchaseOrders']);
    });

    // DC READ
    Route::middleware(['requireRole:DC_ADMIN'])->prefix('dc')->group(function () {
        Route::get('/receipts/{gr}', [DcReceiptController::class, 'show'])->whereUuid('gr');

        Route::get('/stock-adjustments', [StockAdjustmentController::class, 'index']);
        Route::get('/stock-adjustments/{id}', [StockAdjustmentController::class, 'show'])->whereUuid('id');

        Route::get('/stock-adjustments/{id}/attachments', [StockAdjustmentAttachmentController::class, 'index'])
            ->whereUuid('id');

        Route::get('/stock-adjustments/{id}/attachments/{attId}/download', [StockAdjustmentAttachmentController::class, 'download'])
            ->whereUuid('id')
            ->whereUuid('attId');
    });

    // Purchase reads
    Route::middleware(['requireRole:PURCHASE_CABANG'])->group(function () {
        Route::get('/prs', [PurchaseRequestController::class, 'index']);
        Route::get('/prs/{id}', [PurchaseRequestController::class, 'show'])->whereUuid('id');
        Route::get('/rabs/{id}', [RabController::class, 'show'])->whereUuid('id');

        // IMPORTANT: this is a mutation, moved to idempotency zone below
        // Route::put('/rabs/{id}', [RabController::class, 'updateDraft'])->whereUuid('id');
    });

    /*
    |--------------------------------------------------------------------------
    | IDEMPOTENT MUTATION ZONE
    |--------------------------------------------------------------------------
    */
    Route::middleware(['idempotency'])->group(function () {

        // Inventory audit routes (if file exists)
        $auditRoutes = __DIR__ . '/api_inventory_audit.php';
        if (file_exists($auditRoutes)) {
            require_once $auditRoutes;
        }

        $integrityRoutes = __DIR__ . '/api_inventory_integrity_audit.php';
        if (file_exists($integrityRoutes)) {
            require_once $integrityRoutes;
        }

        Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead'])->whereUuid('id');
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);

        Route::middleware(['requireRole:CHEF'])->group(function () {
            Route::post('/prs', [PurchaseRequestController::class, 'store']);
            Route::post('/prs/{id}/submit', [PurchaseRequestController::class, 'submit'])->whereUuid('id');

            Route::post('/kitchen/issues', [KitchenIssueController::class, 'create']);
            Route::post('/kitchen/issues/{id}/submit', [KitchenIssueController::class, 'submit'])->whereUuid('id');
        });

        Route::middleware(['requireRole:CHEF,DC_ADMIN'])->group(function () {
            // If your controller method is "create" not "store", align it here.
            Route::post('/kitchen/out', [KitchenOutController::class, 'create']);
        });

        Route::middleware(['requireRole:PURCHASE_CABANG'])->group(function () {
            Route::post('/prs/{id}/rabs', [RabController::class, 'createForPr'])->whereUuid('id');
            Route::post('/rabs/{id}/submit', [RabController::class, 'submit'])->whereUuid('id');
            Route::post('/rabs/{id}/revise', [RabController::class, 'revise'])->whereUuid('id');

            // Moved here: mutation must be idempotent
            Route::put('/rabs/{id}', [RabController::class, 'updateDraft'])->whereUuid('id');

            Route::post('/rabs/{rabId}/po', [PurchaseOrderController::class, 'createFromApprovedRab'])->whereUuid('rabId');
            Route::post('/pos/{id}/send', [PurchaseOrderController::class, 'sendToSupplier'])->whereUuid('id');
        });

        Route::middleware(['requireRole:KA_SPPG,ACCOUNTING,DC_ADMIN'])->group(function () {
            Route::post('/rabs/{id}/decisions', [RabDecisionController::class, 'store'])->whereUuid('id');
        });

        Route::middleware(['requireRole:ACCOUNTING'])->prefix('accounting')->group(function () {
            Route::post('purchase-orders/{id}/payment-proof', [AccountingPurchaseOrderPaymentController::class, 'uploadProof'])->whereUuid('id');
        });

        Route::middleware(['requireRole:SUPPLIER'])->prefix('supplier')->group(function () {
            Route::post('purchase-orders/{id}/confirm-payment', [SupplierPurchaseOrderPaymentController::class, 'confirmPayment'])->whereUuid('id');
            Route::post('pos/{id}/confirm', [SupplierPortalController::class, 'confirm'])->whereUuid('id');
            Route::post('pos/{id}/reject', [SupplierPortalController::class, 'reject'])->whereUuid('id');
            Route::post('pos/{id}/delivered', [SupplierPortalController::class, 'markDelivered'])->whereUuid('id');
        });

        Route::middleware(['requireRole:DC_ADMIN,CHEF'])->prefix('dc')->group(function () {
            Route::post('/menus', [MenuController::class, 'store']);

            Route::post('/menus/{id}/publish', [MenuController::class, 'publish'])
                ->whereUuid('id');
        });

        // DC operational writes: DC_ADMIN only (recommended)
        Route::middleware(['requireRole:DC_ADMIN'])->prefix('dc')->group(function () {

            Route::post('/pos/{po}/receipts', [DcReceiptController::class, 'createFromPo'])->whereUuid('po');
            Route::patch('/receipts/{gr}', [DcReceiptController::class, 'update'])->whereUuid('gr');
            Route::post('/receipts/{gr}/submit', [DcReceiptController::class, 'submit'])->whereUuid('gr');
            Route::post('/receipts/{gr}/receive', [DcReceiptController::class, 'receive'])->whereUuid('gr');

            Route::post('/issues/{id}/approve', [KitchenIssueController::class, 'approve'])->whereUuid('id');
            Route::post('/issues/{id}/issue', [KitchenIssueController::class, 'issue'])->whereUuid('id');

            Route::post('/stock-adjustments', [StockAdjustmentController::class, 'create']);
            Route::post('/stock-adjustments/{id}/submit', [StockAdjustmentController::class, 'submit'])->whereUuid('id');
            Route::post('/stock-adjustments/{id}/approve', [StockAdjustmentController::class, 'approve'])->whereUuid('id');
            Route::post('/stock-adjustments/{id}/post', [StockAdjustmentController::class, 'post'])->whereUuid('id');
            Route::post('/stock-adjustments/{id}/reject', [StockAdjustmentController::class, 'reject'])->whereUuid('id');
            Route::post('/stock-adjustments/{id}/void', [StockAdjustmentController::class, 'void'])->whereUuid('id');

            Route::post('/stock-adjustments/{id}/attachments', [StockAdjustmentAttachmentController::class, 'store'])
                ->whereUuid('id');

            Route::post(
                '/inventory/movements/{id}/void',
                [InventoryMovementVoidController::class, 'void']
            )->whereUuid('id');

            Route::delete('/stock-adjustments/{id}/attachments/{attId}', [StockAdjustmentAttachmentController::class, 'destroy'])
                ->whereUuid('id')
                ->whereUuid('attId');
        });

        Route::middleware(['requireRole:ACCOUNTING,KA_SPPG,DC_ADMIN', 'throttle:5,1'])
            ->get('/audit/exports/{scope}', [AuditExportController::class, 'export']);
    });
});
