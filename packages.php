<?php

declare(strict_types=1);

/**
 * Katalog paket & helper ceklis pekerjaan.
 *
 * Nilai kuota per item:
 *   - angka > 0 : jatah per bulan
 *   - 0         : item TIDAK termasuk dalam paket (ceklis disembunyikan)
 *   - null      : tanpa batas / sesuai kesepakatan
 *
 * Kuota disesuaikan dengan kartu paket di menu "Pesan Layanan" (dashboard.php).
 */
function package_catalog(): array
{
    return [
        'Basic Maintenance'           => ['article' => 2,    'photo' => 10,   'social' => 8,    'backup' => 0],
        'Standard Growth'             => ['article' => 4,    'photo' => 20,   'social' => 12,   'backup' => 1],
        'Premium Pro'                 => ['article' => 8,    'photo' => null, 'social' => 20,   'backup' => 4],
        'Custom Project & Photoshoot' => ['article' => null, 'photo' => null, 'social' => null, 'backup' => null],
    ];
}

/** Label & ikon tiap item pekerjaan. */
function package_item_meta(): array
{
    return [
        'article' => ['label' => 'Artikel',        'icon' => '📝', 'unit' => 'artikel'],
        'photo'   => ['label' => 'Foto',           'icon' => '🖼️', 'unit' => 'foto'],
        'social'  => ['label' => 'Post Medsos',    'icon' => '📱', 'unit' => 'post'],
        'backup'  => ['label' => 'Backup Bulanan', 'icon' => '💾', 'unit' => 'kali'],
    ];
}

/**
 * Fitur / kuota berdasarkan nama paket klien.
 * Klien tanpa paket (atau nama tak dikenal) => semua item aktif tanpa kuota,
 * supaya admin tetap bisa mencatat pekerjaan.
 */
function package_features(?string $name): array
{
    $catalog = package_catalog();
    $name = $name !== null ? trim($name) : null;
    $known = $name !== null && isset($catalog[$name]);
    $quota = $known ? $catalog[$name] : ['article' => null, 'photo' => null, 'social' => null, 'backup' => null];

    $enabled = [];
    foreach ($quota as $key => $q) {
        $enabled[$key] = $q !== 0;
    }

    return [
        'name'    => $known ? $name : null,
        'known'   => $known,
        'quota'   => $quota,
        'enabled' => $enabled,
    ];
}

/** Daftar ceklis lengkap; tiap ceklis terikat ke satu item paket. */
function job_checklist_master(): array
{
    return [
        'check_image_opt' => [
            'item' => 'photo', 'icon' => '🖼️',
            'label' => 'Upload & Optimasi Gambar',
            'done' => 'Selesai (Kompresi WebP & Alt SEO)', 'wait' => 'Menunggu pengerjaan',
        ],
        'check_social_post' => [
            'item' => 'social', 'icon' => '📱',
            'label' => 'Post Medsos',
            'done' => 'Selesai (Feed / Story Tayang)', 'wait' => 'Menunggu jadwal konten',
        ],
        'check_social_link' => [
            'item' => 'social', 'icon' => '🔗',
            'label' => 'Post Tautan Artikel ke Medsos',
            'done' => 'Selesai (Link Bio & Share Berhasil)', 'wait' => 'Menunggu share link',
        ],
        'check_backup' => [
            'item' => 'backup', 'icon' => '💾',
            'label' => 'Backup Bulanan',
            'done' => 'Selesai (Database & File Website)', 'wait' => 'Menunggu jadwal backup',
        ],
    ];
}

/** Ceklis yang berlaku untuk paket tertentu. */
function job_checklist_for(array $features): array
{
    $out = [];
    foreach (job_checklist_master() as $field => $def) {
        if (!empty($features['enabled'][$def['item']])) {
            $out[$field] = $def;
        }
    }
    return $out;
}

/** Progres ceklis sebuah pekerjaan sesuai paket. */
function job_progress(array $job, array $features): array
{
    $list = job_checklist_for($features);
    $total = count($list);
    $done = 0;
    foreach ($list as $field => $_) {
        if (!empty($job[$field])) {
            $done++;
        }
    }
    return [
        'done'  => $done,
        'total' => $total,
        'pct'   => $total > 0 ? (int)round(($done / $total) * 100) : 0,
    ];
}

/**
 * Ambil input form pekerjaan & buang bagian yang tidak termasuk paket klien.
 */
function job_collect_inputs(array $post, array $features): array
{
    $en = $features['enabled'];
    return [
        'article_count'     => max(0, (int)($post['article_count'] ?? 1)),
        'photo_count'       => !empty($en['photo'])  ? max(0, (int)($post['photo_count'] ?? 0)) : 0,
        'social_post_count' => !empty($en['social']) ? max(0, (int)($post['social_post_count'] ?? 0)) : 0,
        'check_image_opt'   => (!empty($en['photo'])  && isset($post['check_image_opt'])) ? 1 : 0,
        'check_social_post' => (!empty($en['social']) && isset($post['check_social_post'])) ? 1 : 0,
        'check_social_link' => (!empty($en['social']) && isset($post['check_social_link'])) ? 1 : 0,
        'check_backup'      => (!empty($en['backup']) && isset($post['check_backup'])) ? 1 : 0,
    ];
}

/** Pemakaian kuota pada bulan tertentu (format Y-m) dari kumpulan pekerjaan satu klien. */
function job_usage(array $jobs, string $month): array
{
    $u = ['article' => 0, 'photo' => 0, 'social' => 0, 'backup' => 0];
    foreach ($jobs as $j) {
        if (substr((string)($j['upload_date'] ?? ''), 0, 7) !== $month) {
            continue;
        }
        $u['article'] += (int)($j['article_count'] ?? 1);
        $u['photo']   += (int)($j['photo_count'] ?? 0);
        $u['social']  += (int)($j['social_post_count'] ?? 0);
        $u['backup']  += !empty($j['check_backup']) ? 1 : 0;
    }
    return $u;
}

/** Badge nama paket (HTML siap cetak). */
function package_badge_html(?string $name): string
{
    if ($name === null || trim($name) === '') {
        return '<span class="inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-bold bg-slate-100 text-slate-500 border border-slate-200">Belum ada paket</span>';
    }
    return '<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-bold bg-brand-50 text-brand-700 border border-brand-200">📦 '
        . htmlspecialchars($name) . '</span>';
}

/** Chip ringkas jumlah artikel / foto / post / backup pada satu pekerjaan. */
function job_counts_html(array $job, array $features): string
{
    $en = $features['enabled'];
    $chip = static function (string $icon, string $text, bool $on = true): string {
        $cls = $on ? 'bg-slate-100 text-slate-700' : 'bg-emerald-50 text-emerald-700';
        return '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[11px] font-semibold ' . $cls . '">' . $icon . ' ' . $text . '</span>';
    };

    $html = '<div class="flex flex-wrap gap-1.5">';
    $html .= $chip('📝', (int)($job['article_count'] ?? 1) . ' artikel');
    if (!empty($en['photo'])) {
        $html .= $chip('🖼️', (int)($job['photo_count'] ?? 0) . ' foto');
    }
    if (!empty($en['social'])) {
        $html .= $chip('📱', (int)($job['social_post_count'] ?? 0) . ' post medsos');
    }
    if (!empty($en['backup'])) {
        $done = !empty($job['check_backup']);
        $html .= $chip('💾', $done ? 'Backup ✓' : 'Backup belum', !$done);
    }
    return $html . '</div>';
}

/** Panel kuota paket: terpakai vs jatah bulan berjalan. */
function render_quota_panel(array $features, array $usage, string $monthLabel): void
{
    $meta = package_item_meta();
    ?>
    <div class="mb-6 bg-white rounded-3xl border border-slate-200/80 shadow-xs overflow-hidden">
        <div class="p-5 border-b border-slate-200/80 bg-slate-50/50 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
            <div>
                <h3 class="text-sm font-extrabold text-slate-900">Kuota Paket Bulan <?= htmlspecialchars($monthLabel) ?></h3>
                <p class="text-[11px] text-slate-400 mt-0.5">Pemakaian artikel, foto, post medsos &amp; backup terhadap jatah paket.</p>
            </div>
            <?= package_badge_html($features['name']) ?>
        </div>
        <div class="p-5 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <?php foreach ($meta as $key => $m): ?>
                <?php
                $quota = $features['quota'][$key];
                $used = $usage[$key] ?? 0;
                if ($quota === 0): ?>
                    <div class="p-4 rounded-2xl border border-dashed border-slate-200 bg-slate-50/50 opacity-70">
                        <span class="text-xs font-bold text-slate-400"><?= $m['icon'] ?> <?= $m['label'] ?></span>
                        <p class="text-[11px] text-slate-400 mt-2">Tidak termasuk dalam paket ini</p>
                    </div>
                <?php else:
                    $pct = $quota ? min(100, (int)round(($used / $quota) * 100)) : 0;
                    $over = $quota !== null && $used > $quota;
                    $bar = $over ? 'bg-rose-500' : ($quota !== null && $used >= $quota ? 'bg-emerald-500' : 'bg-brand-500');
                    ?>
                    <div class="p-4 rounded-2xl border border-slate-200 bg-white">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-bold text-slate-600"><?= $m['icon'] ?> <?= $m['label'] ?></span>
                            <?php if ($over): ?><span class="text-[10px] font-bold text-rose-600">Melebihi kuota</span><?php endif; ?>
                        </div>
                        <div class="mt-1.5 text-xl font-black text-slate-900">
                            <?= $used ?>
                            <span class="text-xs font-semibold text-slate-400">
                                <?= $quota === null ? $m['unit'] . ' (sesuai kesepakatan)' : '/ ' . $quota . ' ' . $m['unit'] ?>
                            </span>
                        </div>
                        <?php if ($quota !== null): ?>
                            <div class="w-full bg-slate-100 h-1.5 rounded-full overflow-hidden mt-2">
                                <div class="h-full rounded-full transition-all <?= $bar ?>" style="width: <?= $pct ?>%"></div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}
