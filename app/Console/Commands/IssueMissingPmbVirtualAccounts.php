<?php

namespace App\Console\Commands;

use App\Domain\Pmb\PmbVirtualAccountService;
use App\Models\PmbInvoice;
use Illuminate\Console\Command;
use Throwable;

final class IssueMissingPmbVirtualAccounts extends Command
{
    protected $signature = 'pmb:issue-missing-virtual-accounts {--limit=500 : Maksimal invoice yang diproses}';

    protected $description = 'Terbitkan VA untuk invoice PMB lama yang belum memilikinya';

    public function handle(PmbVirtualAccountService $service): int
    {
        $limit = max(1, min(5000, (int) $this->option('limit')));
        $invoices = PmbInvoice::query()->with('application')->whereDoesntHave('virtualAccount')->oldest('id')->limit($limit)->get();
        $issued = 0;
        $failed = 0;

        foreach ($invoices as $invoice) {
            try {
                $service->issue($invoice->application, $invoice);
                $issued++;
            } catch (Throwable $exception) {
                $failed++;
                $this->error("{$invoice->invoice_number}: {$exception->getMessage()}");
            }
        }

        $this->info("VA diterbitkan: {$issued}; gagal: {$failed}; diperiksa: {$invoices->count()}.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
