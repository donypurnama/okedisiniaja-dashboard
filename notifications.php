<?php

declare(strict_types=1);

/**
 * Sistem notifikasi (log notifikasi).
 *
 * - Satu baris per penerima (recipient_id), jadi status baca tiap akun terpisah.
 * - Admin menerima notifikasi dari seluruh klien (client_id = klien terkait).
 * - Klien hanya menerima notifikasi miliknya (recipient_id = id klien).
 * - $link selalu URL internal "dashboard.php?..." dan dibuka lewat dashboard.php?tab=notifikasi&open=ID
 *   supaya otomatis ditandai dibaca.
 */

/** Kirim notifikasi ke satu akun. Kegagalan tidak boleh mengganggu proses utama. */
function notify_user(PDO $pdo, int $recipientId, string $type, string $title, string $message, string $link, ?int $clientId = null): void
{
    if ($recipientId <= 0) {
        return;
    }
    try {
        $pdo->prepare("
            INSERT INTO notifications (recipient_id, client_id, type, title, message, link, is_read, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 0, datetime('now'))
        ")->execute([$recipientId, $clientId, $type, $title, $message, $link]);
    } catch (Throwable $e) {
        // abaikan: notifikasi bersifat pelengkap
    }
}

/** Kirim notifikasi ke seluruh admin aktif. $clientId = klien yang memicu notifikasi. */
function notify_admins(PDO $pdo, string $type, string $title, string $message, string $link, ?int $clientId = null): void
{
    try {
        $ids = $pdo->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return;
    }
    foreach ($ids as $adminId) {
        notify_user($pdo, (int)$adminId, $type, $title, $message, $link, $clientId);
    }
}

/** Ikon & warna per tipe notifikasi. */
function notif_meta(string $type): array
{
    $map = [
        'payment'  => ['💳', 'Pembayaran', 'bg-amber-100 text-amber-700'],
        'order'    => ['🛒', 'Pesanan',    'bg-blue-100 text-blue-700'],
        'activity' => ['📝', 'Kegiatan',   'bg-emerald-100 text-emerald-700'],
        'job'      => ['🚀', 'Pekerjaan',  'bg-brand-100 text-brand-700'],
        'request'  => ['📨', 'Permintaan', 'bg-purple-100 text-purple-700'],
        'account'  => ['👤', 'Akun',       'bg-slate-200 text-slate-700'],
        'client'   => ['🆕', 'Klien Baru', 'bg-pink-100 text-pink-700'],
    ];
    return $map[$type] ?? ['🔔', 'Sistem', 'bg-slate-100 text-slate-600'];
}

/** Waktu relatif ("5 menit lalu"). created_at disimpan UTC (datetime('now') SQLite). */
function notif_time_ago(string $utc): string
{
    try {
        $ts = (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->getTimestamp();
    } catch (Throwable $e) {
        return '';
    }
    $diff = max(0, time() - $ts);
    if ($diff < 60) {
        return 'Baru saja';
    }
    if ($diff < 3600) {
        return floor($diff / 60) . ' menit lalu';
    }
    if ($diff < 86400) {
        return floor($diff / 3600) . ' jam lalu';
    }
    if ($diff < 7 * 86400) {
        return floor($diff / 86400) . ' hari lalu';
    }
    return notif_abs_time($utc);
}

/** Waktu absolut dalam WIB. */
function notif_abs_time(string $utc): string
{
    try {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('Asia/Jakarta'))->format('d M Y, H:i') . ' WIB';
    } catch (Throwable $e) {
        return $utc;
    }
}

/** Satu baris notifikasi. $compact = tampilan dropdown lonceng. */
function render_notif_item(array $n, bool $showClient, bool $compact = false): void
{
    [$icon, $label, $cls] = notif_meta((string)$n['type']);
    $unread = empty($n['is_read']);
    $href = 'dashboard.php?tab=notifikasi&open=' . (int)$n['id'];
    $client = trim((string)($n['client_business'] ?? '')) ?: trim((string)($n['client_name'] ?? ''));
    ?>
    <a href="<?= $href ?>"
       class="group flex items-start gap-3 <?= $compact ? 'px-4 py-3' : 'p-4 sm:px-5' ?> transition-colors hover:bg-slate-50 <?= $unread ? 'bg-brand-50/40' : '' ?>">
        <div class="w-9 h-9 rounded-xl flex items-center justify-center text-base shrink-0 <?= $cls ?>"><?= $icon ?></div>
        <div class="min-w-0 flex-1">
            <div class="flex items-start justify-between gap-2">
                <p class="text-xs <?= $unread ? 'font-extrabold text-slate-900' : 'font-semibold text-slate-700' ?> leading-snug">
                    <?= htmlspecialchars((string)$n['title']) ?>
                </p>
                <?php if ($unread): ?>
                    <span class="w-2 h-2 rounded-full bg-brand-500 shrink-0 mt-1" title="Belum dibaca"></span>
                <?php endif; ?>
            </div>
            <?php if (!empty($n['message'])): ?>
                <p class="text-[11px] text-slate-500 mt-0.5 <?= $compact ? 'line-clamp-2' : '' ?>"><?= htmlspecialchars((string)$n['message']) ?></p>
            <?php endif; ?>
            <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-[10px] text-slate-400">
                <span class="px-1.5 py-0.5 rounded <?= $cls ?> font-bold"><?= $label ?></span>
                <?php if ($showClient && $client !== ''): ?>
                    <span class="font-semibold text-slate-500">👤 <?= htmlspecialchars($client) ?></span>
                <?php endif; ?>
                <span title="<?= htmlspecialchars(notif_abs_time((string)$n['created_at'])) ?>"><?= htmlspecialchars(notif_time_ago((string)$n['created_at'])) ?></span>
            </div>
        </div>
        <?php if (!$compact): ?>
            <span class="text-slate-300 group-hover:text-brand-500 transition-colors self-center">&rarr;</span>
        <?php endif; ?>
    </a>
    <?php
}

/** Menu sidebar "Notifikasi" + badge belum dibaca. */
function render_notif_nav(string $activeTab, int $unread): void
{
    $active = $activeTab === 'notifikasi';
    ?>
    <a href="dashboard.php?tab=notifikasi"
       class="flex items-center justify-between px-3.5 py-2.5 rounded-xl transition-all font-semibold <?= $active ? 'bg-brand-600 text-white shadow-lg shadow-brand-600/30' : 'text-slate-300 hover:text-white hover:bg-slate-900' ?>">
        <div class="flex items-center gap-3">
            <svg class="w-4 h-4 shrink-0 <?= $active ? 'text-white' : 'text-slate-400' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
            </svg>
            <span>Notifikasi</span>
        </div>
        <?php if ($unread > 0): ?>
            <span class="px-2 py-0.5 rounded-full text-[10px] font-black bg-rose-500 text-white shadow-sm"><?= $unread > 99 ? '99+' : $unread ?></span>
        <?php endif; ?>
    </a>
    <?php
}
