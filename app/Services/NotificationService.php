<?php

namespace App\Services;

use App\Models\ClassGroup;
use App\Models\Student;
use App\Models\SystemNotification;

final class NotificationService
{
    public function send(int $userId, string $type, string $title, string $message, ?string $link = null, array $data = []): SystemNotification
    {
        return SystemNotification::create(['user_id' => $userId, 'type' => $type, 'title' => $title, 'message' => $message, 'link' => $link, 'data' => $data]);
    }

    public function student(Student $student, string $type, string $title, string $message, ?string $link = null, array $data = []): void
    {
        if ($student->user_id) $this->send($student->user_id, $type, $title, $message, $link, $data);
    }

    public function classStudents(ClassGroup $classGroup, string $type, string $title, string $message, ?string $link = null): void
    {
        $userIds = $classGroup->enrollments()->where('status', 'enrolled')->whereHas('registration', fn ($query) => $query->where('status', 'approved'))->with('registration.student:id,user_id')->get()->pluck('registration.student.user_id')->filter()->unique();
        foreach ($userIds as $userId) $this->send((int) $userId, $type, $title, $message, $link, ['class_group_id' => $classGroup->id]);
    }
}
