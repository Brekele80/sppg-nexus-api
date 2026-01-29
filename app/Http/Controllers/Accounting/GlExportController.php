namespace App\Http\Controllers\Accounting;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GlExportController
{
    public function __invoke(Request $request)
    {
        $companyId = $request->attributes->get('company_id');

        $from = $request->query('from');
        $to   = $request->query('to');

        $filename = "gl-export-{$from}-{$to}.csv";

        return new StreamedResponse(function () use ($companyId, $from, $to) {
            $out = fopen('php://output', 'w');

            // Accurate-compatible headers
            fputcsv($out, [
                'Date',
                'Account Code',
                'Account Name',
                'Branch',
                'Debit',
                'Credit',
                'Source Type',
                'Source ID'
            ]);

            DB::table('journal_entries')
                ->where('company_id', $companyId)
                ->when($from, fn($q) => $q->whereDate('posted_at', '>=', $from))
                ->when($to, fn($q) => $q->whereDate('posted_at', '<=', $to))
                ->orderBy('posted_at')
                ->chunk(500, function ($rows) use ($out) {
                    foreach ($rows as $r) {
                        fputcsv($out, [
                            substr($r->posted_at, 0, 10),
                            $r->account_code,
                            $r->account_name,
                            $r->branch_id,
                            $r->entry_type === 'DEBIT' ? $r->amount : '',
                            $r->entry_type === 'CREDIT' ? $r->amount : '',
                            $r->source_type,
                            $r->source_id
                        ]);
                    }
                });

            fclose($out);
        }, 200, [
            "Content-Type" => "text/csv",
            "Content-Disposition" => "attachment; filename={$filename}"
        ]);
    }
}
