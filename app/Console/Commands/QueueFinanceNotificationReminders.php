<?php

namespace App\Console\Commands;

use App\Services\FinanceNotificationService;
use Illuminate\Console\Command;

final class QueueFinanceNotificationReminders extends Command
{
    protected $signature = 'finance:queue-reminders';
    protected $description = 'Antrekan pengingat tagihan mahasiswa dan PMB sesuai tanggal jatuh tempo';

    public function handle(FinanceNotificationService $notifications): int
    {
        $result = $notifications->queueDueReminders();
        $this->info("Notifikasi baru yang diantrekan: {$result['queued']}.");

        return self::SUCCESS;
    }
}
