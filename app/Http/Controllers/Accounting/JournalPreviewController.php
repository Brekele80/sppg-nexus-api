namespace App\Http\Controllers\Accounting;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class JournalPreviewController
{
    public function __invoke(Request $request)
    {
        $companyId = $request->attributes->get('company_id');

        $from = $request->query('from');
        $to   = $request->query('to');

        $rows = DB::table('journal_entries')
            ->where('company_id', $companyId)
            ->when($from, fn($q) => $q->whereDate('posted_at', '>=', $from))
            ->when($to, fn($q) => $q->whereDate('posted_at', '<=', $to))
            ->orderBy('posted_at')
            ->get();

        return response()->json($rows);
    }
}
