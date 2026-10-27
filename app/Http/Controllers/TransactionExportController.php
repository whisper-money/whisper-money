<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExportTransactionsRequest;
use App\Models\Transaction;
use App\Services\TransactionCsvExporter;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TransactionExportController extends Controller
{
    /**
     * Download the user's transactions as CSV, narrowed by the same filters and
     * in the same order as the transactions page, or only the rows it has
     * loaded when `ids` names them. Rows are streamed so the whole history
     * never has to fit in memory.
     */
    public function __invoke(ExportTransactionsRequest $request, TransactionCsvExporter $exporter): StreamedResponse
    {
        $ids = $request->ids();

        $transactions = Transaction::query()
            ->where('user_id', $request->user()->id)
            ->with(TransactionCsvExporter::RELATIONS)
            ->applyFilters($request->filters())
            ->when($ids !== null, fn (Builder $query) => $query->whereIn('id', $ids))
            ->sortedForList()
            ->lazy(500);

        return response()->streamDownload(function () use ($transactions, $exporter): void {
            $output = fopen('php://output', 'w');

            // The byte order mark is what makes Excel read the file as UTF-8.
            fwrite($output, "\u{FEFF}");
            fputcsv($output, TransactionCsvExporter::HEADER, escape: '');

            foreach ($transactions as $transaction) {
                fputcsv($output, $exporter->row($transaction), escape: '');
            }

            fclose($output);
        }, 'transactions-'.today()->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            // The page counts rows as they arrive to show progress; a proxy
            // that buffers the whole body would hold them back until the end.
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
