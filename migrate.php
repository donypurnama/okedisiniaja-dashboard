<?php

declare(strict_types=1);

/**
 * Terapkan skema database. Jalankan via CLI: php migrate.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require __DIR__ . '/db.php';

db()->exec(file_get_contents(__DIR__ . '/database/schema.sql'));
echo "Migrasi selesai: tabel users siap.\n";
