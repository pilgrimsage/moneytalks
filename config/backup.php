<?php

return [
    // 64 hex characters (php artisan moneytalks:backup:key). Keep a copy OFF the server: without it a backup cannot be read.
    'encryption_key' => env('BACKUP_ENCRYPTION_KEY'),
    'keep' => (int) env('BACKUP_KEEP', 14),
    'mysqldump_binary' => env('MYSQLDUMP_BINARY', 'mysqldump'),
    'mysql_binary' => env('MYSQL_BINARY', 'mysql'),
];
