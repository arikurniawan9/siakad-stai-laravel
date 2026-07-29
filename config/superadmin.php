<?php

return [
    'role' => 'Super Admin',
    'settings_file' => 'system/bsi.json',
    'backup_directory' => 'backups/database',
    'max_restore_size_kb' => (int) env('SUPERADMIN_MAX_RESTORE_SIZE_KB', 512000),
    'mysql_bin_path' => env('SUPERADMIN_MYSQL_BIN_PATH'),
];
