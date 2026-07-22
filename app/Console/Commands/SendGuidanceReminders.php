<?php
namespace App\Console\Commands;
use App\Models\AcademicGuidanceAppointment;
use App\Models\StudentInterventionPlan;
use App\Services\NotificationService;
use Illuminate\Console\Command;
final class SendGuidanceReminders extends Command
{
    protected $signature = 'guidance:send-reminders';
    protected $description = 'Send reminders for upcoming guidance appointments and intervention plans';
    public function handle(NotificationService $notifications): int
    {
        $now = now(); $until = $now->copy()->addHours((int) config('siakad.guidance.reminder_hours_before', 24)); $sent = 0;
        AcademicGuidanceAppointment::with(['student', 'lecturer'])->whereIn('status', ['pending', 'confirmed'])->whereBetween('starts_at', [$now, $until])->whereNull('reminder_sent_at')->each(function (AcademicGuidanceAppointment $item) use ($notifications, &$sent): void { $notifications->student($item->student, 'guidance_reminder', 'Pengingat bimbingan akademik', 'Jadwal bimbingan Anda akan segera dimulai: '.$item->agenda.'.', '/academic/guidance'); if ($item->lecturer?->user_id) $notifications->send($item->lecturer->user_id, 'guidance_reminder', 'Pengingat bimbingan akademik', 'Jadwal bimbingan dengan '.$item->student->user?->name.' akan segera dimulai.', '/academic/guidance'); $item->update(['reminder_sent_at' => now()]); $sent++; });
        StudentInterventionPlan::with('student')->whereIn('status', ['open', 'in_progress'])->whereNotNull('due_on')->whereDate('due_on', '<=', $now->copy()->addDays(2))->whereNull('reminder_sent_at')->each(function (StudentInterventionPlan $item) use ($notifications, &$sent): void { $notifications->student($item->student, 'guidance_intervention_reminder', 'Pengingat tindak lanjut akademik', 'Tindak lanjut "'.$item->title.'" perlu diperhatikan sebelum '.$item->due_on->format('d/m/Y').'.', '/academic/guidance'); $item->update(['reminder_sent_at' => now()]); $sent++; });
        $this->info("{$sent} reminder dikirim."); return self::SUCCESS;
    }
}
