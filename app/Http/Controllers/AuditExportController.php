<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Support\AuthUser;
use App\Services\AuditExportService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditExportController extends Controller
{
    protected AuditExportService $service;

    public function __construct(AuditExportService $service)
    {
        $this->service = $service;
    }

    /**
     * Export inventory ledger as audited CSV
     *
     * Query Params:
     * - from (YYYY-MM-DD)
     * - to (YYYY-MM-DD)
     * - branch_id (uuid, optional)
     * - item_id (uuid, optional)
     */
    public function inventoryLedger(Request $request): StreamedResponse
    {
        $request->validate([
            'from'      => 'required|date',
            'to'        => 'required|date|after_or_equal:from',
            'branch_id'=> 'nullable|uuid',
            'item_id'  => 'nullable|uuid',
        ]);

        return $this->service->exportInventoryLedger(
            companyId: $request->attributes->get('company_id'),
            from: $request->query('from'),
            to: $request->query('to'),
            branchId: $request->query('branch_id'),
            itemId: $request->query('item_id'),
            requestedBy: $request->user()->id
        );
    }

    public function export(Request $request, string $scope): StreamedResponse
    {
        $u = AuthUser::requireAnyRole($request, ['ACCOUNTING', 'KA_SPPG', 'DC_ADMIN']);
        $companyId = AuthUser::companyId($request);

        $request->validate([
            'from'      => 'required|date',
            'to'        => 'required|date|after_or_equal:from',
            'branch_id'=> 'nullable|uuid',
            'item_id'  => 'nullable|uuid',
        ]);

        return app(AuditExportService::class)->exportInventoryLedger(
            companyId: $companyId,
            from: $request->query('from'),
            to: $request->query('to'),
            branchId: $request->query('branch_id'),
            itemId: $request->query('item_id'),
            requestedBy: $u->id
        );
    }
}
