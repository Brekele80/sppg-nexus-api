<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\InventoryAuditController;

Route::prefix('inventory/audit')->group(function () {
    Route::post('/on-hand', [InventoryAuditController::class, 'auditOnHand']);
});
