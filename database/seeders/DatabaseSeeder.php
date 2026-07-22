<?php

namespace Database\Seeders;

use App\Models\Menu;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $roles = ['Admin', 'Prodi', 'Dosen', 'Mahasiswa', 'Calon Mahasiswa', 'Staff', 'Keuangan', 'Pimpinan', 'Bendahara'];
        $modules = ['users', 'roles', 'permissions', 'menus', 'settings', 'campuses', 'faculties', 'programs', 'academic_terms', 'courses', 'curricula', 'buildings', 'rooms', 'lecturers', 'schedules', 'students', 'calendar', 'exams', 'pmb', 'pmb_fees', 'pmb_verification', 'pmb_payments', 'pmb_selection', 'registration', 'krs', 'grades', 'transcript', 'attendance', 'documents', 'service_requests', 'service_types', 'guidance', 'lms', 'edom', 'finance', 'billing', 'payments', 'bank_integrations', 'virtual_accounts', 'reconciliation', 'reports'];

        foreach ($roles as $role) {
            Role::findOrCreate($role, 'web');
        }

        foreach ($modules as $module) {
            foreach (['view', 'create', 'update', 'delete'] as $action) {
                Permission::findOrCreate($module.'.'.$action, 'web');
            }
        }
        Permission::findOrCreate('students.export', 'web');
        Permission::findOrCreate('users.export', 'web');

        Role::findByName('Admin', 'web')->syncPermissions(Permission::all());

        $rolePermissions = [
            'Prodi' => ['campuses.view', 'faculties.view', 'faculties.create', 'faculties.update', 'programs.view', 'programs.create', 'programs.update', 'courses.view', 'courses.create', 'courses.update', 'academic_terms.view', 'academic_terms.create', 'academic_terms.update', 'curricula.view', 'curricula.create', 'curricula.update', 'buildings.view', 'buildings.create', 'buildings.update', 'rooms.view', 'rooms.create', 'rooms.update', 'lecturers.view', 'lecturers.create', 'lecturers.update', 'schedules.view', 'schedules.create', 'schedules.update', 'students.view', 'students.create', 'students.update', 'students.export', 'pmb.view', 'pmb.update', 'pmb_fees.view', 'pmb_fees.create', 'pmb_fees.update', 'pmb_verification.view', 'pmb_verification.update', 'pmb_selection.view', 'pmb_selection.create', 'pmb_selection.update', 'registration.view', 'registration.create', 'registration.update', 'krs.view', 'krs.update', 'grades.view', 'grades.create', 'grades.update', 'grades.delete', 'transcript.view', 'reports.view'],
            'Dosen' => ['lecturers.view', 'schedules.view', 'registration.view', 'registration.update', 'krs.view', 'krs.update', 'grades.view', 'grades.create', 'grades.update', 'grades.delete', 'lms.view', 'edom.view'],
            'Mahasiswa' => ['students.view', 'lecturers.view', 'schedules.view', 'registration.view', 'krs.view', 'krs.create', 'krs.update', 'krs.delete', 'grades.view', 'transcript.view', 'lms.view', 'edom.view', 'billing.view', 'finance.view'],
            'Calon Mahasiswa' => ['pmb.view', 'finance.view', 'billing.view'],
            'Staff' => ['campuses.view', 'faculties.view', 'programs.view', 'courses.view', 'academic_terms.view', 'curricula.view', 'buildings.view', 'buildings.create', 'buildings.update', 'rooms.view', 'rooms.create', 'rooms.update', 'lecturers.view', 'schedules.view', 'schedules.create', 'schedules.update', 'students.view', 'students.create', 'students.update', 'students.export', 'pmb.view', 'pmb.update', 'pmb_fees.view', 'pmb_verification.view', 'pmb_verification.update', 'pmb_selection.view', 'pmb_selection.create', 'pmb_selection.update', 'registration.view', 'registration.update', 'krs.view', 'krs.update', 'reports.view'],
            'Keuangan' => ['finance.view', 'billing.view', 'payments.view', 'virtual_accounts.view', 'reconciliation.view', 'reports.view', 'registration.view', 'registration.update', 'pmb.view', 'pmb_fees.view', 'pmb_fees.create', 'pmb_fees.update', 'pmb_payments.view', 'pmb_payments.update'],
            'Pimpinan' => ['finance.view', 'reconciliation.view', 'reports.view'],
            'Bendahara' => ['finance.view', 'billing.view', 'payments.view', 'virtual_accounts.view', 'reconciliation.view'],
        ];
        $rolePermissions['Prodi'] = array_values(array_unique([...$rolePermissions['Prodi'], 'lms.view', 'lms.create', 'lms.update', 'lms.delete', 'edom.view', 'edom.create', 'edom.update', 'edom.delete']));
        $rolePermissions['Dosen'] = array_values(array_unique([...$rolePermissions['Dosen'], 'lms.create', 'lms.update', 'lms.delete']));
        $rolePermissions['Mahasiswa'] = array_values(array_unique([...$rolePermissions['Mahasiswa'], 'edom.create']));
        $rolePermissions['Keuangan'] = array_values(array_unique([...$rolePermissions['Keuangan'], 'billing.create', 'billing.update', 'payments.create', 'payments.update']));
        $rolePermissions['Bendahara'] = array_values(array_unique([...$rolePermissions['Bendahara'], 'billing.create', 'billing.update', 'payments.create', 'payments.update']));
        $rolePermissions['Prodi'] = array_values(array_unique([...$rolePermissions['Prodi'], 'attendance.view', 'attendance.create', 'attendance.update', 'attendance.delete', 'documents.view', 'documents.create']));
        $rolePermissions['Dosen'] = array_values(array_unique([...$rolePermissions['Dosen'], 'attendance.view', 'attendance.create', 'attendance.update', 'attendance.delete', 'documents.view']));
        $rolePermissions['Mahasiswa'] = array_values(array_unique([...$rolePermissions['Mahasiswa'], 'attendance.view', 'attendance.create', 'documents.view', 'documents.create']));
        $rolePermissions['Keuangan'] = array_values(array_unique([...$rolePermissions['Keuangan'], 'documents.view', 'documents.create']));
        $rolePermissions['Bendahara'] = array_values(array_unique([...$rolePermissions['Bendahara'], 'documents.view', 'documents.create']));
        $rolePermissions['Prodi'] = array_values(array_unique([...$rolePermissions['Prodi'], 'service_requests.view', 'service_requests.update']));
        $rolePermissions['Dosen'] = array_values(array_unique([...$rolePermissions['Dosen'], 'service_requests.view', 'service_requests.update']));
        $rolePermissions['Mahasiswa'] = array_values(array_unique([...$rolePermissions['Mahasiswa'], 'service_requests.view', 'service_requests.create', 'service_requests.update']));
        $rolePermissions['Staff'] = array_values(array_unique([...$rolePermissions['Staff'], 'service_requests.view', 'service_requests.update']));
        $rolePermissions['Keuangan'] = array_values(array_unique([...$rolePermissions['Keuangan'], 'service_requests.view', 'service_requests.update']));
        $rolePermissions['Bendahara'] = array_values(array_unique([...$rolePermissions['Bendahara'], 'service_requests.view', 'service_requests.update']));
        $rolePermissions['Dosen'] = array_values(array_unique([...$rolePermissions['Dosen'], 'guidance.view', 'guidance.create', 'guidance.update']));
        $rolePermissions['Mahasiswa'] = array_values(array_unique([...$rolePermissions['Mahasiswa'], 'guidance.view', 'guidance.create']));
        $rolePermissions['Prodi'] = array_values(array_unique([...$rolePermissions['Prodi'], 'guidance.view', 'guidance.create', 'guidance.update']));
        $rolePermissions['Staff'] = array_values(array_unique([...$rolePermissions['Staff'], 'guidance.view', 'guidance.create', 'guidance.update']));
        $rolePermissions['Pimpinan'] = array_values(array_unique([...$rolePermissions['Pimpinan'], 'guidance.view']));
        $rolePermissions['Prodi'] = array_values(array_unique([...$rolePermissions['Prodi'], 'calendar.view', 'calendar.create', 'calendar.update', 'calendar.delete', 'exams.view', 'exams.create', 'exams.update', 'exams.delete']));
        $rolePermissions['Staff'] = array_values(array_unique([...$rolePermissions['Staff'], 'calendar.view', 'calendar.create', 'calendar.update', 'calendar.delete', 'exams.view', 'exams.create', 'exams.update', 'exams.delete']));
        $rolePermissions['Dosen'] = array_values(array_unique([...$rolePermissions['Dosen'], 'calendar.view', 'exams.view']));
        $rolePermissions['Mahasiswa'] = array_values(array_unique([...$rolePermissions['Mahasiswa'], 'calendar.view', 'exams.view']));
        $rolePermissions['Pimpinan'] = array_values(array_unique([...$rolePermissions['Pimpinan'], 'calendar.view', 'exams.view']));
        foreach ($rolePermissions as $role => $permissions) {
            Role::findByName($role, 'web')->syncPermissions(Permission::query()->whereIn('name', $permissions)->get());
        }

        $adminConfig = config('siakad.local_admin');
        if (app()->environment(['local', 'testing']) && filled($adminConfig['password'] ?? null)) {
            $admin = User::withTrashed()->updateOrCreate(
                ['email' => $adminConfig['email']],
                [
                    'name' => 'Administrator SIAKAD',
                    'username' => $adminConfig['username'],
                    'password' => $adminConfig['password'],
                    'is_active' => true,
                    'active_role' => 'Admin',
                ]
            );
            if ($admin->trashed()) $admin->restore();
            $admin->syncRoles(['Admin']);
        }

        $allRoles = $roles;
        $menus = [
            ['key' => 'dashboard', 'label' => 'Overview', 'href' => '/dashboard', 'icon' => 'LayoutDashboard', 'permission' => null, 'roles' => $allRoles],
            ['key' => 'layanan', 'label' => 'Layanan mahasiswa', 'href' => '/services', 'icon' => 'ConciergeBell', 'permission' => 'service_requests.view', 'roles' => ['Admin', 'Prodi', 'Dosen', 'Mahasiswa', 'Staff', 'Keuangan', 'Bendahara']],
            ['key' => 'bimbingan', 'label' => 'Bimbingan & early warning', 'href' => '/academic/guidance', 'icon' => 'HeartPulse', 'permission' => 'guidance.view', 'roles' => ['Admin', 'Prodi', 'Dosen', 'Mahasiswa', 'Staff', 'Pimpinan']],
            ['key' => 'akademik', 'label' => 'Akademik', 'href' => null, 'icon' => 'GraduationCap', 'permission' => null, 'roles' => ['Admin', 'Prodi', 'Dosen', 'Mahasiswa', 'Staff', 'Keuangan', 'Bendahara']],
            ['key' => 'akademik.master-data', 'label' => 'Master data', 'href' => '/admin/master-data', 'icon' => 'Database', 'permission' => 'courses.view', 'parent' => 'akademik', 'roles' => ['Admin', 'Prodi', 'Staff']],
            ['key' => 'akademik.sarana', 'label' => 'Gedung & ruangan', 'href' => '/admin/facilities', 'icon' => 'Building2', 'permission' => 'buildings.view', 'parent' => 'akademik', 'roles' => ['Admin', 'Prodi', 'Staff']],
            ['key' => 'akademik.kurikulum', 'label' => 'Kurikulum', 'href' => '/admin/curricula', 'icon' => 'BookOpenCheck', 'permission' => 'curricula.view', 'parent' => 'akademik', 'roles' => ['Admin', 'Prodi', 'Staff']],
            ['key' => 'akademik.jadwal', 'label' => 'Dosen & jadwal', 'href' => '/admin/academic-schedules', 'icon' => 'CalendarClock', 'permission' => 'schedules.view', 'parent' => 'akademik', 'roles' => ['Admin', 'Prodi', 'Dosen', 'Mahasiswa', 'Staff']],
            ['key' => 'akademik.kalender', 'label' => 'Kalender & ujian', 'href' => '/academic/calendar', 'icon' => 'CalendarDays', 'permission' => 'calendar.view', 'parent' => 'akademik', 'roles' => ['Admin', 'Prodi', 'Dosen', 'Mahasiswa', 'Staff', 'Pimpinan']],
            ['key' => 'akademik.mahasiswa', 'label' => 'Mahasiswa', 'href' => '/admin/students', 'icon' => 'Users', 'permission' => 'students.view', 'parent' => 'akademik', 'roles' => ['Admin', 'Prodi', 'Staff']],
            ['key' => 'akademik.krs', 'label' => 'Registrasi & KRS', 'href' => '/academic/registration', 'icon' => 'ClipboardList', 'permission' => 'registration.view', 'parent' => 'akademik', 'roles' => ['Admin', 'Prodi', 'Dosen', 'Mahasiswa', 'Staff', 'Keuangan']],
            ['key' => 'akademik.nilai', 'label' => 'Nilai & transkrip', 'href' => '/academic/grades', 'icon' => 'ChartNoAxesCombined', 'permission' => 'grades.view', 'parent' => 'akademik', 'roles' => ['Admin', 'Prodi', 'Dosen', 'Mahasiswa']],
            ['key' => 'akademik.presensi', 'label' => 'Presensi kuliah', 'href' => '/academic/attendance', 'icon' => 'ClipboardCheck', 'permission' => 'attendance.view', 'parent' => 'akademik', 'roles' => ['Admin', 'Prodi', 'Dosen', 'Mahasiswa']],
            ['key' => 'akademik.dokumen', 'label' => 'Dokumen resmi', 'href' => '/documents', 'icon' => 'FileCheck2', 'permission' => 'documents.view', 'parent' => 'akademik', 'roles' => ['Admin', 'Prodi', 'Dosen', 'Mahasiswa', 'Keuangan', 'Bendahara']],
            ['key' => 'pmb', 'label' => 'PMB', 'href' => '#', 'icon' => 'UserPlus', 'permission' => 'pmb.view', 'roles' => ['Admin', 'Prodi', 'Staff', 'Keuangan', 'Calon Mahasiswa']],
            ['key' => 'pmb.pendaftar', 'label' => 'Pendaftar & Tarif', 'href' => '/admin/pmb', 'icon' => 'Users', 'permission' => 'pmb_fees.view', 'parent' => 'pmb', 'roles' => ['Admin', 'Prodi', 'Staff', 'Keuangan']],
            ['key' => 'pmb.seleksi', 'label' => 'Seleksi', 'href' => '/admin/pmb/selection', 'icon' => 'BadgeCheck', 'permission' => 'pmb_selection.view', 'parent' => 'pmb', 'roles' => ['Admin', 'Prodi', 'Staff']],
            ['key' => 'pmb.aplikasi-saya', 'label' => 'Aplikasi saya', 'href' => '/pmb/application', 'icon' => 'ClipboardList', 'permission' => 'pmb.view', 'parent' => 'pmb', 'roles' => ['Calon Mahasiswa']],
            ['key' => 'keuangan', 'label' => 'Keuangan & VA', 'href' => '#', 'icon' => 'WalletCards', 'permission' => 'finance.view', 'roles' => ['Admin', 'Keuangan', 'Bendahara', 'Pimpinan', 'Mahasiswa', 'Calon Mahasiswa']],
            ['key' => 'keuangan.tagihan', 'label' => 'Tagihan mahasiswa', 'href' => '/finance', 'icon' => 'ReceiptText', 'permission' => 'billing.view', 'parent' => 'keuangan', 'roles' => ['Admin', 'Keuangan', 'Bendahara', 'Mahasiswa']],
            ['key' => 'keuangan.rekonsiliasi', 'label' => 'Rekonsiliasi', 'href' => '/finance?tab=reconciliation', 'icon' => 'ArrowLeftRight', 'permission' => 'reconciliation.view', 'parent' => 'keuangan', 'roles' => ['Admin', 'Keuangan', 'Bendahara', 'Pimpinan']],
            ['key' => 'lms', 'label' => 'LMS & EDOM', 'href' => null, 'icon' => 'BookOpenCheck', 'permission' => null, 'roles' => ['Admin', 'Prodi', 'Dosen', 'Mahasiswa']],
            ['key' => 'lms.kelas', 'label' => 'Ruang belajar', 'href' => '/academic/lms', 'icon' => 'Presentation', 'permission' => 'lms.view', 'parent' => 'lms', 'roles' => ['Admin', 'Prodi', 'Dosen', 'Mahasiswa']],
            ['key' => 'lms.edom', 'label' => 'Evaluasi dosen', 'href' => '/academic/edom', 'icon' => 'Star', 'permission' => 'edom.view', 'parent' => 'lms', 'roles' => ['Admin', 'Prodi', 'Dosen', 'Mahasiswa']],
            ['key' => 'laporan', 'label' => 'Laporan', 'href' => '/reports', 'icon' => 'FileBarChart', 'permission' => 'reports.view', 'roles' => ['Admin', 'Prodi', 'Keuangan', 'Pimpinan']],
            ['key' => 'pengaturan', 'label' => 'Pengaturan', 'href' => '#', 'icon' => 'Settings2', 'permission' => 'settings.view', 'roles' => ['Admin']],
            ['key' => 'pengaturan.users', 'label' => 'Pengguna', 'href' => '/admin/users', 'icon' => 'Users', 'permission' => 'users.view', 'parent' => 'pengaturan', 'roles' => ['Admin']],
            ['key' => 'pengaturan.menu-builder', 'label' => 'Menu Builder', 'href' => '/admin/menu-builder', 'icon' => 'PanelsTopLeft', 'permission' => 'menus.view', 'parent' => 'pengaturan', 'roles' => ['Admin']],
            ['key' => 'pengaturan.audit', 'label' => 'Audit Trail', 'href' => '/admin/audit-logs', 'icon' => 'ShieldCheck', 'permission' => 'settings.view', 'parent' => 'pengaturan', 'roles' => ['Admin']],
        ];
        $menuIds = [];
        foreach ($menus as $index => $data) {
            $parentId = isset($data['parent']) ? ($menuIds[$data['parent']] ?? null) : null;
            $menu = Menu::updateOrCreate(['key' => $data['key']], ['parent_id' => $parentId, 'label' => $data['label'], 'href' => $data['href'] ?? null, 'icon' => $data['icon'] ?? null, 'permission' => $data['permission'] ?? null, 'sort_order' => $index, 'is_active' => true]);
            $menu->roles()->sync(Role::query()->whereIn('name', $data['roles'])->pluck('id'));
            $menuIds[$data['key']] = $menu->id;
        }

        $serviceTypes = [
            ['code' => 'AKTIF-KULIAH', 'name' => 'Surat Keterangan Aktif Kuliah', 'category' => 'academic', 'description' => 'Surat resmi yang menerangkan status mahasiswa aktif.', 'workflow' => ['advisor', 'program', 'academic'], 'requirements_text' => 'Pastikan data identitas dan semester aktif sudah benar.', 'template_subject' => 'Surat Keterangan Aktif Kuliah', 'template_body' => "Yang bertanda tangan di bawah ini menerangkan bahwa:\n\n{NAMA}, NIM {NIM}, adalah mahasiswa aktif pada Program Studi {PROGRAM}. Surat ini diterbitkan untuk keperluan {TUJUAN}.", 'sla_business_days' => 3, 'requires_attachment' => false],
            ['code' => 'CUTI', 'name' => 'Permohonan Cuti Akademik', 'category' => 'academic', 'description' => 'Permohonan persetujuan cuti akademik mahasiswa.', 'workflow' => ['advisor', 'program', 'academic'], 'requirements_text' => 'Lampirkan surat alasan atau dokumen pendukung cuti.', 'template_subject' => 'Surat Persetujuan Cuti Akademik', 'template_body' => "Menerangkan bahwa permohonan cuti akademik atas nama {NAMA}, NIM {NIM}, Program Studi {PROGRAM}, telah diperiksa dan disetujui untuk keperluan {TUJUAN}.", 'sla_business_days' => 5, 'requires_attachment' => true],
            ['code' => 'PINDAH', 'name' => 'Permohonan Pindah Studi', 'category' => 'academic', 'description' => 'Permohonan pindah program studi atau institusi.', 'workflow' => ['advisor', 'program', 'finance', 'academic'], 'requirements_text' => 'Lampirkan surat tujuan pindah dan dokumen pendukung.', 'template_subject' => 'Surat Persetujuan Pindah Studi', 'template_body' => "Permohonan pindah studi atas nama {NAMA}, NIM {NIM}, dari Program Studi {PROGRAM}, telah melalui pemeriksaan akademik dan administrasi untuk keperluan {TUJUAN}.", 'sla_business_days' => 7, 'requires_attachment' => true],
            ['code' => 'REKOMENDASI', 'name' => 'Surat Rekomendasi Mahasiswa', 'category' => 'general', 'description' => 'Surat rekomendasi untuk kegiatan akademik atau kemahasiswaan.', 'workflow' => ['advisor', 'program'], 'requirements_text' => 'Jelaskan tujuan dan penerima surat secara lengkap.', 'template_subject' => 'Surat Rekomendasi', 'template_body' => "Dengan ini kami memberikan rekomendasi kepada {NAMA}, NIM {NIM}, mahasiswa Program Studi {PROGRAM}, untuk {TUJUAN}.", 'sla_business_days' => 3, 'requires_attachment' => false],
            ['code' => 'BEBAS-ADM', 'name' => 'Surat Bebas Administrasi', 'category' => 'finance', 'description' => 'Pemeriksaan dan pernyataan bebas kewajiban administrasi.', 'workflow' => ['program', 'finance', 'academic'], 'requirements_text' => 'Seluruh tagihan dan kewajiban administrasi harus sudah diselesaikan.', 'template_subject' => 'Surat Keterangan Bebas Administrasi', 'template_body' => "Menerangkan bahwa {NAMA}, NIM {NIM}, Program Studi {PROGRAM}, telah menyelesaikan pemeriksaan kewajiban administrasi untuk keperluan {TUJUAN}.", 'sla_business_days' => 5, 'requires_attachment' => false],
        ];
        foreach ($serviceTypes as $type) DB::table('service_request_types')->updateOrInsert(['code' => $type['code']], [...$type, 'workflow' => json_encode($type['workflow']), 'is_active' => true, 'deleted_at' => null, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('service_request_types')->whereIn('code', ['PINDAH', 'BEBAS-ADM'])->update(['requires_financial_clearance' => true, 'updated_at' => now()]);

        DB::table('academic_terms')->updateOrInsert(
            ['code' => '2026-GANJIL'],
            ['name' => 'Tahun Akademik 2026/2027', 'semester' => 'Ganjil', 'starts_on' => '2026-08-01', 'ends_on' => '2027-01-31', 'is_active' => true, 'updated_at' => now(), 'created_at' => now()]
        );

        $campusId = DB::table('campuses')->where('code', 'STAI-01')->value('id');
        if (! $campusId) {
            $campusId = DB::table('campuses')->insertGetId(['uuid' => (string) Str::uuid(), 'name' => 'Kampus Utama', 'code' => 'STAI-01', 'address' => 'Kampus utama', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }

        DB::table('faculties')->updateOrInsert(['code' => 'FTI'], ['campus_id' => $campusId, 'name' => 'Fakultas Teknologi Informasi', 'created_at' => now(), 'updated_at' => now()]);
        $facultyId = DB::table('faculties')->where('code', 'FTI')->value('id');
        DB::table('programs')->updateOrInsert(['code' => 'TI-S1'], ['faculty_id' => $facultyId, 'name' => 'Teknik Informatika', 'degree' => 'S1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $programId = DB::table('programs')->where('code', 'TI-S1')->value('id');
        DB::table('courses')->updateOrInsert(['code' => 'IF601'], ['program_id' => $programId, 'name' => 'Rekayasa Perangkat Lunak', 'credits' => 3, 'type' => 'Wajib', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }
}
