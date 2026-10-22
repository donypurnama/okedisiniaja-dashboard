<?php

declare(strict_types=1);

/**
 * Koneksi SQLite (PDO) untuk OkeIniAja Client.
 * Pemakaian: $pdo = db();
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dir = __DIR__ . '/database';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $pdo = new PDO('sqlite:' . $dir . '/app.sqlite');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        db_upgrade($pdo);
    }
    return $pdo;
}

/**
 * Upgrade skema ringan & idempotent (aman dipanggil di setiap koneksi).
 * Menambah kolom baru pada job_activities jika belum ada.
 */
function db_upgrade(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS notifications (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            recipient_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            client_id    INTEGER REFERENCES users(id) ON DELETE CASCADE,
            type         TEXT NOT NULL DEFAULT 'system',
            title        TEXT NOT NULL,
            message      TEXT,
            link         TEXT,
            is_read      INTEGER NOT NULL DEFAULT 0,
            created_at   TEXT NOT NULL DEFAULT (datetime('now'))
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_notifications_recipient ON notifications (recipient_id, is_read, id)");

    $cols = [];
    foreach ($pdo->query('PRAGMA table_info(job_activities)') as $row) {
        $cols[$row['name']] = true;
    }
    if (!$cols) {
        return; // tabel belum dibuat (jalankan php migrate.php)
    }

    $add = [
        'article_count'     => 'INTEGER NOT NULL DEFAULT 1',
        'photo_count'       => 'INTEGER NOT NULL DEFAULT 0',
        'social_post_count' => 'INTEGER NOT NULL DEFAULT 0',
        'check_backup'      => 'INTEGER NOT NULL DEFAULT 0',
    ];
    foreach ($add as $name => $def) {
        if (!isset($cols[$name])) {
            $pdo->exec("ALTER TABLE job_activities ADD COLUMN {$name} {$def}");
        }
    }
}
