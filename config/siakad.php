<?php

return [
    'timezone' => env('SIAKAD_TIMEZONE', 'Asia/Jakarta'),
    'institution' => env('SIAKAD_INSTITUTION', 'STAI Kampus Digital'),
    'local_admin' => [
        'username' => env('SIAKAD_ADMIN_USERNAME', 'admin'),
        'email' => env('SIAKAD_ADMIN_EMAIL', 'admin@siakad.local'),
        'password' => env('SIAKAD_ADMIN_PASSWORD'),
    ],
    'nim' => [
        'format' => env('SIAKAD_NIM_FORMAT', '{PROGRAM}{YEAR}{SEQUENCE}'),
        'sequence_digits' => (int) env('SIAKAD_NIM_SEQUENCE_DIGITS', 4),
    ],
    'grade_scale' => [
        ['letter' => 'A', 'minimum' => (float) env('SIAKAD_GRADE_A_MIN', 85), 'points' => 4.00],
        ['letter' => 'B+', 'minimum' => (float) env('SIAKAD_GRADE_B_PLUS_MIN', 80), 'points' => 3.50],
        ['letter' => 'B', 'minimum' => (float) env('SIAKAD_GRADE_B_MIN', 75), 'points' => 3.00],
        ['letter' => 'C+', 'minimum' => (float) env('SIAKAD_GRADE_C_PLUS_MIN', 70), 'points' => 2.50],
        ['letter' => 'C', 'minimum' => (float) env('SIAKAD_GRADE_C_MIN', 65), 'points' => 2.00],
        ['letter' => 'D', 'minimum' => (float) env('SIAKAD_GRADE_D_MIN', 50), 'points' => 1.00],
        ['letter' => 'E', 'minimum' => 0, 'points' => 0.00],
    ],
    'credit_limits' => [
        ['minimum_gpa' => (float) env('SIAKAD_CREDIT_GPA_HIGH_MIN', 3.00), 'credits' => (int) env('SIAKAD_CREDIT_GPA_HIGH_LIMIT', 24)],
        ['minimum_gpa' => (float) env('SIAKAD_CREDIT_GPA_GOOD_MIN', 2.50), 'credits' => (int) env('SIAKAD_CREDIT_GPA_GOOD_LIMIT', 21)],
        ['minimum_gpa' => (float) env('SIAKAD_CREDIT_GPA_FAIR_MIN', 2.00), 'credits' => (int) env('SIAKAD_CREDIT_GPA_FAIR_LIMIT', 18)],
        ['minimum_gpa' => 0, 'credits' => (int) env('SIAKAD_CREDIT_GPA_LOW_LIMIT', 15)],
    ],
    'edom_anonymity_threshold' => (int) env('SIAKAD_EDOM_ANONYMITY_THRESHOLD', 3),
    'guidance' => [
        'low_gpa_threshold' => (float) env('SIAKAD_GUIDANCE_LOW_GPA_THRESHOLD', 2.00),
        'low_attendance_threshold' => (float) env('SIAKAD_GUIDANCE_LOW_ATTENDANCE_THRESHOLD', 75),
        'reminder_hours_before' => (int) env('SIAKAD_GUIDANCE_REMINDER_HOURS_BEFORE', 24),
    ],
    'exam' => [
        'attendance_threshold' => (float) env('SIAKAD_EXAM_ATTENDANCE_THRESHOLD', 75),
    ],
];
