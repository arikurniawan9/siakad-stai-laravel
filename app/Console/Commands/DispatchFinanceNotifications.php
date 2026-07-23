<?php

namespace App\Console\Commands;

use App\Services\FinanceNotificationService;
use Illuminate\Console\Command;

final class DispatchFinanceNotifications extends Command
{
    protected $signature = 'finance:dispatch-notifications {--limit= : Maksimal notifikasi yang diproses}';
    protected $description = 'Kirim antrean notifikasi keuangan melalui email dan WhatsApp';

    public function handle(FinanceNotificationService $notifications): int
    {
        $limit = $this->option('limit');
        $result = $notifications->dispatchPending($limit !== null ? max(1, (int) $limit) : null);
        $this->info("Terkirim: {$result['sent']}; gagal: {$result['failed']}; dilewati: {$result['skipped']}.");

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
