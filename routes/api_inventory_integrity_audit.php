<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\InventoryIntegrityAuditController;

Route::prefix('inventory/audit')->group(function () {
    Route::post('/integrity', [InventoryIntegrityAuditController::class, 'run']);
});
