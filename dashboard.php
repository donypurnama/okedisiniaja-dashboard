<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/packages.php';
require_once __DIR__ . '/notifications.php';

// Pastikan hanya user yang sudah login yang bisa mengakses
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$pdo = db();
$userId = (int)$_SESSION['user_id'];

// Ambil data terbaru pengguna dari database
$stmtUser = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$stmtUser->execute([$userId]);
$currentUser = $stmtUser->fetch();

if (!$currentUser) {
    session_destroy();
    header("Location: index.php");
    exit;
}

$site_name = "OkeIniAja.com";
$site_url = "https://okeiniaja.com/";
$contact_wa = "6281291311218";
$current_year = date('Y');

/** URL publik untuk berkas bukti transfer */
function proof_url(?string $filename): string
{
    if (empty($filename)) {
        return '';
    }
    $trimmed = trim($filename);
    if (str_starts_with($trimmed, 'http://') || str_starts_with($trimmed, 'https://')) {
        return $trimmed;
    }
    if (str_starts_with($trimmed, 'uploads/')) {
        return $trimmed;
    }
    return 'uploads/proofs/' . basename($trimmed);
}

$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// Flash message ditampilkan sebagai toast notification (lihat toast.php)
$toastQueue = [];
if (!empty($flashSuccess)) {
    $toastQueue[] = ['type' => 'success', 'title' => 'Berhasil!', 'message' => (string)$flashSuccess];
}
if (!empty($flashError)) {
    $toastQueue[] = ['type' => 'error', 'title' => 'Perhatian!', 'message' => (string)$flashError];
}

// --------------------------------------------------------------------------
// PROSES FORM POST (SELF-SERVICE ACTIONS)
// --------------------------------------------------------------------------
$isAdmin = ($currentUser['role'] ?? 'client') === 'admin';

// Buka notifikasi: tandai dibaca lalu arahkan ke halaman terkait (hanya milik akun yang login)
if (isset($_GET['open'])) {
    $openId = (int)$_GET['open'];
    $stmtOpen = $pdo->prepare("SELECT id, link FROM notifications WHERE id = ? AND recipient_id = ? LIMIT 1");
    $stmtOpen->execute([$openId, $userId]);
    $openNotif = $stmtOpen->fetch();
    $target = 'dashboard.php?tab=notifikasi';
    if ($openNotif) {
        $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?")->execute([$openNotif['id']]);
        // hanya izinkan tautan internal
        if (preg_match('/^dashboard\.php\?tab=[a-z_]+/', (string)$openNotif['link'])) {
            $target = $openNotif['link'];
        }
    }
    header("Location: " . $target);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';

    // =========================================================================
    // ADMINISTRATOR ACTIONS
    // =========================================================================
    
    // ADMIN ACTION 1: VERIFIKASI / TOLAK / RETUR PEMBAYARAN
    if ($isAdmin && $action === 'admin_verify_payment') {
        $paymentId = (int)($_POST['payment_id'] ?? 0);
        $decision = $_POST['decision'] ?? 'verified'; // 'verified', 'verified_with_refund', 'returned', 'rejected'
        $adminNotes = trim($_POST['admin_notes'] ?? '');
        $refundAmount = (int)preg_replace('/[^0-9]/', '', $_POST['refund_amount'] ?? '0');
        $refundBank = trim($_POST['refund_bank'] ?? '');
        $refundAccount = trim($_POST['refund_account'] ?? '');

        $stmtPay = $pdo->prepare("
            SELECT p.*, o.package_name, o.billing_cycle, o.total_amount, o.order_code, o.user_id as order_user_id 
            FROM payments p 
            JOIN orders o ON p.order_id = o.id 
            WHERE p.id = ? LIMIT 1
        ");
        $stmtPay->execute([$paymentId]);
        $paymentRow = $stmtPay->fetch();

        if ($paymentRow) {
            $orderId = (int)$paymentRow['order_id'];
            $orderCode = $paymentRow['order_code'];
            $targetUserId = (int)$paymentRow['order_user_id'];
            $packageName = $paymentRow['package_name'];
            $billingCycle = $paymentRow['billing_cycle'];
            $expiryInterval = ($billingCycle === 'yearly') ? '+365 days' : '+30 days';

            if ($decision === 'verified') {
                // 1. Verifikasi Normal
                $pdo->prepare("
                    UPDATE payments 
                    SET status = 'verified', admin_notes = ?, verified_at = datetime('now'), updated_at = datetime('now') 
                    WHERE id = ?
                ")->execute([$adminNotes, $paymentId]);

                $pdo->prepare("UPDATE orders SET status = 'active', updated_at = datetime('now') WHERE id = ?")
                    ->execute([$orderId]);

                $pdo->prepare("
                    UPDATE users 
                    SET package_active = ?, package_billing = ?, package_expires_at = datetime('now', ?), updated_at = datetime('now') 
                    WHERE id = ?
                ")->execute([$packageName, $billingCycle, $expiryInterval, $targetUserId]);

                $pdo->prepare("
                    INSERT INTO activities (user_id, category, title, description, meta_info, status, activity_date, created_at)
                    VALUES (?, 'system', 'Pembayaran Terverifikasi & Layanan Aktif', ?, 'Verifikasi Sukses', 'completed', datetime('now'), datetime('now'))
                ")->execute([
                    $targetUserId,
                    "Pembayaran paket {$packageName} ({$billingCycle}) telah diverifikasi oleh Administrator. Tim spesialis kami segera mulai mengelola aset digital Anda." . ($adminNotes ? " Catatan: {$adminNotes}" : "")
                ]);

                $_SESSION['flash_success'] = "Pembayaran #{$paymentId} berhasil diverifikasi! Paket {$packageName} kini telah AKTIF untuk klien.";

            } elseif ($decision === 'verified_with_refund') {
                // 2. Setujui Paket & Retur Kelebihan Bayar
                $pdo->prepare("
                    UPDATE payments 
                    SET status = 'verified', 
                        admin_notes = ?, 
                        refund_amount = ?, 
                        refund_bank = ?, 
                        refund_account = ?, 
                        refund_date = datetime('now'), 
                        verified_at = datetime('now'), 
                        updated_at = datetime('now') 
                    WHERE id = ?
                ")->execute([$adminNotes, $refundAmount, $refundBank, $refundAccount, $paymentId]);

                $pdo->prepare("UPDATE orders SET status = 'active', updated_at = datetime('now') WHERE id = ?")
                    ->execute([$orderId]);

                $pdo->prepare("
                    UPDATE users 
                    SET package_active = ?, package_billing = ?, package_expires_at = datetime('now', ?), updated_at = datetime('now') 
                    WHERE id = ?
                ")->execute([$packageName, $billingCycle, $expiryInterval, $targetUserId]);

                $formattedRefund = number_format($refundAmount, 0, ',', '.');
                $pdo->prepare("
                    INSERT INTO activities (user_id, category, title, description, meta_info, status, activity_date, created_at)
                    VALUES (?, 'system', 'Pembayaran Diverifikasi & Kelebihan Bayar Diretur', ?, 'Kelebihan Dana Dikembalikan', 'completed', datetime('now'), datetime('now'))
                ")->execute([
                    $targetUserId,
                    "Pembayaran paket {$packageName} telah diverifikasi dan paket Anda AKTIF. Kelebihan transfer sebesar Rp {$formattedRefund} telah dikembalikan/diretur ke rekening {$refundBank} ({$refundAccount})." . ($adminNotes ? " Catatan Admin: {$adminNotes}" : "")
                ]);

                $_SESSION['flash_success'] = "Pembayaran #{$paymentId} diverifikasi & retur kelebihan bayar Rp {$formattedRefund} berhasil diproses!";

            } elseif ($decision === 'returned') {
                // 3. Retur Penuh / Refund 100%
                $pdo->prepare("
                    UPDATE payments 
                    SET status = 'returned', 
                        admin_notes = ?, 
                        refund_amount = ?, 
                        refund_bank = ?, 
                        refund_account = ?, 
                        refund_date = datetime('now'), 
                        updated_at = datetime('now') 
                    WHERE id = ?
                ")->execute([$adminNotes, $refundAmount, $refundBank, $refundAccount, $paymentId]);

                // Batalkan pesanan
                $pdo->prepare("UPDATE orders SET status = 'cancelled', updated_at = datetime('now') WHERE id = ?")
                    ->execute([$orderId]);

                $formattedRefund = number_format($refundAmount, 0, ',', '.');
                $pdo->prepare("
                    INSERT INTO activities (user_id, category, title, description, meta_info, status, activity_date, created_at)
                    VALUES (?, 'system', 'Pengembalian Dana (Return) Berhasil Diproses', ?, 'Dana Diretur', 'completed', datetime('now'), datetime('now'))
                ")->execute([
                    $targetUserId,
                    "Dana pembayaran pesanan {$orderCode} sebesar Rp {$formattedRefund} telah dikembalikan/diretur ke rekening {$refundBank} ({$refundAccount})." . ($adminNotes ? " Catatan/Ref: {$adminNotes}" : "")
                ]);

                $_SESSION['flash_success'] = "Pembayaran #{$paymentId} berhasil diproses sebagai Retur / Pengembalian Dana Rp {$formattedRefund}.";

            } elseif ($decision === 'rejected') {
                // 4. Tolak Pembayaran (Tanpa Retur / Mutasi Belum Masuk / Bukti Palsu)
                $pdo->prepare("
                    UPDATE payments 
                    SET status = 'rejected', admin_notes = ?, updated_at = datetime('now') 
                    WHERE id = ?
                ")->execute([$adminNotes, $paymentId]);

                $pdo->prepare("UPDATE orders SET status = 'pending_payment', updated_at = datetime('now') WHERE id = ?")
                    ->execute([$orderId]);

                $pdo->prepare("
                    INSERT INTO activities (user_id, category, title, description, meta_info, status, activity_date, created_at)
                    VALUES (?, 'system', 'Konfirmasi Pembayaran Ditolak', ?, 'Perlu Penyesuaian', 'completed', datetime('now'), datetime('now'))
                ")->execute([
                    $targetUserId,
                    "Bukti konfirmasi pembayaran pesanan {$orderCode} belum dapat diverifikasi. Catatan Admin: " . ($adminNotes ?: 'Mohon periksa kembali nominal transfer atau unggah bukti transfer yang valid.')
                ]);

                $_SESSION['flash_success'] = "Pembayaran #{$paymentId} berhasil ditandai sebagai Ditolak.";
            }

            // Notifikasi ke klien terkait hasil verifikasi
            $payNotif = [
                'verified' => ['Pembayaran terverifikasi', "Pembayaran pesanan {$orderCode} telah diverifikasi. Paket {$packageName} kini aktif.", 'dashboard.php?tab=invoice'],
                'verified_with_refund' => ['Pembayaran terverifikasi & kelebihan diretur', "Pembayaran pesanan {$orderCode} diverifikasi. Kelebihan transfer telah dikembalikan.", 'dashboard.php?tab=invoice'],
                'returned' => ['Dana pembayaran dikembalikan', "Dana pembayaran pesanan {$orderCode} telah dikembalikan ke rekening Anda.", 'dashboard.php?tab=invoice'],
                'rejected' => ['Konfirmasi pembayaran ditolak', "Bukti pembayaran pesanan {$orderCode} belum dapat diverifikasi. Silakan periksa kembali dan kirim ulang.", "dashboard.php?tab=pembayaran&order_id={$orderId}"],
            ];
            if (isset($payNotif[$decision])) {
                [$pnTitle, $pnMsg, $pnLink] = $payNotif[$decision];
                notify_user($pdo, $targetUserId, 'payment', $pnTitle, $pnMsg, $pnLink, $targetUserId);
            }
        }
        header("Location: dashboard.php?tab=admin_payments");
        exit;
    }

    // ADMIN ACTION 2: MENGISIKAN KEGIATAN UNTUK KLIEN
    if ($isAdmin && $action === 'admin_add_activity') {
        $targetUserId = (int)($_POST['target_user_id'] ?? 0);
        $category = $_POST['category'] ?? 'article';
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $metaInfo = trim($_POST['meta_info'] ?? '');
        $status = $_POST['status'] ?? 'completed';
        $activityDate = trim($_POST['activity_date'] ?? date('Y-m-d'));

        if ($targetUserId <= 0 || empty($title)) {
            $_SESSION['flash_error'] = 'Klien dan Judul Kegiatan wajib dipilih dan diisi.';
            header("Location: dashboard.php?tab=admin_activities");
            exit;
        }

        try {
            $pdo->prepare("
                INSERT INTO activities (user_id, category, title, description, meta_info, status, activity_date, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now'))
            ")->execute([
                $targetUserId,
                $category,
                $title,
                $description,
                $metaInfo,
                $status,
                $activityDate
            ]);

            // Notifikasi ke klien
            notify_user($pdo, $targetUserId, 'activity', 'Kegiatan baru: ' . $title,
                $description !== '' ? mb_strimwidth($description, 0, 140, '…') : 'Tim kami mencatat kegiatan baru untuk akun Anda.',
                'dashboard.php?tab=laporan', $targetUserId);

            $_SESSION['flash_success'] = "Kegiatan baru berhasil dicatat dan dipublikasikan ke Portal Klien yang bersangkutan!";
            header("Location: dashboard.php?tab=admin_activities&client_id=" . $targetUserId);
            exit;
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = 'Gagal menyimpan kegiatan: ' . $e->getMessage();
            header("Location: dashboard.php?tab=admin_activities");
            exit;
        }
    }

    // ADMIN ACTION 3: HAPUS KEGIATAN
    if ($isAdmin && $action === 'admin_delete_activity') {
        $actId = (int)($_POST['activity_id'] ?? 0);
        if ($actId > 0) {
            $pdo->prepare("DELETE FROM activities WHERE id = ?")->execute([$actId]);
            $_SESSION['flash_success'] = "Kegiatan berhasil dihapus.";
        }
        header("Location: dashboard.php?tab=admin_activities");
        exit;
    }

    // ADMIN ACTION 4: TAMBAH PEKERJAAN ARTIKEL BARU DENGAN CEKLIS
    if ($isAdmin && $action === 'admin_add_job_activity') {
        $targetUserId = (int)($_POST['target_user_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $taskType = trim($_POST['task_type'] ?? 'article');
        $uploadDate = trim($_POST['upload_date'] ?? date('Y-m-d'));
        $articleUrl = trim($_POST['article_url'] ?? '');
        $socialUrl = trim($_POST['social_url'] ?? '');
        $checkImageOpt = isset($_POST['check_image_opt']) ? 1 : 0;
        $checkSocialPost = isset($_POST['check_social_post']) ? 1 : 0;
        $checkSocialLink = isset($_POST['check_social_link']) ? 1 : 0;
        $status = $_POST['status'] ?? 'in_progress';
        $notes = trim($_POST['notes'] ?? '');

        // Jumlah & ceklis hanya berlaku untuk item yang termasuk paket klien
        $stmtPkg = $pdo->prepare("SELECT package_active FROM users WHERE id = ? LIMIT 1");
        $stmtPkg->execute([$targetUserId]);
        $jobInputs = job_collect_inputs($_POST, package_features($stmtPkg->fetchColumn() ?: null));
        $checkImageOpt = $jobInputs['check_image_opt'];
        $checkSocialPost = $jobInputs['check_social_post'];
        $checkSocialLink = $jobInputs['check_social_link'];

        if ($targetUserId <= 0 || $title === '') {
            $_SESSION['flash_error'] = 'Klien tujuan dan Judul Pekerjaan/Artikel wajib diisi.';
            header("Location: dashboard.php?tab=aktivitas");
            exit;
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO job_activities (user_id, title, task_type, upload_date, article_url, social_url, check_image_opt, check_social_post, check_social_link, article_count, photo_count, social_post_count, check_backup, status, notes, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))
            ");
            $stmt->execute([
                $targetUserId,
                $title,
                $taskType,
                $uploadDate,
                $articleUrl ?: null,
                $socialUrl ?: null,
                $checkImageOpt,
                $checkSocialPost,
                $checkSocialLink,
                $jobInputs['article_count'],
                $jobInputs['photo_count'],
                $jobInputs['social_post_count'],
                $jobInputs['check_backup'],
                $status,
                $notes
            ]);

            // Catat log kegiatan sistem untuk klien
            $pdo->prepare("
                INSERT INTO activities (user_id, category, title, description, meta_info, status, activity_date, created_at)
                VALUES (?, 'article', ?, ?, 'Pekerjaan Artikel Baru', 'completed', datetime('now'), datetime('now'))
            ")->execute([
                $targetUserId,
                "Jadwal Pekerjaan Artikel: {$title}",
                "Target Upload: " . date('d M Y', strtotime($uploadDate)) . ". Ceklis: " . ($checkImageOpt ? '☑️ Gambar ' : '⬜ Gambar ') . ($checkSocialPost ? '☑️ Medsos ' : '⬜ Medsos ') . ($checkSocialLink ? '☑️ Link Medsos' : '⬜ Link Medsos')
            ]);

            // Notifikasi ke klien
            notify_user($pdo, $targetUserId, 'job', 'Jadwal artikel baru: ' . $title,
                'Target upload ' . date('d M Y', strtotime($uploadDate)) . '.',
                'dashboard.php?tab=aktivitas', $targetUserId);

            $_SESSION['flash_success'] = "Pekerjaan artikel '{$title}' berhasil ditambahkan ke jadwal klien!";
            header("Location: dashboard.php?tab=aktivitas&client_id=" . $targetUserId);
            exit;
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = 'Gagal menambahkan pekerjaan: ' . $e->getMessage();
            header("Location: dashboard.php?tab=aktivitas");
            exit;
        }
    }

    // ADMIN ACTION 5: UPDATE CEKLIS & STATUS PEKERJAAN ARTIKEL
    if ($isAdmin && $action === 'admin_update_job_activity') {
        $jobId = (int)($_POST['job_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $uploadDate = trim($_POST['upload_date'] ?? date('Y-m-d'));
        $articleUrl = trim($_POST['article_url'] ?? '');
        $socialUrl = trim($_POST['social_url'] ?? '');
        $checkImageOpt = isset($_POST['check_image_opt']) ? 1 : 0;
        $checkSocialPost = isset($_POST['check_social_post']) ? 1 : 0;
        $checkSocialLink = isset($_POST['check_social_link']) ? 1 : 0;
        $status = $_POST['status'] ?? 'in_progress';
        $notes = trim($_POST['notes'] ?? '');

        $stmtChk = $pdo->prepare("SELECT * FROM job_activities WHERE id = ? LIMIT 1");
        $stmtChk->execute([$jobId]);
        $existingJob = $stmtChk->fetch();

        if (!$existingJob) {
            $_SESSION['flash_error'] = 'Data pekerjaan tidak ditemukan.';
            header("Location: dashboard.php?tab=aktivitas");
            exit;
        }

        try {
            $stmtPkgU = $pdo->prepare("SELECT package_active FROM users WHERE id = ? LIMIT 1");
            $stmtPkgU->execute([$existingJob['user_id']]);
            $jobInputs = job_collect_inputs($_POST, package_features($stmtPkgU->fetchColumn() ?: null));
            $checkImageOpt = $jobInputs['check_image_opt'];
            $checkSocialPost = $jobInputs['check_social_post'];
            $checkSocialLink = $jobInputs['check_social_link'];

            $stmtUp = $pdo->prepare("
                UPDATE job_activities 
                SET title = ?, upload_date = ?, article_url = ?, social_url = ?, 
                    check_image_opt = ?, check_social_post = ?, check_social_link = ?, 
                    article_count = ?, photo_count = ?, social_post_count = ?, check_backup = ?, 
                    status = ?, notes = ?, updated_at = datetime('now')
                WHERE id = ?
            ");
            $stmtUp->execute([
                $title ?: $existingJob['title'],
                $uploadDate,
                $articleUrl ?: null,
                $socialUrl ?: null,
                $checkImageOpt,
                $checkSocialPost,
                $checkSocialLink,
                $jobInputs['article_count'],
                $jobInputs['photo_count'],
                $jobInputs['social_post_count'],
                $jobInputs['check_backup'],
                $status,
                $notes,
                $jobId
            ]);

            // Jika status selesai atau semua ceklis selesai, kirim update aktivitas ke portal klien
            if ($status === 'completed' && $existingJob['status'] !== 'completed') {
                $pdo->prepare("
                    INSERT INTO activities (user_id, category, title, description, meta_info, status, activity_date, created_at)
                    VALUES (?, 'article', ?, ?, 'Selesai 100%', 'completed', datetime('now'), datetime('now'))
                ")->execute([
                    $existingJob['user_id'],
                    "Artikel Selesai & Terbit: " . ($title ?: $existingJob['title']),
                    "Seluruh ceklis upload & optimasi gambar, post medsos, dan share link artikel telah selesai dikerjakan." . ($articleUrl ? " Tautan: {$articleUrl}" : "")
                ]);
            }

            // Notifikasi ke klien
            $jobTitleN = $title ?: $existingJob['title'];
            $statusLabelsN = ['completed' => 'Selesai & tayang', 'scheduled' => 'Terjadwal', 'in_progress' => 'Dalam proses', 'draft' => 'Draf'];
            notify_user($pdo, (int)$existingJob['user_id'], 'job',
                ($status === 'completed' && $existingJob['status'] !== 'completed' ? 'Artikel selesai & tayang: ' : 'Progres pekerjaan diperbarui: ') . $jobTitleN,
                'Status: ' . ($statusLabelsN[$status] ?? $status) . '.',
                'dashboard.php?tab=aktivitas', (int)$existingJob['user_id']);

            $_SESSION['flash_success'] = "Ceklis dan status pekerjaan #{$jobId} berhasil diperbarui!";
            header("Location: dashboard.php?tab=aktivitas&client_id=" . $existingJob['user_id']);
            exit;
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = 'Gagal memperbarui ceklis pekerjaan: ' . $e->getMessage();
            header("Location: dashboard.php?tab=aktivitas");
            exit;
        }
    }

    // ADMIN ACTION 6: HAPUS PEKERJAAN ARTIKEL
    if ($isAdmin && $action === 'admin_delete_job_activity') {
        $jobId = (int)($_POST['job_id'] ?? 0);
        $clientId = (int)($_POST['client_id'] ?? 0);

        try {
            $stmtDel = $pdo->prepare("DELETE FROM job_activities WHERE id = ?");
            $stmtDel->execute([$jobId]);

            $_SESSION['flash_success'] = "Pekerjaan #{$jobId} berhasil dihapus.";
            header("Location: dashboard.php?tab=aktivitas" . ($clientId ? "&client_id={$clientId}" : ""));
            exit;
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = 'Gagal menghapus pekerjaan: ' . $e->getMessage();
            header("Location: dashboard.php?tab=aktivitas");
            exit;
        }
    }

    // ADMIN ACTION 7: GANTI PASSWORD KLIEN OLEH ADMIN
    if ($isAdmin && $action === 'admin_change_client_password') {
        $targetId = (int)($_POST['client_id'] ?? 0);
        $newPass = (string)($_POST['new_password'] ?? '');
        $confirmPass = (string)($_POST['confirm_password'] ?? '');
        $backUrl = "dashboard.php?tab=admin_client_detail&client_id={$targetId}";

        $stmtTarget = $pdo->prepare("SELECT id, name, business_name, role FROM users WHERE id = ? LIMIT 1");
        $stmtTarget->execute([$targetId]);
        $targetUser = $stmtTarget->fetch();

        if (!$targetUser || $targetUser['role'] !== 'client') {
            $_SESSION['flash_error'] = 'Klien tidak ditemukan.';
            header("Location: dashboard.php?tab=admin_clients");
            exit;
        }

        if (strlen($newPass) < 6) {
            $_SESSION['flash_error'] = 'Password baru minimal harus 6 karakter.';
            header("Location: {$backUrl}");
            exit;
        }

        if ($newPass !== $confirmPass) {
            $_SESSION['flash_error'] = 'Konfirmasi password baru tidak cocok.';
            header("Location: {$backUrl}");
            exit;
        }

        try {
            $stmtUpPass = $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = datetime('now') WHERE id = ?");
            $stmtUpPass->execute([password_hash($newPass, PASSWORD_DEFAULT), $targetId]);

            $pdo->prepare("
                INSERT INTO activities (user_id, category, title, description, meta_info, status, activity_date, created_at)
                VALUES (?, 'system', 'Password Akun Diperbarui', 'Password akun Anda telah diatur ulang oleh administrator. Gunakan password baru untuk login berikutnya.', 'Keamanan Akun', 'completed', datetime('now'), datetime('now'))
            ")->execute([$targetId]);

            $label = $targetUser['business_name'] ?: $targetUser['name'];
            notify_user($pdo, $targetId, 'account', 'Password akun diatur ulang',
                'Password akun Anda telah diatur ulang oleh administrator. Gunakan password baru untuk login berikutnya.',
                'dashboard.php?tab=profil', $targetId);
            $_SESSION['flash_success'] = "Password akun {$label} berhasil diganti. Sampaikan password baru kepada klien.";
            header("Location: {$backUrl}");
            exit;
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = 'Gagal mengganti password klien: ' . $e->getMessage();
            header("Location: {$backUrl}");
            exit;
        }
    }

    // TANDAI SEMUA NOTIFIKASI SUDAH DIBACA (hanya milik akun yang login)
    if ($action === 'mark_all_notifications_read') {
        $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE recipient_id = ? AND is_read = 0")->execute([$userId]);
        $_SESSION['flash_success'] = 'Semua notifikasi telah ditandai sudah dibaca.';
        header("Location: dashboard.php?tab=notifikasi");
        exit;
    }

    // 1. PEMESANAN PAKET LAYANAN
    if ($action === 'order_package') {
        $packageName = trim($_POST['package_name'] ?? '');
        $billingCycle = $_POST['billing_cycle'] ?? 'monthly';
        $voucherCode = strtoupper(trim($_POST['voucher_code'] ?? ''));
        $notes = trim($_POST['notes'] ?? '');

        // Validasi paket & tarif dasar
        $basePrices = [
            'Basic Maintenance' => 75000,
            'Standard Growth' => 150000,
            'Premium Pro' => 275000,
            'Custom Project & Photoshoot' => 500000,
        ];

        if (!isset($basePrices[$packageName])) {
            $_SESSION['flash_error'] = 'Pilihan paket tidak valid.';
            header("Location: dashboard.php?tab=pesan");
            exit;
        }

        $monthlyPrice = $basePrices[$packageName];
        $amount = $monthlyPrice;
        $discount = 0;

        // Diskon billing tahunan (bayar 10 bulan = hemat 15% + 2 bulan gratis)
        if ($billingCycle === 'yearly') {
            $amount = $monthlyPrice * 12;
            $discount = (int)round($monthlyPrice * 2); // 2 bulan gratis
        }

        // Voucher HEMAT40
        if ($voucherCode === 'HEMAT40') {
            $voucherDiscount = (int)round(($amount - $discount) * 0.40);
            $discount += $voucherDiscount;
        }

        $totalAmount = max(0, $amount - $discount);
        $orderCode = 'ORD-' . date('Ym') . '-' . str_pad((string)rand(100, 9999), 4, '0', STR_PAD_LEFT);

        try {
            $stmtOrder = $pdo->prepare("
                INSERT INTO orders (order_code, user_id, package_name, billing_cycle, amount, discount, total_amount, voucher_code, notes, status, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending_payment', datetime('now'), datetime('now'))
            ");
            $stmtOrder->execute([
                $orderCode,
                $userId,
                $packageName,
                $billingCycle,
                $amount,
                $discount,
                $totalAmount,
                $voucherCode,
                $notes
            ]);
            $orderId = (int)$pdo->lastInsertId();

            // Catat log kegiatan
            $stmtAct = $pdo->prepare("
                INSERT INTO activities (user_id, category, title, description, meta_info, status, activity_date, created_at)
                VALUES (?, 'system', ?, ?, ?, 'completed', datetime('now'), datetime('now'))
            ");
            $stmtAct->execute([
                $userId,
                'Pesanan Paket Dibuat: ' . $packageName,
                'Pesanan berhasil dibuat dengan kode ' . $orderCode . '. Menunggu konfirmasi pembayaran.',
                'Kode: ' . $orderCode . ' | Total: Rp ' . number_format($totalAmount, 0, ',', '.')
            ]);

            // Notifikasi ke admin
            notify_admins($pdo, 'order', 'Pesanan baru: ' . $packageName,
                ($currentUser['business_name'] ?: $currentUser['name']) . ' membuat pesanan ' . $orderCode . ' (Rp ' . number_format($totalAmount, 0, ',', '.') . ').',
                'dashboard.php?tab=admin_invoices', $userId);

            $_SESSION['flash_success'] = "Pesanan paket {$packageName} ({$orderCode}) berhasil dibuat! Silakan lakukan pembayaran dan kirimkan konfirmasi di bawah ini.";
            header("Location: dashboard.php?tab=pembayaran&order_id=" . $orderId);
            exit;
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = 'Gagal membuat pesanan: ' . $e->getMessage();
            header("Location: dashboard.php?tab=pesan");
            exit;
        }
    }

    // 2. KONFIRMASI PEMBAYARAN
    if ($action === 'confirm_payment') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $bankDest = trim($_POST['bank_destination'] ?? '');
        $senderBank = trim($_POST['sender_bank'] ?? '');
        $senderName = trim($_POST['sender_name'] ?? '');
        $transferAmount = (int)preg_replace('/[^0-9]/', '', $_POST['transfer_amount'] ?? '0');
        $transferDate = trim($_POST['transfer_date'] ?? date('Y-m-d'));
        $notes = trim($_POST['notes'] ?? '');

        // Verifikasi kepemilikan pesanan
        $stmtChk = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ? LIMIT 1");
        $stmtChk->execute([$orderId, $userId]);
        $order = $stmtChk->fetch();

        if (!$order) {
            $_SESSION['flash_error'] = 'Pesanan tidak ditemukan atau bukan milik akun Anda.';
            header("Location: dashboard.php?tab=pembayaran");
            exit;
        }

        if ($bankDest === '' || $senderName === '' || $transferAmount <= 0) {
            $_SESSION['flash_error'] = 'Semua kolom bertanda bintang (*) pada konfirmasi pembayaran wajib diisi.';
            header("Location: dashboard.php?tab=pembayaran&order_id=" . $orderId);
            exit;
        }

        // Upload bukti pembayaran (opsional tapi disarankan)
        $proofFileName = null;
        if (!empty($_FILES['proof_file']['name']) && $_FILES['proof_file']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['proof_file']['name'], PATHINFO_EXTENSION));
            $allowedExts = ['jpg', 'jpeg', 'png', 'pdf', 'webp'];
            if (in_array($ext, $allowedExts, true)) {
                $dir = __DIR__ . '/uploads/proofs';
                if (!is_dir($dir)) {
                    mkdir($dir, 0777, true);
                }
                $proofFileName = 'proof_' . $order['order_code'] . '_' . time() . '.' . $ext;
                move_uploaded_file($_FILES['proof_file']['tmp_name'], $dir . '/' . $proofFileName);
            }
        }

        try {
            $stmtPay = $pdo->prepare("
                INSERT INTO payments (order_id, user_id, bank_destination, sender_bank, sender_name, transfer_amount, transfer_date, proof_file, notes, status, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', datetime('now'), datetime('now'))
            ");
            $stmtPay->execute([
                $orderId,
                $userId,
                $bankDest,
                $senderBank,
                $senderName,
                $transferAmount,
                $transferDate,
                $proofFileName,
                $notes
            ]);

            // Ubah status order menjadi reviewing
            $stmtUpOrder = $pdo->prepare("UPDATE orders SET status = 'reviewing', updated_at = datetime('now') WHERE id = ?");
            $stmtUpOrder->execute([$orderId]);

            // Catat log kegiatan
            $stmtAct = $pdo->prepare("
                INSERT INTO activities (user_id, category, title, description, meta_info, status, activity_date, created_at)
                VALUES (?, 'system', ?, ?, ?, 'completed', datetime('now'), datetime('now'))
            ");
            $stmtAct->execute([
                $userId,
                'Konfirmasi Pembayaran Dikirim: ' . $order['order_code'],
                'Konfirmasi transfer Rp ' . number_format($transferAmount, 0, ',', '.') . ' via ' . $bankDest . ' atas nama ' . $senderName . ' telah diterima.',
                'Bank: ' . $bankDest . ' | Status: Menunggu Verifikasi'
            ]);

            // Notifikasi ke admin
            notify_admins($pdo, 'payment', 'Konfirmasi pembayaran masuk',
                ($currentUser['business_name'] ?: $currentUser['name']) . ' mengirim konfirmasi transfer Rp ' . number_format($transferAmount, 0, ',', '.') . ' untuk pesanan ' . $order['order_code'] . '.',
                'dashboard.php?tab=admin_payments', $userId);

            $_SESSION['flash_success'] = "Konfirmasi pembayaran untuk pesanan {$order['order_code']} berhasil dikirim! Tim finance kami akan memverifikasi dalam 1x24 jam.";
            header("Location: dashboard.php?tab=pembayaran");
            exit;
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = 'Gagal menyimpan konfirmasi pembayaran: ' . $e->getMessage();
            header("Location: dashboard.php?tab=pembayaran&order_id=" . $orderId);
            exit;
        }
    }

    // 3. UPDATE PROFIL USER & BISNIS
    if ($action === 'update_profile') {
        $name = trim($_POST['name'] ?? '');
        $businessName = trim($_POST['business_name'] ?? '');
        $whatsapp = trim($_POST['whatsapp'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $websiteUrl = trim($_POST['website_url'] ?? '');
        $instagramHandle = trim($_POST['instagram_handle'] ?? '');

        if ($name === '' || $businessName === '' || $whatsapp === '' || $email === '') {
            $_SESSION['flash_error'] = 'Nama, Nama Usaha, WhatsApp, dan Email wajib diisi.';
            header("Location: dashboard.php?tab=profil");
            exit;
        }

        // Cek duplikasi email ke user lain
        $stmtChkEmail = $pdo->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(?) AND id != ? LIMIT 1");
        $stmtChkEmail->execute([$email, $userId]);
        if ($stmtChkEmail->fetch()) {
            $_SESSION['flash_error'] = 'Alamat email sudah digunakan oleh pengguna lain.';
            header("Location: dashboard.php?tab=profil");
            exit;
        }

        try {
            $stmtUpProf = $pdo->prepare("
                UPDATE users 
                SET name = ?, business_name = ?, whatsapp = ?, email = ?, website_url = ?, instagram_handle = ?, updated_at = datetime('now')
                WHERE id = ?
            ");
            $stmtUpProf->execute([$name, $businessName, $whatsapp, strtolower($email), $websiteUrl, $instagramHandle, $userId]);

            $_SESSION['user_name'] = $name;
            $_SESSION['business_name'] = $businessName;
            $_SESSION['user_email'] = $email;
            $_SESSION['user_whatsapp'] = $whatsapp;

            $_SESSION['flash_success'] = 'Profil akun usaha Anda berhasil diperbarui!';
            header("Location: dashboard.php?tab=profil");
            exit;
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = 'Gagal memperbarui profil: ' . $e->getMessage();
            header("Location: dashboard.php?tab=profil");
            exit;
        }
    }

    // 4. GANTI PASSWORD
    if ($action === 'change_password') {
        $oldPass = $_POST['old_password'] ?? '';
        $newPass = $_POST['new_password'] ?? '';
        $confirmPass = $_POST['confirm_password'] ?? '';

        if (!password_verify($oldPass, $currentUser['password_hash'])) {
            $_SESSION['flash_error'] = 'Password saat ini yang Anda masukkan salah.';
            header("Location: dashboard.php?tab=profil");
            exit;
        }

        if (strlen($newPass) < 6) {
            $_SESSION['flash_error'] = 'Password baru minimal harus 6 karakter.';
            header("Location: dashboard.php?tab=profil");
            exit;
        }

        if ($newPass !== $confirmPass) {
            $_SESSION['flash_error'] = 'Konfirmasi password baru tidak cocok.';
            header("Location: dashboard.php?tab=profil");
            exit;
        }

        try {
            $hash = password_hash($newPass, PASSWORD_DEFAULT);
            $stmtPass = $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = datetime('now') WHERE id = ?");
            $stmtPass->execute([$hash, $userId]);

            $_SESSION['flash_success'] = 'Password akun berhasil diubah. Gunakan password baru untuk login berikutnya.';
            header("Location: dashboard.php?tab=profil");
            exit;
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = 'Gagal mengubah password: ' . $e->getMessage();
            header("Location: dashboard.php?tab=profil");
            exit;
        }
    }

    // 5. AJUKAN PERMINTAAN KONTEN / REVISI
    if ($action === 'request_content') {
        $category = $_POST['category'] ?? 'article';
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if ($title === '') {
            $_SESSION['flash_error'] = 'Judul / topik permintaan materi wajib diisi.';
            header("Location: dashboard.php?tab=laporan");
            exit;
        }

        try {
            $stmtAct = $pdo->prepare("
                INSERT INTO activities (user_id, category, title, description, meta_info, status, activity_date, created_at)
                VALUES (?, ?, ?, ?, 'Permintaan Mandiri oleh Klien', 'review', datetime('now'), datetime('now'))
            ");
            $stmtAct->execute([$userId, $category, $title, $description]);

            // Notifikasi ke admin
            notify_admins($pdo, 'request', 'Permintaan materi dari klien',
                ($currentUser['business_name'] ?: $currentUser['name']) . ': ' . $title,
                'dashboard.php?tab=admin_client_detail&client_id=' . $userId, $userId);

            $_SESSION['flash_success'] = 'Permintaan materi/revisi berhasil dicatat! Tim kami akan meninjau dan mengeksekusi segera.';
            header("Location: dashboard.php?tab=laporan");
            exit;
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = 'Gagal mengirim permintaan: ' . $e->getMessage();
            header("Location: dashboard.php?tab=laporan");
            exit;
        }
    }
}

// --------------------------------------------------------------------------
// PENGAMBILAN DATA KHUSUS MILIK USER YANG LOGIN (STRICT SCOPING)
// --------------------------------------------------------------------------

// 1. Pesanan milik user ini
$stmtOrders = $pdo->prepare("SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC");
$stmtOrders->execute([$userId]);
$myOrders = $stmtOrders->fetchAll();

// 2. Pembayaran milik user ini
$stmtPayments = $pdo->prepare("
    SELECT p.*, o.order_code, o.package_name, o.billing_cycle 
    FROM payments p 
    JOIN orders o ON p.order_id = o.id 
    WHERE p.user_id = ? 
    ORDER BY p.id DESC
");
$stmtPayments->execute([$userId]);
$myPayments = $stmtPayments->fetchAll();

// 3. Laporan kegiatan milik user ini
$stmtActs = $pdo->prepare("SELECT * FROM activities WHERE user_id = ? ORDER BY id DESC");
$stmtActs->execute([$userId]);
$myActivities = $stmtActs->fetchAll();

// 4. Prospek Leads WhatsApp milik user ini
$stmtLeads = $pdo->prepare("SELECT * FROM leads WHERE user_id = ? ORDER BY id DESC");
$stmtLeads->execute([$userId]);
$myLeads = $stmtLeads->fetchAll();

// Jika user baru belum memiliki aktivitas awal, buatkan 1 catatan penyambutan
if (empty($myActivities) && empty($myOrders)) {
    $pdo->prepare("
        INSERT INTO activities (user_id, category, title, description, meta_info, status, activity_date, created_at)
        VALUES (?, 'system', 'Akun Portal Klien Berhasil Didaftarkan', 'Selamat datang di OkeIniAja! Anda dapat memilih dan memesan paket pengelolaan untuk memulai.', 'Portal Klien Mandiri', 'completed', datetime('now'), datetime('now'))
    ")->execute([$userId]);

    $stmtActs->execute([$userId]);
    $myActivities = $stmtActs->fetchAll();
}

// Hitung metrik ringkas & Metrik Invoice
// Ambil data pekerjaan artikel & ceklis (job_activities)
$stmtMyJobs = $pdo->prepare("SELECT * FROM job_activities WHERE user_id = ? ORDER BY upload_date ASC, id DESC");
$stmtMyJobs->execute([$userId]);
$myJobActivities = $stmtMyJobs->fetchAll();

$myJobsTotal = count($myJobActivities);
$myJobsInProgress = 0;
$myJobsScheduled = 0;
$myJobsCompleted = 0;

foreach ($myJobActivities as $jb) {
    if ($jb['status'] === 'completed') $myJobsCompleted++;
    elseif ($jb['status'] === 'scheduled') $myJobsScheduled++;
    else $myJobsInProgress++;
}

// Untuk Administrator: ambil data pekerjaan semua klien
$allJobActivities = [];
$adminJobFilterClient = (int)($_GET['client_id'] ?? 0);
if ($isAdmin) {
    if ($adminJobFilterClient > 0) {
        $stmtAllJobs = $pdo->prepare("
            SELECT j.*, u.id as client_id, u.name as client_name, u.business_name as client_business, u.whatsapp as client_whatsapp, u.package_active as client_package
            FROM job_activities j 
            JOIN users u ON j.user_id = u.id 
            WHERE j.user_id = ?
            ORDER BY j.upload_date ASC, j.id DESC
        ");
        $stmtAllJobs->execute([$adminJobFilterClient]);
    } else {
        $stmtAllJobs = $pdo->query("
            SELECT j.*, u.id as client_id, u.name as client_name, u.business_name as client_business, u.whatsapp as client_whatsapp, u.package_active as client_package
            FROM job_activities j 
            JOIN users u ON j.user_id = u.id 
            ORDER BY j.upload_date ASC, j.id DESC
        ");
    }
    $allJobActivities = $stmtAllJobs->fetchAll();
}

$countArticles = 0;
$countMedsos = 0;
foreach ($myActivities as $act) {
    if ($act['category'] === 'article') $countArticles++;
    if ($act['category'] === 'social_media') $countMedsos++;
}
$countLeads = count($myLeads);
$countPendingOrders = 0;
$hasActivePackage = false;
$activePackageName = $currentUser['package_active'] ?? null;

// Metrik Modul Invoice
$totalInvoiceCount = count($myOrders);
$totalInvoiceAmount = 0;
$paidInvoiceCount = 0;
$paidInvoiceAmount = 0;
$pendingInvoiceCount = 0;
$pendingInvoiceAmount = 0;

$unpaidInvoices = [];
$paidInvoices = [];

foreach ($myOrders as $ord) {
    $totalInvoiceAmount += (int)$ord['total_amount'];

    if ($ord['status'] === 'pending_payment' || $ord['status'] === 'reviewing') {
        $countPendingOrders++;
        $pendingInvoiceCount++;
        $pendingInvoiceAmount += (int)$ord['total_amount'];
        $unpaidInvoices[] = $ord;
    } elseif ($ord['status'] === 'active') {
        $hasActivePackage = true;
        $activePackageName = $ord['package_name'];
        $paidInvoiceCount++;
        $paidInvoiceAmount += (int)$ord['total_amount'];
        $paidInvoices[] = $ord;
    }
}

// =========================================================================
// DATA FETCHING UNTUK ADMINISTRATOR (GLOBAL MULTI-TENANT VIEW)
// =========================================================================
$allClients = [];
$allPayments = [];
$allActivities = [];
$allOrders = [];
$adminTotalClients = 0;
$adminActiveClients = 0;
$adminPendingPaymentsCount = 0;
$adminTotalVerifiedRevenue = 0;
$selectedClientId = (int)($_GET['client_id'] ?? 0);

// Paket klien yang sedang login (dipakai untuk kuota & ceklis di menu Aktivitas)
$clientFeatures = package_features($currentUser['package_active'] ?: $activePackageName);
$bulanId = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$monthLabelId = $bulanId[(int)date('n')] . ' ' . date('Y');

// Notifikasi milik akun yang login (admin: dari seluruh klien, klien: miliknya saja)
$stmtNU = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE recipient_id = ? AND is_read = 0");
$stmtNU->execute([$userId]);
$notificationCount = (int)$stmtNU->fetchColumn();
$stmtNR = $pdo->prepare("
    SELECT n.*, u.business_name AS client_business, u.name AS client_name
    FROM notifications n
    LEFT JOIN users u ON u.id = n.client_id
    WHERE n.recipient_id = ?
    ORDER BY n.id DESC
    LIMIT 5
");
$stmtNR->execute([$userId]);
$notifRecent = $stmtNR->fetchAll();

if ($isAdmin) {
    // 1. Ambil seluruh klien (role = 'client')
    $stmtAllClients = $pdo->query("
        SELECT u.*, 
               (SELECT COUNT(*) FROM orders o WHERE o.user_id = u.id) as total_orders,
               (SELECT COUNT(*) FROM activities a WHERE a.user_id = u.id) as total_activities,
               (SELECT COALESCE(SUM(o.total_amount), 0) FROM orders o WHERE o.user_id = u.id AND o.status = 'active') as total_spend
        FROM users u 
        WHERE u.role = 'client' 
        ORDER BY u.id DESC
    ");
    $allClients = $stmtAllClients->fetchAll();
    $adminTotalClients = count($allClients);

    // 2. Ambil seluruh pembayaran & rincian pesanan
    $stmtAllPayments = $pdo->query("
        SELECT p.*, o.order_code, o.package_name, o.billing_cycle, o.total_amount,
               u.id as client_id, u.name as client_name, u.business_name as client_business, u.whatsapp as client_whatsapp, u.email as client_email
        FROM payments p
        JOIN orders o ON p.order_id = o.id
        JOIN users u ON p.user_id = u.id
        ORDER BY p.id DESC
    ");
    $allPayments = $stmtAllPayments->fetchAll();

    // 3. Ambil seluruh kegiatan klien
    $stmtAllActivities = $pdo->query("
        SELECT a.*, u.id as client_id, u.name as client_name, u.business_name as client_business
        FROM activities a
        JOIN users u ON a.user_id = u.id
        ORDER BY a.id DESC
    ");
    $allActivities = $stmtAllActivities->fetchAll();

    // 4. Ambil seluruh pesanan untuk rekap invoice admin
    $stmtAllOrders = $pdo->query("
        SELECT o.*, u.id as client_id, u.name as client_name, u.business_name as client_business, u.whatsapp as client_whatsapp
        FROM orders o
        JOIN users u ON o.user_id = u.id
        ORDER BY o.id DESC
    ");
    $allOrders = $stmtAllOrders->fetchAll();

    // Hitung metrik admin
    foreach ($allClients as $cl) {
        if (!empty($cl['package_active'])) {
            $adminActiveClients++;
        }
    }

    foreach ($allPayments as $pay) {
        if ($pay['status'] === 'reviewing' || $pay['status'] === 'pending') {
            $adminPendingPaymentsCount++;
        }
        if ($pay['status'] === 'verified') {
            $adminTotalVerifiedRevenue += (int)$pay['transfer_amount'];
        }
    }
}

$invoiceFilter = $_GET['filter'] ?? 'all'; // 'all', 'unpaid', 'paid'
$activeTab = $_GET['tab'] ?? ($isAdmin ? 'admin_clients' : 'ringkasan');
$selectedOrderId = (int)($_GET['order_id'] ?? 0);

// Daftar notifikasi pada halaman Notifikasi (hanya milik akun yang login)
$notifFilter = (($_GET['filter'] ?? 'all') === 'unread') ? 'unread' : 'all';
$notifList = [];
$notifTotal = 0;
$notifPage = max(1, (int)($_GET['page'] ?? 1));
$notifPerPage = 20;
$notifPages = 1;
if ($activeTab === 'notifikasi') {
    $notifWhere = 'n.recipient_id = ?' . ($notifFilter === 'unread' ? ' AND n.is_read = 0' : '');
    $stmtNC = $pdo->prepare("SELECT COUNT(*) FROM notifications n WHERE {$notifWhere}");
    $stmtNC->execute([$userId]);
    $notifTotal = (int)$stmtNC->fetchColumn();
    $notifPages = max(1, (int)ceil($notifTotal / $notifPerPage));
    $notifPage = min($notifPage, $notifPages);

    $stmtNL = $pdo->prepare("
        SELECT n.*, u.business_name AS client_business, u.name AS client_name
        FROM notifications n
        LEFT JOIN users u ON u.id = n.client_id
        WHERE {$notifWhere}
        ORDER BY n.id DESC
        LIMIT {$notifPerPage} OFFSET " . (($notifPage - 1) * $notifPerPage)
    );
    $stmtNL->execute([$userId]);
    $notifList = $stmtNL->fetchAll();
}

// Detail klien (khusus admin): kumpulkan data satu klien
$detailClient = null;
$detailOrders = $detailPayments = $detailActivities = $detailJobs = [];
$detailLeadsCount = 0;
$detailFeatures = package_features(null);
if ($isAdmin && $activeTab === 'admin_client_detail') {
    $detailId = (int)($_GET['client_id'] ?? 0);
    foreach ($allClients as $cl) {
        if ((int)$cl['id'] === $detailId) {
            $detailClient = $cl;
            break;
        }
    }
    if (!$detailClient) {
        $_SESSION['flash_error'] = 'Klien tidak ditemukan.';
        header("Location: dashboard.php?tab=admin_clients");
        exit;
    }

    $detailOrders = array_values(array_filter($allOrders, static fn($o) => (int)$o['client_id'] === $detailId));
    $detailPayments = array_values(array_filter($allPayments, static fn($p) => (int)$p['client_id'] === $detailId));
    $detailActivities = array_slice(array_values(array_filter($allActivities, static fn($a) => (int)$a['client_id'] === $detailId)), 0, 8);

    $stmtDJ = $pdo->prepare("SELECT * FROM job_activities WHERE user_id = ? ORDER BY upload_date DESC, id DESC");
    $stmtDJ->execute([$detailId]);
    $detailJobs = $stmtDJ->fetchAll();

    $stmtDL = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE user_id = ?");
    $stmtDL->execute([$detailId]);
    $detailLeadsCount = (int)$stmtDL->fetchColumn();

    $detailFeatures = package_features($detailClient['package_active'] ?: null);
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>Portal Klien - <?= htmlspecialchars($currentUser['business_name'] ?: $currentUser['name']) ?> | <?= $site_name ?></title>
   <meta name="robots" content="noindex, nofollow">

   <link rel="preconnect" href="https://fonts.googleapis.com">
   <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
   <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

   <script src="https://cdn.tailwindcss.com"></script>
   <script>
      tailwind.config = {
         theme: {
            extend: {
               fontFamily: { sans: ['"Plus Jakarta Sans"', 'Inter', 'sans-serif'] },
               colors: {
                  brand: {
                     50: '#fff5f2', 100: '#ffe8e2', 200: '#ffd4c8', 300: '#ffb3a1', 400: '#f88266',
                     500: '#ea6043', 600: '#d54e32', 700: '#b53d25', 800: '#953420', 900: '#7c2f1e', 950: '#44140b',
                  }
               }
            }
         }
      }
   </script>
   <style>
      .no-scrollbar::-webkit-scrollbar { display: none; }
      .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
      @media print {
         body * { visibility: hidden; }
         #invoicePrintArea, #invoicePrintArea * { visibility: visible; }
         #invoicePrintArea { position: absolute; left: 0; top: 0; width: 100%; }
      }
   </style>
</head>

<body class="font-sans text-slate-800 antialiased bg-slate-100/70 min-h-screen flex flex-col">

   <!-- MOBILE BACKDROP OVERLAY -->
   <div id="sidebarBackdrop" onclick="toggleSidebar()" class="fixed inset-0 bg-slate-950/60 backdrop-blur-sm z-40 hidden lg:hidden transition-opacity"></div>

   <!-- SIDEBAR NAVIGATION -->
   <aside id="sidebar" class="fixed top-0 bottom-0 left-0 z-50 w-64 bg-slate-950 text-white flex flex-col justify-between transition-transform duration-300 ease-in-out -translate-x-full lg:translate-x-0 border-r border-slate-800/80 shadow-2xl lg:shadow-none select-none">
      
      <!-- Top Section -->
      <div class="flex flex-col flex-1 overflow-y-auto no-scrollbar">
         
         <!-- Brand / Logo -->
         <div class="p-5 flex items-center justify-between border-b border-slate-800/80">
            <a href="<?= $site_url ?>" target="_blank" class="flex items-center gap-2.5 group">
               <span class="w-9 h-9 rounded-xl bg-gradient-to-tr from-brand-600 to-orange-500 flex items-center justify-center text-white font-black text-base shadow-md shadow-brand-600/30 group-hover:scale-105 transition-transform">O</span>
               <div class="flex flex-col">
                  <span class="font-extrabold text-white tracking-tight text-lg leading-tight group-hover:text-brand-400 transition-colors">OkeIni<span class="text-brand-500">Aja</span></span>
                  <span class="text-[10px] uppercase font-bold tracking-widest text-slate-400">Portal Klien</span>
               </div>
            </a>
            <button onclick="toggleSidebar()" class="lg:hidden p-1.5 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 focus:outline-none" aria-label="Tutup Menu">
               <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
               </svg>
            </button>
         </div>

         <!-- User & Business Quick Card -->
         <div class="p-4 mx-3 my-4 rounded-2xl bg-gradient-to-br from-slate-900 to-slate-850 border border-slate-800/90 shadow-inner">
            <div class="flex items-center gap-3">
               <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-brand-500 to-orange-600 text-white flex items-center justify-center font-black text-base shadow-md shadow-brand-500/20 shrink-0">
                  <?= strtoupper(substr($currentUser['name'], 0, 1)) ?>
               </div>
               <div class="min-w-0 flex-1">
                  <h4 class="text-xs font-bold text-white truncate"><?= htmlspecialchars($currentUser['business_name'] ?: $currentUser['name']) ?></h4>
                  <p class="text-[11px] text-slate-400 truncate"><?= htmlspecialchars($currentUser['name']) ?></p>
               </div>
            </div>
            <div class="mt-3 pt-2.5 border-t border-slate-800 flex items-center justify-between text-[11px]">
               <span class="inline-flex items-center gap-1.5 text-slate-300 font-medium">
                  <span class="w-2 h-2 rounded-full <?= $hasActivePackage ? 'bg-emerald-400 animate-pulse' : 'bg-amber-400' ?>"></span>
                  <?= $hasActivePackage ? 'Paket Aktif' : 'Belum Berlangganan' ?>
               </span>
               <span class="text-slate-500 font-mono text-[10px]">#<?= $currentUser['id'] ?></span>
            </div>
         </div>

         <!-- Navigation Links -->
         <div class="px-3 py-2">
            <?php if ($isAdmin): ?>
               <span class="px-3 text-[10px] font-extrabold uppercase tracking-widest text-brand-400 flex items-center gap-1.5">
                  <span>👑</span> PANEL ADMINISTRATOR
               </span>
               <nav class="mt-2 space-y-1 text-xs">
                  <!-- 1. Semua Klien -->
                  <a href="dashboard.php?tab=admin_clients" class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl transition-all font-semibold <?= in_array($activeTab, ['admin_clients', 'admin_client_detail'], true) ? 'bg-brand-600 text-white shadow-lg shadow-brand-600/30' : 'text-slate-300 hover:text-white hover:bg-slate-900' ?>">
                     <svg class="w-4 h-4 shrink-0 <?= in_array($activeTab, ['admin_clients', 'admin_client_detail'], true) ? 'text-white' : 'text-slate-400' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                     </svg>
                     <span>Daftar Klien (<?= count($allClients ?? []) ?>)</span>
                  </a>

                  <!-- 2. Verifikasi Pembayaran -->
                  <a href="dashboard.php?tab=admin_payments" class="flex items-center justify-between px-3.5 py-2.5 rounded-xl transition-all font-semibold <?= $activeTab === 'admin_payments' ? 'bg-brand-600 text-white shadow-lg shadow-brand-600/30' : 'text-slate-300 hover:text-white hover:bg-slate-900' ?>">
                     <div class="flex items-center gap-3">
                        <svg class="w-4 h-4 shrink-0 <?= $activeTab === 'admin_payments' ? 'text-white' : 'text-slate-400' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                           <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <span>Verifikasi Bayar</span>
                     </div>
                     <?php if (($adminPendingPaymentsCount ?? 0) > 0): ?>
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-black bg-rose-500 text-white shadow-sm animate-pulse">
                           <?= $adminPendingPaymentsCount ?>
                        </span>
                     <?php endif; ?>
                  </a>

                  <!-- 3. Aktivitas Pekerjaan & Ceklis Artikel (NEW) -->
                  <a href="dashboard.php?tab=aktivitas" class="flex items-center justify-between px-3.5 py-2.5 rounded-xl transition-all font-semibold <?= $activeTab === 'aktivitas' ? 'bg-brand-600 text-white shadow-lg shadow-brand-600/30' : 'text-slate-300 hover:text-white hover:bg-slate-900' ?>">
                     <div class="flex items-center gap-3">
                        <svg class="w-4 h-4 shrink-0 <?= $activeTab === 'aktivitas' ? 'text-white' : 'text-slate-400' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                           <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
                        </svg>
                        <span>Aktivitas &amp; Ceklis</span>
                     </div>
                     <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-800 text-slate-300">
                        <?= count($allJobActivities ?? []) ?>
                     </span>
                  </a>

                  <!-- 4. Log Kegiatan Sistem -->
                  <a href="dashboard.php?tab=admin_activities" class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl transition-all font-semibold <?= $activeTab === 'admin_activities' ? 'bg-brand-600 text-white shadow-lg shadow-brand-600/30' : 'text-slate-300 hover:text-white hover:bg-slate-900' ?>">
                     <svg class="w-4 h-4 shrink-0 <?= $activeTab === 'admin_activities' ? 'text-white' : 'text-slate-400' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                     </svg>
                     <span>Kelola Kegiatan</span>
                  </a>

                  <!-- 4. Semua Invoice -->
                  <a href="dashboard.php?tab=admin_invoices" class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl transition-all font-semibold <?= $activeTab === 'admin_invoices' ? 'bg-brand-600 text-white shadow-lg shadow-brand-600/30' : 'text-slate-300 hover:text-white hover:bg-slate-900' ?>">
                     <svg class="w-4 h-4 shrink-0 <?= $activeTab === 'admin_invoices' ? 'text-white' : 'text-slate-400' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2zM10 8h4"/>
                     </svg>
                     <span>Semua Invoice</span>
                  </a>

                  <?php render_notif_nav($activeTab, $notificationCount); ?>

                   <!-- 5. Profil Admin -->
                  <a href="dashboard.php?tab=profil" class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl transition-all font-semibold <?= $activeTab === 'profil' ? 'bg-brand-600 text-white shadow-lg shadow-brand-600/30' : 'text-slate-300 hover:text-white hover:bg-slate-900' ?>">
                     <svg class="w-4 h-4 shrink-0 <?= $activeTab === 'profil' ? 'text-white' : 'text-slate-400' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                     </svg>
                     <span>Profil Admin</span>
                  </a>
               </nav>
            <?php else: ?>
               <span class="px-3 text-[10px] font-extrabold uppercase tracking-widest text-slate-500">Menu Navigasi</span>
               <nav class="mt-2 space-y-1 text-xs">
                  <!-- 1. Ringkasan -->
                  <a href="dashboard.php?tab=ringkasan" class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl transition-all font-semibold <?= $activeTab === 'ringkasan' ? 'bg-brand-600 text-white shadow-lg shadow-brand-600/30' : 'text-slate-300 hover:text-white hover:bg-slate-900' ?>">
                     <svg class="w-4 h-4 shrink-0 <?= $activeTab === 'ringkasan' ? 'text-white' : 'text-slate-400' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/>
                     </svg>
                     <span>Ringkasan Akun</span>
                  </a>

                  <!-- 2. Pesan Layanan Paket -->
                  <a href="dashboard.php?tab=pesan" class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl transition-all font-semibold <?= $activeTab === 'pesan' ? 'bg-brand-600 text-white shadow-lg shadow-brand-600/30' : 'text-slate-300 hover:text-white hover:bg-slate-900' ?>">
                     <svg class="w-4 h-4 shrink-0 <?= $activeTab === 'pesan' ? 'text-white' : 'text-slate-400' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
                     </svg>
                     <span>Pesan Layanan Paket</span>
                  </a>

                  <!-- 3. Menu Invoice & Tagihan -->
                  <a href="dashboard.php?tab=invoice" class="flex items-center justify-between px-3.5 py-2.5 rounded-xl transition-all font-semibold <?= $activeTab === 'invoice' ? 'bg-brand-600 text-white shadow-lg shadow-brand-600/30' : 'text-slate-300 hover:text-white hover:bg-slate-900' ?>">
                     <div class="flex items-center gap-3">
                        <svg class="w-4 h-4 shrink-0 <?= $activeTab === 'invoice' ? 'text-white' : 'text-slate-400' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                           <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2zM10 8h4"/>
                        </svg>
                        <span>Daftar Invoice</span>
                     </div>
                     <?php if ($pendingInvoiceCount > 0): ?>
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-black bg-amber-500 text-slate-950 shadow-sm" title="<?= $pendingInvoiceCount ?> tagihan belum lunas">
                           <?= $pendingInvoiceCount ?>
                        </span>
                     <?php endif; ?>
                  </a>

                  <!-- 4. Konfirmasi Bayar -->
                  <a href="dashboard.php?tab=pembayaran" class="flex items-center justify-between px-3.5 py-2.5 rounded-xl transition-all font-semibold <?= $activeTab === 'pembayaran' ? 'bg-brand-600 text-white shadow-lg shadow-brand-600/30' : 'text-slate-300 hover:text-white hover:bg-slate-900' ?>">
                     <div class="flex items-center gap-3">
                        <svg class="w-4 h-4 shrink-0 <?= $activeTab === 'pembayaran' ? 'text-white' : 'text-slate-400' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                           <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/>
                        </svg>
                        <span>Konfirmasi Bayar</span>
                     </div>
                     <?php if ($countPendingOrders > 0): ?>
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-black bg-rose-500 text-white shadow-sm animate-pulse">
                           <?= $countPendingOrders ?>
                        </span>
                     <?php endif; ?>
                  </a>

                  <!-- 5. Menu Aktivitas Pekerjaan (NEW) -->
                  <a href="dashboard.php?tab=aktivitas" class="flex items-center justify-between px-3.5 py-2.5 rounded-xl transition-all font-semibold <?= $activeTab === 'aktivitas' ? 'bg-brand-600 text-white shadow-lg shadow-brand-600/30' : 'text-slate-300 hover:text-white hover:bg-slate-900' ?>">
                     <div class="flex items-center gap-3">
                        <svg class="w-4 h-4 shrink-0 <?= $activeTab === 'aktivitas' ? 'text-white' : 'text-slate-400' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                           <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
                        </svg>
                        <span>Aktivitas</span>
                     </div>
                     <?php if ($myJobsTotal > 0): ?>
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold <?= $myJobsInProgress > 0 ? 'bg-amber-500/20 text-amber-300 border border-amber-500/30' : 'bg-slate-800 text-slate-300' ?>">
                           <?= $myJobsTotal ?>
                        </span>
                     <?php endif; ?>
                  </a>

                  <!-- 6. Laporan Kegiatan -->
                  <a href="dashboard.php?tab=laporan" class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl transition-all font-semibold <?= $activeTab === 'laporan' ? 'bg-brand-600 text-white shadow-lg shadow-brand-600/30' : 'text-slate-300 hover:text-white hover:bg-slate-900' ?>">
                     <svg class="w-4 h-4 shrink-0 <?= $activeTab === 'laporan' ? 'text-white' : 'text-slate-400' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                     </svg>
                     <span>Laporan Kegiatan</span>
                  </a>

                  <?php render_notif_nav($activeTab, $notificationCount); ?>

                   <!-- 6. Profil User -->
                  <a href="dashboard.php?tab=profil" class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl transition-all font-semibold <?= $activeTab === 'profil' ? 'bg-brand-600 text-white shadow-lg shadow-brand-600/30' : 'text-slate-300 hover:text-white hover:bg-slate-900' ?>">
                     <svg class="w-4 h-4 shrink-0 <?= $activeTab === 'profil' ? 'text-white' : 'text-slate-400' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                     </svg>
                     <span>Profil Pengguna</span>
                  </a>
               </nav>
            <?php endif; ?>
         </div>

      </div>

      <!-- Bottom / Help & Logout -->
      <div class="p-3 border-t border-slate-800/80 space-y-2">
         <!-- Help Card -->
         <div class="p-3 rounded-xl bg-slate-900/90 border border-slate-800 text-xs">
            <div class="flex items-center gap-2 mb-1">
               <span class="w-2 h-2 rounded-full bg-emerald-400"></span>
               <span class="font-bold text-white text-[11px]">Bantuan Support</span>
            </div>
            <p class="text-[10px] text-slate-400 mb-2 leading-relaxed">Ada kendala teknis atau pertanyaan paket?</p>
            <a href="https://wa.me/<?= $contact_wa ?>?text=Halo%20Admin%20OkeIniAja,%20saya%20klien%20<?= urlencode($currentUser['business_name'] ?: $currentUser['name']) ?>%20ingin%20konsultasi." target="_blank"
               class="w-full py-2 px-3 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white font-bold text-[11px] flex items-center justify-center gap-1.5 transition-colors shadow-sm">
               <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24">
                  <path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981z"/>
               </svg>
               <span>Chat WhatsApp CS</span>
            </a>
         </div>

         <!-- Logout Button -->
         <a href="logout.php" data-confirm="Apakah Anda yakin ingin keluar dari Portal Klien?" data-confirm-ok="Ya, Keluar"
            class="w-full py-2.5 px-3 rounded-xl text-slate-400 hover:text-rose-400 hover:bg-rose-950/40 border border-transparent hover:border-rose-900/50 text-xs font-semibold flex items-center gap-2.5 transition-all">
            <svg class="w-4 h-4 text-slate-500 group-hover:text-rose-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
               <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
            </svg>
            <span>Keluar Akun</span>
         </a>
      </div>

   </aside>

   <!-- MAIN CONTENT LAYOUT WRAPPER (OFFSET BY SIDEBAR ON LG) -->
   <div class="lg:pl-64 flex flex-col min-h-screen flex-1">
      
      <!-- TOP BAR IN MAIN CONTENT -->
      <header class="sticky top-0 z-30 bg-white/90 backdrop-blur-md border-b border-slate-200/80 px-4 sm:px-6 lg:px-8 py-3.5 flex items-center justify-between shadow-xs">
         <div class="flex items-center gap-3">
            <!-- Mobile Hamburger Toggle -->
            <button onclick="toggleSidebar()" class="lg:hidden p-2 rounded-xl text-slate-600 hover:bg-slate-100 hover:text-slate-900 focus:outline-none transition-colors" aria-label="Buka Menu Sidebar">
               <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
               </svg>
            </button>

            <!-- Breadcrumb / Active Tab Title -->
            <div class="flex items-center gap-2 text-xs">
               <span class="text-slate-400 font-medium hidden sm:inline">Portal Klien</span>
               <span class="text-slate-300 hidden sm:inline">/</span>
               <span class="font-extrabold text-slate-900 text-sm sm:text-base">
                  <?php
                     switch($activeTab) {
                        case 'aktivitas': echo '🚀 Aktivitas Pekerjaan & Ceklis Artikel'; break;
                        case 'admin_clients': echo '👥 Kelola Seluruh Klien'; break;
                         case 'admin_client_detail': echo '👤 Detail Klien'; break;
                         case 'notifikasi': echo '🔔 Log Notifikasi'; break;
                        case 'admin_payments': echo '💳 Verifikasi Pembayaran Klien'; break;
                        case 'admin_activities': echo '📝 Pengisian &amp; Log Kegiatan Klien'; break;
                        case 'admin_invoices': echo '🧾 Rekap Seluruh Invoice &amp; Pesanan'; break;
                        case 'pesan': echo '🛒 Pesan Layanan Paket'; break;
                        case 'invoice': echo '🧾 Tagihan &amp; Invoice Resmi'; break;
                        case 'pembayaran': echo '💳 Konfirmasi Pembayaran'; break;
                        case 'laporan': echo '📋 Laporan Kegiatan &amp; Konten'; break;
                        case 'profil': echo '👤 Profil Pengguna &amp; Keamanan'; break;
                        default: echo '📊 Ringkasan Akun'; break;
                     }
                  ?>
               </span>
            </div>
         </div>

         <!-- Top Right Actions & Status -->
         <div class="flex items-center gap-3">
            <span class="hidden md:inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-slate-100 text-slate-700 border border-slate-200">
               <span class="w-2 h-2 rounded-full <?= $hasActivePackage ? 'bg-emerald-500 animate-ping' : 'bg-amber-400' ?>"></span>
               <?= $hasActivePackage ? 'Paket: ' . htmlspecialchars((string)$activePackageName) : 'Belum Berlangganan' ?>
            </span>

            <!-- Notifikasi (Lonceng) -->
             <div class="relative">
                <button type="button" id="notifBell" onclick="toggleNotifPanel(event)"
                   class="relative p-2 rounded-xl text-slate-600 hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-brand-200 transition-colors"
                   aria-label="Notifikasi" aria-haspopup="true" aria-expanded="false">
                   <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                   </svg>
                   <span id="notifBadge"
                      class="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] px-1 rounded-full text-[10px] font-extrabold flex items-center justify-center ring-2 ring-white <?= $notificationCount > 0 ? 'bg-rose-500 text-white' : 'bg-slate-200 text-slate-500' ?>">
                      <?= $notificationCount > 99 ? '99+' : (int)$notificationCount ?>
                   </span>
                </button>

                <div id="notifPanel" class="hidden absolute right-0 mt-2 w-80 max-w-[calc(100vw-2rem)] bg-white rounded-2xl border border-slate-200 shadow-xl z-40 overflow-hidden">
                   <div class="px-4 py-3 border-b border-slate-100 flex items-center justify-between bg-slate-50/70">
                      <span class="text-xs font-extrabold text-slate-900">Notifikasi</span>
                      <span class="text-[11px] font-semibold text-slate-400"><?= (int)$notificationCount ?> belum dibaca</span>
                   </div>
                   <?php if (empty($notifRecent)): ?>
                      <div class="px-4 py-10 text-center">
                         <div class="w-10 h-10 mx-auto mb-2 rounded-xl bg-slate-100 text-slate-400 flex items-center justify-center text-lg">🔔</div>
                         <p class="text-xs font-bold text-slate-600">Belum ada notifikasi</p>
                         <p class="text-[11px] text-slate-400 mt-0.5">Pembaruan penting akan muncul di sini.</p>
                      </div>
                   <?php else: ?>
                      <div class="max-h-96 overflow-y-auto divide-y divide-slate-100">
                         <?php foreach ($notifRecent as $nr): ?>
                            <?php render_notif_item($nr, $isAdmin, true); ?>
                         <?php endforeach; ?>
                      </div>
                   <?php endif; ?>
                   <a href="dashboard.php?tab=notifikasi" class="block text-center px-4 py-2.5 border-t border-slate-100 text-xs font-bold text-brand-600 hover:bg-slate-50 transition-colors">
                      Lihat semua notifikasi &rarr;
                   </a>
                </div>
             </div>

             <div class="flex items-center gap-2.5 pl-2 sm:border-l border-slate-200">
               <div class="w-8 h-8 rounded-full bg-brand-100 text-brand-700 flex items-center justify-center font-bold text-xs border border-brand-200 shadow-xs">
                  <?= strtoupper(substr($currentUser['name'], 0, 1)) ?>
               </div>
               <div class="hidden sm:flex flex-col text-right">
                  <span class="text-xs font-bold text-slate-800"><?= htmlspecialchars($currentUser['name']) ?></span>
                  <span class="text-[10px] text-slate-400 truncate max-w-[120px]"><?= htmlspecialchars($currentUser['business_name'] ?: 'Klien') ?></span>
               </div>
            </div>
         </div>
      </header>

      <!-- MAIN BODY (TABS) -->
      <main class="flex-1 max-w-7xl w-full mx-auto p-4 sm:p-6 lg:p-8">

      <!-- Flash messages ditampilkan sebagai toast (lihat toast.php di akhir halaman) -->

            <!-- ==================================================================== -->
      <!-- ADMIN VIEW 1: DAFTAR SELURUH KLIEN -->
      <!-- ==================================================================== -->
      <?php if ($isAdmin && $activeTab === 'admin_clients'): ?>
         <div class="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
               <span class="text-xs font-extrabold uppercase tracking-wider text-brand-600 bg-brand-50 px-3 py-1 rounded-full border border-brand-200">
                  Panel Administrator
               </span>
               <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 mt-2">Daftar Seluruh Klien Terdaftar</h1>
               <p class="text-sm text-slate-500 mt-1 max-w-2xl">
                  Kelola data seluruh akun UMKM &amp; bisnis, pantau paket langganan aktif, dan isi laporan kegiatan langsung untuk tiap klien.
               </p>
            </div>
            <a href="dashboard.php?tab=admin_activities"
               class="px-5 py-2.5 rounded-xl bg-slate-900 hover:bg-brand-600 text-white font-bold text-xs transition-all w-fit shadow-sm flex items-center gap-2">
               <span>+ Tulis Kegiatan Klien</span>
            </a>
         </div>

         <!-- 4 Stat Summary Cards for Admin -->
         <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6 mb-8">
            <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs flex items-center justify-between">
               <div>
                  <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Total Klien</span>
                  <div class="text-2xl sm:text-3xl font-black text-slate-900 mt-1"><?= $adminTotalClients ?></div>
                  <p class="text-[11px] text-slate-500 mt-1">Akun bisnis terdaftar</p>
               </div>
               <div class="w-12 h-12 rounded-2xl bg-slate-100 text-slate-700 flex items-center justify-center font-bold">
                  👥
               </div>
            </div>

            <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs flex items-center justify-between">
               <div>
                  <span class="text-xs font-bold text-emerald-600 uppercase tracking-wider">Paket Aktif</span>
                  <div class="text-2xl sm:text-3xl font-black text-emerald-600 mt-1"><?= $adminActiveClients ?></div>
                  <p class="text-[11px] text-emerald-700/80 mt-1">Sedang berlangganan</p>
               </div>
               <div class="w-12 h-12 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center font-bold">
                  🚀
               </div>
            </div>

            <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs flex items-center justify-between">
               <div>
                  <span class="text-xs font-bold text-amber-600 uppercase tracking-wider">Perlu Verifikasi</span>
                  <div class="text-2xl sm:text-3xl font-black text-amber-600 mt-1"><?= $adminPendingPaymentsCount ?></div>
                  <p class="text-[11px] text-amber-700/80 mt-1">
                     <a href="dashboard.php?tab=admin_payments" class="hover:underline">Cek transfer masuk &rarr;</a>
                  </p>
               </div>
               <div class="w-12 h-12 rounded-2xl bg-amber-50 text-amber-600 flex items-center justify-center font-bold">
                  💳
               </div>
            </div>

            <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs flex items-center justify-between">
               <div>
                  <span class="text-xs font-bold text-brand-600 uppercase tracking-wider">Total Omzet Terverifikasi</span>
                  <div class="text-xl sm:text-2xl font-black text-brand-600 mt-1">
                     Rp <?= number_format($adminTotalVerifiedRevenue, 0, ',', '.') ?>
                  </div>
                  <p class="text-[11px] text-slate-400 mt-1">Dari pembayaran lunas</p>
               </div>
               <div class="w-12 h-12 rounded-2xl bg-brand-50 text-brand-600 flex items-center justify-center font-bold">
                  💰
               </div>
            </div>
         </div>

         <!-- TABEL DAFTAR KLIEN -->
         <div class="bg-white rounded-3xl border border-slate-200/80 shadow-xs overflow-hidden mb-8">
            <div class="p-5 border-b border-slate-200/80 flex items-center justify-between bg-slate-50/50">
               <h3 class="text-sm font-extrabold text-slate-900">Seluruh Klien OkeIniAja</h3>
               <span class="text-xs text-slate-500 font-semibold"><?= count($allClients) ?> Klien Terdata</span>
            </div>

            <?php if (empty($allClients)): ?>
               <div class="py-12 text-center text-slate-400 text-xs">Belum ada klien terdaftar.</div>
            <?php else: ?>
               <div class="overflow-x-auto">
                  <table class="w-full text-left border-collapse text-xs">
                     <thead>
                        <tr class="bg-slate-50/80 border-b border-slate-200/80 text-slate-500 uppercase tracking-wider font-bold">
                           <th class="py-3.5 px-5">ID &amp; Klien</th>
                           <th class="py-3.5 px-5">Kontak WhatsApp &amp; Email</th>
                           <th class="py-3.5 px-5">Paket Aktif</th>
                           <th class="py-3.5 px-5">Statistik</th>
                           <th class="py-3.5 px-5">Bergabung</th>
                           <th class="py-3.5 px-5 text-right">Aksi</th>
                        </tr>
                     </thead>
                     <tbody class="divide-y divide-slate-100">
                        <?php foreach ($allClients as $client): ?>
                           <tr class="hover:bg-slate-50/60 transition-colors">
                              <td class="py-4 px-5">
                                 <div class="flex items-center gap-3">
                                    <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-brand-500 to-orange-500 text-white flex items-center justify-center font-black text-sm shrink-0">
                                       <?= strtoupper(substr($client['name'], 0, 1)) ?>
                                    </div>
                                    <div>
                                       <strong class="text-slate-900 font-bold block text-sm"><?= htmlspecialchars($client['business_name'] ?: '-') ?></strong>
                                       <span class="text-slate-500 font-medium">PIC: <?= htmlspecialchars($client['name']) ?></span>
                                       <span class="text-[10px] text-slate-400 block font-mono">#ID <?= $client['id'] ?></span>
                                    </div>
                                 </div>
                              </td>
                              <td class="py-4 px-5">
                                 <div class="space-y-1">
                                    <a href="https://wa.me/<?= $client['whatsapp'] ?>" target="_blank"
                                       class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md bg-emerald-50 text-emerald-700 hover:bg-emerald-100 font-bold text-[11px] transition-colors">
                                       <span>💬 +<?= htmlspecialchars($client['whatsapp'] ?: '-') ?></span>
                                    </a>
                                    <span class="text-slate-500 block text-[11px]"><?= htmlspecialchars($client['email'] ?: '-') ?></span>
                                 </div>
                              </td>
                              <td class="py-4 px-5">
                                 <?php if (!empty($client['package_active'])): ?>
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                       <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                       <?= htmlspecialchars($client['package_active']) ?>
                                    </span>
                                    <span class="text-[10px] text-slate-400 block mt-1">
                                       Berlaku s/d: <?= !empty($client['package_expires_at']) ? date('d M Y', strtotime($client['package_expires_at'])) : '-' ?>
                                    </span>
                                 <?php else: ?>
                                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-medium bg-slate-100 text-slate-500">
                                       Belum Berlangganan
                                    </span>
                                 <?php endif; ?>
                              </td>
                              <td class="py-4 px-5 text-slate-600">
                                 <div class="space-y-0.5 text-[11px]">
                                    <div>📦 Total Pesanan: <strong><?= $client['total_orders'] ?></strong></div>
                                    <div>📝 Total Kegiatan: <strong><?= $client['total_activities'] ?></strong></div>
                                 </div>
                              </td>
                              <td class="py-4 px-5 text-slate-500 text-[11px]">
                                 <?= date('d M Y', strtotime($client['created_at'])) ?>
                              </td>
                              <td class="py-4 px-5 text-right whitespace-nowrap">
                                 <a href="dashboard.php?tab=admin_client_detail&client_id=<?= $client['id'] ?>"
                                     class="px-3 py-1.5 bg-slate-900 hover:bg-slate-700 text-white font-bold rounded-lg text-xs transition-colors inline-flex items-center gap-1 shadow-xs">
                                     <span>👁️ Detail</span>
                                  </a>
                                  <a href="dashboard.php?tab=admin_client_detail&client_id=<?= $client['id'] ?>#ganti-password" title="Ganti Password"
                                     class="px-2.5 py-1.5 bg-amber-50 hover:bg-amber-100 text-amber-700 border border-amber-200 font-bold rounded-lg text-xs transition-colors inline-flex items-center shadow-xs">🔑</a>
                                  <a href="dashboard.php?tab=admin_activities&client_id=<?= $client['id'] ?>"
                                    class="px-3 py-1.5 bg-brand-600 hover:bg-brand-700 text-white font-bold rounded-lg text-xs transition-colors inline-flex items-center gap-1 shadow-xs">
                                    <span>+ Isi Kegiatan</span>
                                 </a>
                              </td>
                           </tr>
                        <?php endforeach; ?>
                     </tbody>
                  </table>
               </div>
            <?php endif; ?>
         </div>
      <?php endif; ?>

      <!-- ==================================================================== -->
      <!-- HALAMAN LOG NOTIFIKASI (ADMIN: SEMUA KLIEN, KLIEN: MILIK SENDIRI)     -->
      <!-- ==================================================================== -->
      <?php if ($activeTab === 'notifikasi'): ?>
         <div class="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
               <span class="text-xs font-extrabold uppercase tracking-wider text-brand-600 bg-brand-50 px-3 py-1 rounded-full border border-brand-200">
                  Log Notifikasi
               </span>
               <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 mt-2">Notifikasi</h1>
               <p class="text-sm text-slate-500 mt-1 max-w-2xl">
                  <?php if ($isAdmin): ?>
                     Aktivitas terbaru dari seluruh klien: pesanan, konfirmasi pembayaran, dan permintaan. Klik notifikasi untuk menuju halaman terkait.
                  <?php else: ?>
                     Pembaruan terbaru untuk akun Anda: verifikasi pembayaran, jadwal &amp; progres pekerjaan, serta kegiatan. Klik notifikasi untuk melihat detailnya.
                  <?php endif; ?>
               </p>
            </div>
            <?php if ($notificationCount > 0): ?>
               <form method="POST" class="w-fit">
                  <input type="hidden" name="action" value="mark_all_notifications_read">
                  <button type="submit"
                     class="px-5 py-2.5 rounded-xl bg-slate-900 hover:bg-brand-600 text-white font-bold text-xs transition-all shadow-sm flex items-center gap-2">
                     <span>✓ Tandai Semua Dibaca</span>
                  </button>
               </form>
            <?php endif; ?>
         </div>

         <!-- Filter -->
         <div class="mb-4 flex items-center gap-2 text-xs font-bold">
            <a href="dashboard.php?tab=notifikasi"
               class="px-4 py-2 rounded-xl border transition-colors <?= $notifFilter === 'all' ? 'bg-slate-900 text-white border-slate-900' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50' ?>">
               Semua
            </a>
            <a href="dashboard.php?tab=notifikasi&filter=unread"
               class="px-4 py-2 rounded-xl border transition-colors flex items-center gap-1.5 <?= $notifFilter === 'unread' ? 'bg-slate-900 text-white border-slate-900' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50' ?>">
               Belum Dibaca
               <?php if ($notificationCount > 0): ?>
                  <span class="px-1.5 py-0.5 rounded-full text-[10px] font-black bg-rose-500 text-white"><?= $notificationCount ?></span>
               <?php endif; ?>
            </a>
         </div>

         <div class="bg-white rounded-3xl border border-slate-200/80 shadow-xs overflow-hidden mb-8">
            <div class="p-5 border-b border-slate-200/80 flex items-center justify-between bg-slate-50/50">
               <h3 class="text-sm font-extrabold text-slate-900"><?= $notifFilter === 'unread' ? 'Notifikasi Belum Dibaca' : 'Seluruh Notifikasi' ?></h3>
               <span class="text-xs text-slate-500 font-semibold"><?= $notifTotal ?> notifikasi</span>
            </div>

            <?php if (empty($notifList)): ?>
               <div class="py-16 text-center">
                  <div class="w-14 h-14 mx-auto mb-3 rounded-2xl bg-slate-100 flex items-center justify-center text-2xl">🔔</div>
                  <h3 class="text-sm font-extrabold text-slate-700">
                     <?= $notifFilter === 'unread' ? 'Semua notifikasi sudah dibaca' : 'Belum ada notifikasi' ?>
                  </h3>
                  <p class="text-xs text-slate-400 mt-1">Pembaruan penting akan muncul di sini.</p>
               </div>
            <?php else: ?>
               <div class="divide-y divide-slate-100">
                  <?php foreach ($notifList as $n): ?>
                     <?php render_notif_item($n, $isAdmin); ?>
                  <?php endforeach; ?>
               </div>

               <?php if ($notifPages > 1): ?>
                  <div class="p-4 border-t border-slate-100 flex items-center justify-between text-xs font-bold">
                     <?php $pageBase = 'dashboard.php?tab=notifikasi' . ($notifFilter === 'unread' ? '&filter=unread' : '') . '&page='; ?>
                     <?php if ($notifPage > 1): ?>
                        <a href="<?= $pageBase . ($notifPage - 1) ?>" class="px-4 py-2 rounded-xl border border-slate-200 text-slate-600 hover:bg-slate-50">&larr; Sebelumnya</a>
                     <?php else: ?>
                        <span></span>
                     <?php endif; ?>
                     <span class="text-slate-400">Halaman <?= $notifPage ?> dari <?= $notifPages ?></span>
                     <?php if ($notifPage < $notifPages): ?>
                        <a href="<?= $pageBase . ($notifPage + 1) ?>" class="px-4 py-2 rounded-xl border border-slate-200 text-slate-600 hover:bg-slate-50">Berikutnya &rarr;</a>
                     <?php else: ?>
                        <span></span>
                     <?php endif; ?>
                  </div>
               <?php endif; ?>
            <?php endif; ?>
         </div>
      <?php endif; ?>

      <!-- ==================================================================== -->
      <!-- ADMIN VIEW: DETAIL KLIEN + GANTI PASSWORD OLEH ADMIN                  -->
      <!-- ==================================================================== -->
      <?php if ($isAdmin && $activeTab === 'admin_client_detail' && $detailClient): ?>
         <?php
            $fmtDate = static fn($d) => !empty($d) ? date('d M Y', strtotime((string)$d)) : '-';
            $fmtDateTime = static fn($d) => !empty($d) ? date('d M Y, H:i', strtotime((string)$d)) : '-';
            $rp = static fn($n) => 'Rp ' . number_format((int)$n, 0, ',', '.');
            $orderStatus = [
               'pending_payment' => ['Menunggu Pembayaran', 'bg-amber-50 text-amber-700 border-amber-200'],
               'reviewing'       => ['Sedang Diverifikasi', 'bg-blue-50 text-blue-700 border-blue-200'],
               'active'          => ['Lunas / Aktif', 'bg-emerald-50 text-emerald-700 border-emerald-200'],
               'cancelled'       => ['Dibatalkan', 'bg-slate-100 text-slate-600 border-slate-200'],
               'expired'         => ['Kedaluwarsa', 'bg-slate-100 text-slate-600 border-slate-200'],
            ];
            $payStatus = [
               'pending'  => ['Menunggu', 'bg-amber-50 text-amber-700 border-amber-200'],
               'verified' => ['Terverifikasi', 'bg-emerald-50 text-emerald-700 border-emerald-200'],
               'rejected' => ['Ditolak', 'bg-rose-50 text-rose-700 border-rose-200'],
               'refunded' => ['Diretur', 'bg-purple-50 text-purple-700 border-purple-200'],
               'returned' => ['Dikembalikan', 'bg-purple-50 text-purple-700 border-purple-200'],
            ];
            $jobStatus = [
               'completed'   => ['Selesai & Tayang', 'bg-emerald-50 text-emerald-700 border-emerald-200'],
               'scheduled'   => ['Terjadwal', 'bg-blue-50 text-blue-700 border-blue-200'],
               'in_progress' => ['Dalam Proses', 'bg-amber-50 text-amber-700 border-amber-200'],
               'draft'       => ['Draf', 'bg-slate-100 text-slate-600 border-slate-200'],
            ];
            $badge = static function (array $map, string $key): string {
               [$label, $cls] = $map[$key] ?? [$key, 'bg-slate-100 text-slate-600 border-slate-200'];
               return '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold border ' . $cls . '">' . htmlspecialchars($label) . '</span>';
            };
            $dc = $detailClient;
            $detailWa = preg_replace('/[^0-9]/', '', (string)($dc['whatsapp'] ?? ''));
         ?>

         <!-- Breadcrumb & Header -->
         <div class="mb-6">
            <a href="dashboard.php?tab=admin_clients" class="inline-flex items-center gap-1.5 text-xs font-bold text-slate-500 hover:text-brand-600 transition-colors">
               <span>&larr;</span> Kembali ke Daftar Klien
            </a>
         </div>

         <div class="mb-8 bg-white rounded-3xl border border-slate-200/80 shadow-xs p-5 sm:p-6 flex flex-col lg:flex-row lg:items-center justify-between gap-5">
            <div class="flex items-center gap-4">
               <div class="w-14 h-14 rounded-2xl bg-gradient-to-tr from-brand-500 to-orange-500 text-white flex items-center justify-center font-black text-xl shrink-0 shadow-sm">
                  <?= strtoupper(substr((string)$dc['name'], 0, 1)) ?>
               </div>
               <div>
                  <div class="flex items-center gap-2 flex-wrap">
                     <h1 class="text-xl sm:text-2xl font-extrabold text-slate-900"><?= htmlspecialchars($dc['business_name'] ?: $dc['name']) ?></h1>
                     <span class="px-2 py-0.5 rounded-full text-[10px] font-extrabold uppercase border <?= ($dc['status'] ?? 'active') === 'active' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-rose-50 text-rose-700 border-rose-200' ?>">
                        <?= ($dc['status'] ?? 'active') === 'active' ? 'Akun Aktif' : 'Nonaktif' ?>
                     </span>
                  </div>
                  <p class="text-xs text-slate-500 mt-0.5">PIC: <strong class="text-slate-700"><?= htmlspecialchars($dc['name']) ?></strong> · <span class="font-mono">#ID <?= (int)$dc['id'] ?></span></p>
                  <div class="mt-2"><?= package_badge_html($dc['package_active'] ?: null) ?></div>
               </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
               <?php if ($detailWa): ?>
                  <a href="https://wa.me/<?= $detailWa ?>" target="_blank" rel="noopener"
                     class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs transition-colors shadow-xs">💬 Hubungi WhatsApp</a>
               <?php endif; ?>
               <a href="dashboard.php?tab=aktivitas&client_id=<?= (int)$dc['id'] ?>"
                  class="px-4 py-2 rounded-xl bg-slate-900 hover:bg-brand-600 text-white font-bold text-xs transition-colors shadow-xs">🚀 Pekerjaan &amp; Ceklis</a>
               <a href="dashboard.php?tab=admin_activities&client_id=<?= (int)$dc['id'] ?>"
                  class="px-4 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-bold text-xs transition-colors shadow-xs">+ Isi Kegiatan</a>
            </div>
         </div>

         <!-- Ringkasan Statistik -->
         <div class="grid grid-cols-2 lg:grid-cols-5 gap-3 sm:gap-4 mb-8">
            <?php
               $detailStats = [
                  ['📦', 'Pesanan', count($detailOrders)],
                  ['💳', 'Pembayaran', count($detailPayments)],
                  ['📝', 'Kegiatan', (int)($dc['total_activities'] ?? 0)],
                  ['🚀', 'Pekerjaan Artikel', count($detailJobs)],
                  ['📞', 'Prospek Leads', $detailLeadsCount],
               ];
            ?>
            <?php foreach ($detailStats as [$ic, $lb, $val]): ?>
               <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-xs">
                  <div class="text-lg"><?= $ic ?></div>
                  <div class="text-2xl font-black text-slate-900 mt-1"><?= $val ?></div>
                  <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider"><?= $lb ?></span>
               </div>
            <?php endforeach; ?>
         </div>

         <div class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-8">
            <!-- KOLOM KIRI -->
            <div class="xl:col-span-2 space-y-6">

               <!-- Informasi Akun -->
               <div class="bg-white rounded-3xl border border-slate-200/80 shadow-xs overflow-hidden">
                  <div class="p-5 border-b border-slate-200/80 bg-slate-50/50">
                     <h3 class="text-sm font-extrabold text-slate-900">Informasi Akun &amp; Bisnis</h3>
                  </div>
                  <dl class="p-5 grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-4 text-xs">
                     <?php
                        $infoRows = [
                           ['Nama PIC', $dc['name']],
                           ['Nama Usaha / Brand', $dc['business_name'] ?: '-'],
                           ['Email', $dc['email'] ?: '-'],
                           ['WhatsApp', $dc['whatsapp'] ? '+' . $dc['whatsapp'] : '-'],
                           ['Website', $dc['website_url'] ?: '-'],
                           ['Instagram', $dc['instagram_handle'] ?: '-'],
                           ['Bergabung', $fmtDateTime($dc['created_at'])],
                           ['Login Terakhir', $fmtDateTime($dc['last_login_at'] ?? null)],
                           ['Profil Diperbarui', $fmtDateTime($dc['updated_at'] ?? null)],
                           ['Total Belanja (Lunas)', $rp($dc['total_spend'] ?? 0)],
                        ];
                     ?>
                     <?php foreach ($infoRows as [$k, $v]): ?>
                        <div>
                           <dt class="text-[10px] font-bold uppercase tracking-wider text-slate-400"><?= $k ?></dt>
                           <dd class="mt-0.5 font-semibold text-slate-800 break-words">
                              <?php if ($k === 'Website' && $v !== '-'): ?>
                                 <a href="<?= htmlspecialchars((string)$v) ?>" target="_blank" rel="noopener" class="text-brand-600 hover:underline"><?= htmlspecialchars((string)$v) ?></a>
                              <?php else: ?>
                                 <?= htmlspecialchars((string)$v) ?>
                              <?php endif; ?>
                           </dd>
                        </div>
                     <?php endforeach; ?>
                  </dl>
               </div>

               <!-- Paket & Kuota -->
               <div>
                  <div class="mb-3 bg-white rounded-2xl border border-slate-200/80 shadow-xs p-4 grid grid-cols-1 sm:grid-cols-3 gap-4 text-xs">
                     <div>
                        <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 block">Paket Aktif</span>
                        <strong class="text-slate-900"><?= htmlspecialchars($dc['package_active'] ?: 'Belum berlangganan') ?></strong>
                     </div>
                     <div>
                        <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 block">Siklus Tagihan</span>
                        <strong class="text-slate-900"><?= ($dc['package_billing'] ?? '') === 'yearly' ? 'Tahunan' : (($dc['package_billing'] ?? '') === 'monthly' ? 'Bulanan' : '-') ?></strong>
                     </div>
                     <div>
                        <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 block">Berlaku Sampai</span>
                        <strong class="text-slate-900"><?= $fmtDate($dc['package_expires_at'] ?? null) ?></strong>
                     </div>
                  </div>
                  <?php render_quota_panel($detailFeatures, job_usage($detailJobs, date('Y-m')), $monthLabelId); ?>
               </div>

               <!-- Pesanan -->
               <div class="bg-white rounded-3xl border border-slate-200/80 shadow-xs overflow-hidden">
                  <div class="p-5 border-b border-slate-200/80 bg-slate-50/50 flex items-center justify-between">
                     <h3 class="text-sm font-extrabold text-slate-900">Riwayat Pesanan</h3>
                     <span class="text-xs text-slate-500 font-semibold"><?= count($detailOrders) ?> pesanan</span>
                  </div>
                  <?php if (empty($detailOrders)): ?>
                     <div class="py-10 text-center text-slate-400 text-xs">Klien belum pernah memesan paket.</div>
                  <?php else: ?>
                     <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                           <thead>
                              <tr class="bg-slate-50/80 border-b border-slate-200/80 text-slate-500 uppercase tracking-wider font-bold">
                                 <th class="py-3 px-5">Kode</th>
                                 <th class="py-3 px-5">Paket</th>
                                 <th class="py-3 px-5">Siklus</th>
                                 <th class="py-3 px-5">Total</th>
                                 <th class="py-3 px-5">Status</th>
                                 <th class="py-3 px-5">Tanggal</th>
                              </tr>
                           </thead>
                           <tbody class="divide-y divide-slate-100">
                              <?php foreach ($detailOrders as $o): ?>
                                 <tr class="hover:bg-slate-50/60">
                                    <td class="py-3 px-5 font-mono font-bold text-slate-800"><?= htmlspecialchars($o['order_code']) ?></td>
                                    <td class="py-3 px-5 font-semibold text-slate-800"><?= htmlspecialchars($o['package_name']) ?></td>
                                    <td class="py-3 px-5 text-slate-600"><?= $o['billing_cycle'] === 'yearly' ? 'Tahunan' : 'Bulanan' ?></td>
                                    <td class="py-3 px-5 font-bold text-slate-900"><?= $rp($o['total_amount']) ?></td>
                                    <td class="py-3 px-5"><?= $badge($orderStatus, (string)$o['status']) ?></td>
                                    <td class="py-3 px-5 text-slate-500"><?= $fmtDate($o['created_at']) ?></td>
                                 </tr>
                              <?php endforeach; ?>
                           </tbody>
                        </table>
                     </div>
                  <?php endif; ?>
               </div>

               <!-- Pembayaran -->
               <div class="bg-white rounded-3xl border border-slate-200/80 shadow-xs overflow-hidden">
                  <div class="p-5 border-b border-slate-200/80 bg-slate-50/50 flex items-center justify-between">
                     <h3 class="text-sm font-extrabold text-slate-900">Riwayat Pembayaran</h3>
                     <a href="dashboard.php?tab=admin_payments" class="text-xs font-bold text-brand-600 hover:underline">Verifikasi pembayaran &rarr;</a>
                  </div>
                  <?php if (empty($detailPayments)): ?>
                     <div class="py-10 text-center text-slate-400 text-xs">Belum ada konfirmasi pembayaran.</div>
                  <?php else: ?>
                     <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                           <thead>
                              <tr class="bg-slate-50/80 border-b border-slate-200/80 text-slate-500 uppercase tracking-wider font-bold">
                                 <th class="py-3 px-5">ID</th>
                                 <th class="py-3 px-5">Pesanan</th>
                                 <th class="py-3 px-5">Pengirim</th>
                                 <th class="py-3 px-5">Jumlah Transfer</th>
                                 <th class="py-3 px-5">Status</th>
                                 <th class="py-3 px-5">Tgl Transfer</th>
                              </tr>
                           </thead>
                           <tbody class="divide-y divide-slate-100">
                              <?php foreach ($detailPayments as $p): ?>
                                 <tr class="hover:bg-slate-50/60">
                                    <td class="py-3 px-5 font-mono font-bold text-slate-800">#PAY-<?= (int)$p['id'] ?></td>
                                    <td class="py-3 px-5 text-slate-700"><?= htmlspecialchars($p['order_code']) ?> <span class="text-slate-400">· <?= htmlspecialchars($p['package_name']) ?></span></td>
                                    <td class="py-3 px-5 text-slate-600"><?= htmlspecialchars(trim(($p['sender_bank'] ?? '') . ' a/n ' . ($p['sender_name'] ?? ''))) ?></td>
                                    <td class="py-3 px-5 font-bold text-slate-900">
                                       <?= $rp($p['transfer_amount']) ?>
                                       <?php if (!empty($p['proof_file'])): ?>
                                          <a href="<?= htmlspecialchars(proof_url((string)$p['proof_file'])) ?>" target="_blank"
                                             class="block mt-0.5 text-[10px] font-bold text-brand-600 hover:underline">
                                             📎 Lihat Bukti
                                          </a>
                                       <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-5"><?= $badge($payStatus, (string)$p['status']) ?></td>
                                    <td class="py-3 px-5 text-slate-500"><?= $fmtDate($p['transfer_date']) ?></td>
                                 </tr>
                              <?php endforeach; ?>
                           </tbody>
                        </table>
                     </div>
                  <?php endif; ?>
               </div>

               <!-- Pekerjaan Artikel -->
               <div class="bg-white rounded-3xl border border-slate-200/80 shadow-xs overflow-hidden">
                  <div class="p-5 border-b border-slate-200/80 bg-slate-50/50 flex items-center justify-between">
                     <h3 class="text-sm font-extrabold text-slate-900">Pekerjaan &amp; Ceklis</h3>
                     <a href="dashboard.php?tab=aktivitas&client_id=<?= (int)$dc['id'] ?>" class="text-xs font-bold text-brand-600 hover:underline">Kelola &rarr;</a>
                  </div>
                  <?php if (empty($detailJobs)): ?>
                     <div class="py-10 text-center text-slate-400 text-xs">Belum ada pekerjaan artikel untuk klien ini.</div>
                  <?php else: ?>
                     <div class="divide-y divide-slate-100">
                        <?php foreach (array_slice($detailJobs, 0, 8) as $j): ?>
                           <?php $jp = job_progress($j, $detailFeatures); ?>
                           <div class="p-4 sm:px-5 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                              <div class="min-w-0">
                                 <p class="text-xs font-bold text-slate-900 leading-snug"><?= htmlspecialchars($j['title']) ?></p>
                                 <p class="text-[11px] text-slate-400 mt-0.5">📅 <?= $fmtDate($j['upload_date']) ?> · Ceklis <?= $jp['done'] ?>/<?= $jp['total'] ?></p>
                                 <div class="mt-1.5"><?= job_counts_html($j, $detailFeatures) ?></div>
                              </div>
                              <div class="shrink-0"><?= $badge($jobStatus, (string)$j['status']) ?></div>
                           </div>
                        <?php endforeach; ?>
                     </div>
                  <?php endif; ?>
               </div>

               <!-- Kegiatan Terbaru -->
               <div class="bg-white rounded-3xl border border-slate-200/80 shadow-xs overflow-hidden">
                  <div class="p-5 border-b border-slate-200/80 bg-slate-50/50 flex items-center justify-between">
                     <h3 class="text-sm font-extrabold text-slate-900">Kegiatan Terbaru</h3>
                     <span class="text-xs text-slate-500 font-semibold"><?= count($detailActivities) ?> terakhir</span>
                  </div>
                  <?php if (empty($detailActivities)): ?>
                     <div class="py-10 text-center text-slate-400 text-xs">Belum ada kegiatan tercatat.</div>
                  <?php else: ?>
                     <ul class="divide-y divide-slate-100">
                        <?php foreach ($detailActivities as $a): ?>
                           <li class="p-4 sm:px-5">
                              <div class="flex items-start justify-between gap-3">
                                 <p class="text-xs font-bold text-slate-900"><?= htmlspecialchars($a['title']) ?></p>
                                 <span class="text-[10px] text-slate-400 shrink-0"><?= $fmtDate($a['activity_date']) ?></span>
                              </div>
                              <?php if (!empty($a['description'])): ?>
                                 <p class="text-[11px] text-slate-500 mt-0.5 line-clamp-2"><?= htmlspecialchars($a['description']) ?></p>
                              <?php endif; ?>
                           </li>
                        <?php endforeach; ?>
                     </ul>
                  <?php endif; ?>
               </div>
            </div>

            <!-- KOLOM KANAN: GANTI PASSWORD -->
            <div class="space-y-6">
               <div id="ganti-password" class="bg-white rounded-3xl border border-slate-200/80 shadow-xs overflow-hidden xl:sticky xl:top-24 scroll-mt-24">
                  <div class="p-5 border-b border-slate-200/80 bg-slate-50/50 flex items-center gap-3">
                     <div class="w-9 h-9 rounded-xl bg-amber-100 text-amber-600 flex items-center justify-center text-base">🔑</div>
                     <div>
                        <h3 class="text-sm font-extrabold text-slate-900">Ganti Password Klien</h3>
                        <p class="text-[11px] text-slate-400">Atur ulang password untuk akun ini.</p>
                     </div>
                  </div>
                  <form method="POST" class="p-5 space-y-4"
                     data-confirm="Ganti password akun <?= htmlspecialchars($dc['business_name'] ?: $dc['name'], ENT_QUOTES) ?>? Password lama tidak akan berlaku lagi."
                     data-confirm-ok="Ya, Ganti Password">
                     <input type="hidden" name="action" value="admin_change_client_password">
                     <input type="hidden" name="client_id" value="<?= (int)$dc['id'] ?>">

                     <div>
                        <label for="adminNewPass" class="block text-xs font-bold text-slate-700 mb-1">Password Baru *</label>
                        <div class="relative">
                           <input type="password" id="adminNewPass" name="new_password" required minlength="6" autocomplete="new-password"
                              placeholder="Minimal 6 karakter"
                              class="w-full px-3.5 py-2.5 pr-16 rounded-xl border border-slate-200 text-xs font-medium text-slate-900 focus:outline-none focus:border-brand-600">
                           <button type="button" onclick="toggleClientPass()" id="adminPassToggle"
                              class="absolute inset-y-0 right-3 text-[11px] font-bold text-slate-500 hover:text-slate-800">Lihat</button>
                        </div>
                     </div>
                     <div>
                        <label for="adminConfirmPass" class="block text-xs font-bold text-slate-700 mb-1">Konfirmasi Password *</label>
                        <input type="password" id="adminConfirmPass" name="confirm_password" required minlength="6" autocomplete="new-password"
                           placeholder="Ulangi password baru"
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-medium text-slate-900 focus:outline-none focus:border-brand-600">
                     </div>

                     <div class="flex gap-2">
                        <button type="button" onclick="generateClientPass()"
                           class="flex-1 px-3 py-2 rounded-xl border border-slate-200 text-slate-700 hover:bg-slate-50 font-bold text-[11px] transition-colors">🎲 Buat Acak</button>
                        <button type="button" onclick="copyClientPass()"
                           class="flex-1 px-3 py-2 rounded-xl border border-slate-200 text-slate-700 hover:bg-slate-50 font-bold text-[11px] transition-colors">📋 Salin</button>
                     </div>

                     <p class="text-[11px] text-slate-400 leading-relaxed">
                        Password tidak ditampilkan lagi setelah disimpan. Salin dulu lalu sampaikan ke klien lewat WhatsApp. Perubahan dicatat di kegiatan klien.
                     </p>

                     <button type="submit"
                        class="w-full py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-white font-extrabold text-xs transition-colors shadow-sm">
                        Simpan Password Baru
                     </button>
                  </form>
               </div>
            </div>
         </div>

         <script>
            function toggleClientPass() {
               const a = document.getElementById('adminNewPass');
               const c = document.getElementById('adminConfirmPass');
               const show = a.type === 'password';
               a.type = c.type = show ? 'text' : 'password';
               document.getElementById('adminPassToggle').textContent = show ? 'Sembunyikan' : 'Lihat';
            }

            function generateClientPass() {
               const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
               const buf = new Uint32Array(10);
               crypto.getRandomValues(buf);
               let pass = '';
               buf.forEach(function (n) { pass += chars[n % chars.length]; });
               document.getElementById('adminNewPass').value = pass;
               document.getElementById('adminConfirmPass').value = pass;
               if (document.getElementById('adminNewPass').type === 'password') toggleClientPass();
               showToast('info', 'Password acak dibuat. Jangan lupa salin sebelum menyimpan.', 'Password dibuat');
            }

            function copyClientPass() {
               const v = document.getElementById('adminNewPass').value;
               if (!v) { showToast('warning', 'Isi atau buat password terlebih dahulu.', 'Belum ada password'); return; }
               const done = function () { showToast('success', 'Password disalin ke clipboard.', 'Tersalin'); };
               if (navigator.clipboard && window.isSecureContext) {
                  navigator.clipboard.writeText(v).then(done, function () { showToast('error', 'Gagal menyalin password.'); });
               } else {
                  const t = document.createElement('textarea');
                  t.value = v; document.body.appendChild(t); t.select();
                  try { document.execCommand('copy'); done(); } catch (e) { showToast('error', 'Gagal menyalin password.'); }
                  t.remove();
               }
            }
         </script>
      <?php endif; ?>

      <!-- ==================================================================== -->
      <!-- ADMIN VIEW 2: VERIFIKASI PEMBAYARAN KLIEN & PENGELOLAAN RETUR -->
      <!-- ==================================================================== -->
      <?php if ($isAdmin && $activeTab === 'admin_payments'): ?>
         <div class="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
               <span class="text-xs font-extrabold uppercase tracking-wider text-brand-600 bg-brand-50 px-3 py-1 rounded-full border border-brand-200">
                  Verifikasi Pembayaran &amp; Return
               </span>
               <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 mt-2">Daftar Konfirmasi Pembayaran Klien</h1>
               <p class="text-sm text-slate-500 mt-1 max-w-2xl">
                  Periksa mutasi transfer, setujui pembayaran pas, tolak bukti tidak valid, atau proses retur pengembalian dana jika terjadi kelebihan transfer oleh klien.
               </p>
            </div>
         </div>

         <!-- TABEL PEMBAYARAN -->
         <div class="bg-white rounded-3xl border border-slate-200/80 shadow-xs overflow-hidden mb-8">
            <div class="p-5 border-b border-slate-200/80 flex items-center justify-between bg-slate-50/50">
               <div>
                  <h3 class="text-sm font-extrabold text-slate-900">Seluruh Konfirmasi Pembayaran</h3>
                  <p class="text-[11px] text-slate-400 mt-0.5">Pantau status lunas, penolakan, dan mutasi pengembalian dana.</p>
               </div>
               <span class="text-xs text-slate-500 font-semibold"><?= count($allPayments) ?> Data Pembayaran</span>
            </div>

            <?php if (empty($allPayments)): ?>
               <div class="py-12 text-center text-slate-400 text-xs">Belum ada konfirmasi pembayaran masuk dari klien.</div>
            <?php else: ?>
               <div class="overflow-x-auto">
                  <table class="w-full text-left border-collapse text-xs">
                     <thead>
                        <tr class="bg-slate-50/80 border-b border-slate-200/80 text-slate-500 uppercase tracking-wider font-bold">
                           <th class="py-3.5 px-5">ID &amp; Tanggal</th>
                           <th class="py-3.5 px-5">Klien &amp; Usaha</th>
                           <th class="py-3.5 px-5">Pesanan &amp; Paket</th>
                           <th class="py-3.5 px-5">Rekening &amp; Pengirim</th>
                           <th class="py-3.5 px-5">Nominal Transfer vs Tagihan</th>
                           <th class="py-3.5 px-5">Bukti Berkas</th>
                           <th class="py-3.5 px-5">Status &amp; Retur</th>
                           <th class="py-3.5 px-5 text-right">Aksi Verifikasi</th>
                        </tr>
                     </thead>
                     <tbody class="divide-y divide-slate-100">
                        <?php foreach ($allPayments as $pay): ?>
                           <?php 
                              $orderAmount = (int)($pay['total_amount'] ?? 0);
                              $transferAmount = (int)($pay['transfer_amount'] ?? 0);
                              $diff = $transferAmount - $orderAmount;
                              $refundAmount = (int)($pay['refund_amount'] ?? 0);
                           ?>
                           <tr class="hover:bg-slate-50/60 transition-colors">
                              <!-- 1. ID & Tanggal -->
                              <td class="py-4 px-5 font-mono">
                                 <strong class="text-slate-900 block font-bold">#PAY-<?= $pay['id'] ?></strong>
                                 <span class="text-[10px] text-slate-400"><?= date('d M Y', strtotime($pay['transfer_date'])) ?></span>
                              </td>

                              <!-- 2. Klien & Kontak -->
                              <td class="py-4 px-5">
                                 <strong class="text-slate-900 font-bold block"><?= htmlspecialchars($pay['client_business'] ?: '-') ?></strong>
                                 <span class="text-slate-500 font-medium">PIC: <?= htmlspecialchars($pay['client_name']) ?></span>
                                 <a href="https://wa.me/<?= $pay['client_whatsapp'] ?>" target="_blank" class="text-[10px] text-emerald-600 block hover:underline font-semibold mt-0.5">
                                    WA: +<?= htmlspecialchars($pay['client_whatsapp']) ?>
                                 </a>
                              </td>

                              <!-- 3. Pesanan & Paket -->
                              <td class="py-4 px-5">
                                 <strong class="text-slate-900 font-bold block"><?= htmlspecialchars($pay['package_name']) ?></strong>
                                 <span class="text-[10px] text-slate-500 font-mono"><?= htmlspecialchars($pay['order_code']) ?></span>
                                 <span class="text-[10px] font-semibold text-brand-600 block capitalize"><?= htmlspecialchars($pay['billing_cycle']) ?></span>
                              </td>

                              <!-- 4. Rekening Tujuan & Pengirim -->
                              <td class="py-4 px-5">
                                 <span class="text-slate-900 font-semibold block">Tujuan: <?= htmlspecialchars($pay['bank_destination']) ?></span>
                                 <span class="text-slate-500 block text-[11px]">Dari: <?= htmlspecialchars($pay['sender_bank'] ?: 'Bank') ?> - <?= htmlspecialchars($pay['sender_name']) ?></span>
                              </td>

                              <!-- 5. Nominal Transfer vs Tagihan (Deteksi Kelebihan Bayar Otomatis) -->
                              <td class="py-4 px-5">
                                 <span class="text-sm font-black text-slate-900 block">
                                    Rp <?= number_format($transferAmount, 0, ',', '.') ?>
                                 </span>
                                 <span class="text-[10px] text-slate-400 block">Tagihan: Rp <?= number_format($orderAmount, 0, ',', '.') ?></span>

                                 <?php if ($diff > 0): ?>
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-extrabold bg-amber-100 text-amber-800 border border-amber-300 mt-1 shadow-2xs">
                                       <span>⚠️</span> Lebih Bayar: +Rp <?= number_format($diff, 0, ',', '.') ?>
                                    </span>
                                 <?php elseif ($diff < 0): ?>
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-extrabold bg-rose-100 text-rose-800 border border-rose-300 mt-1">
                                       Kurang Bayar: -Rp <?= number_format(abs($diff), 0, ',', '.') ?>
                                    </span>
                                 <?php else: ?>
                                    <span class="text-[10px] text-emerald-600 font-semibold block mt-0.5">✓ Pas sesuai tagihan</span>
                                 <?php endif; ?>

                                 <?php if (!empty($pay['notes'])): ?>
                                    <span class="text-[10px] text-slate-500 italic block mt-1">"<?= htmlspecialchars($pay['notes']) ?>"</span>
                                 <?php endif; ?>
                              </td>

                              <!-- 6. Bukti Transfer -->
                              <td class="py-4 px-5">
                                 <?php if (!empty($pay['proof_file'])): ?>
                                    <a href="<?= htmlspecialchars(proof_url((string)$pay['proof_file'])) ?>" target="_blank"
                                       class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-[11px] transition-colors">
                                       <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                       <span>Lihat Berkas</span>
                                    </a>
                                 <?php else: ?>
                                    <span class="text-slate-400 italic text-[11px]">Tanpa lampiran</span>
                                 <?php endif; ?>
                              </td>

                              <!-- 7. Status & Info Retur -->
                              <td class="py-4 px-5">
                                 <?php if ($pay['status'] === 'verified'): ?>
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                       <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                       Terverifikasi (Lunas)
                                    </span>
                                    <?php if ($refundAmount > 0): ?>
                                       <div class="mt-1.5 p-2 rounded-lg bg-purple-50 border border-purple-200 text-purple-800 text-[10px] space-y-0.5">
                                          <div class="font-bold flex items-center gap-1">
                                             <span>↩</span> Retur Lebih Bayar: Rp <?= number_format($refundAmount, 0, ',', '.') ?>
                                          </div>
                                          <div class="text-[9px] text-purple-600">
                                             <?= htmlspecialchars($pay['refund_bank'] ?: 'Bank') ?> (<?= htmlspecialchars($pay['refund_account'] ?: '-') ?>)
                                          </div>
                                       </div>
                                    <?php endif; ?>

                                 <?php elseif ($pay['status'] === 'returned'): ?>
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-purple-50 text-purple-700 border border-purple-200">
                                       <span class="w-1.5 h-1.5 rounded-full bg-purple-500"></span>
                                       Diretur / Refund Penuh
                                    </span>
                                    <div class="mt-1.5 p-2 rounded-lg bg-purple-50/60 border border-purple-200 text-purple-800 text-[10px] space-y-0.5">
                                       <div class="font-bold">
                                          Total Dikembalikan: Rp <?= number_format($refundAmount ?: $transferAmount, 0, ',', '.') ?>
                                       </div>
                                       <div class="text-[9px] text-purple-600">
                                          Rekening: <?= htmlspecialchars($pay['refund_bank'] ?: 'Bank') ?> <?= htmlspecialchars($pay['refund_account'] ?: '') ?>
                                       </div>
                                    </div>

                                 <?php elseif ($pay['status'] === 'rejected'): ?>
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-rose-50 text-rose-700 border border-rose-200">
                                       <span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span>
                                       Ditolak
                                    </span>
                                 <?php else: ?>
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                       <span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse"></span>
                                       Menunggu Review
                                    </span>
                                 <?php endif; ?>

                                 <?php if (!empty($pay['admin_notes'])): ?>
                                    <span class="block text-[10px] text-slate-500 italic mt-1 font-medium">
                                       Catatan Admin: "<?= htmlspecialchars($pay['admin_notes']) ?>"
                                    </span>
                                 <?php endif; ?>
                              </td>

                              <!-- 8. Aksi Verifikasi / Tolak / Retur -->
                              <td class="py-4 px-5 text-right whitespace-nowrap">
                                 <div class="inline-flex items-center gap-1.5">
                                    <?php if ($pay['status'] === 'pending'): ?>
                                       <!-- 1. Tombol Setujui Langsung -->
                                       <form method="POST" data-confirm="Verifikasi pembayaran ini dan aktifkan paket untuk klien sekarang?" data-confirm-ok="Ya, Verifikasi" class="inline">
                                          <input type="hidden" name="action" value="admin_verify_payment">
                                          <input type="hidden" name="payment_id" value="<?= $pay['id'] ?>">
                                          <input type="hidden" name="decision" value="verified">
                                          <button type="submit"
                                             class="px-2.5 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white font-bold rounded-lg text-xs transition-colors shadow-2xs flex items-center gap-1"
                                             title="Verifikasi Normal (Lunas)">
                                             <span>✓ Setujui</span>
                                          </button>
                                       </form>

                                       <!-- 2. Tombol Retur / Refund (Kelebihan Bayar / Retur Penuh) -->
                                       <button type="button"
                                          onclick="openReturnModal(<?= $pay['id'] ?>, '<?= htmlspecialchars(addslashes($pay['client_business'] ?: $pay['client_name'])) ?>', '<?= htmlspecialchars(addslashes($pay['order_code'])) ?>', <?= $orderAmount ?>, <?= $transferAmount ?>, '<?= htmlspecialchars(addslashes($pay['sender_bank'] ?: '')) ?>', '<?= htmlspecialchars(addslashes($pay['sender_name'] ?: '')) ?>')"
                                          class="px-2.5 py-1.5 <?= $diff > 0 ? 'bg-amber-500 hover:bg-amber-600 text-white animate-pulse' : 'bg-purple-50 hover:bg-purple-100 text-purple-700 border border-purple-200' ?> font-bold rounded-lg text-xs transition-colors flex items-center gap-1"
                                          title="<?= $diff > 0 ? 'Kelebihan Bayar Terdeteksi! Klik untuk retur dana' : 'Retur / Pengembalian Dana' ?>">
                                          <span>↩ Retur</span>
                                       </button>

                                       <!-- 3. Tombol Tolak Pembayaran -->
                                       <button type="button"
                                          onclick="openRejectModal(<?= $pay['id'] ?>, '<?= htmlspecialchars(addslashes($pay['client_business'] ?: $pay['client_name'])) ?>', '<?= htmlspecialchars(addslashes($pay['order_code'])) ?>', 'Rp <?= number_format($transferAmount, 0, ',', '.') ?>')"
                                          class="px-2 py-1.5 bg-rose-50 hover:bg-rose-100 text-rose-700 font-bold rounded-lg text-xs transition-colors border border-rose-200"
                                          title="Tolak Pembayaran (Bukti Tidak Valid)">
                                          ✕ Tolak
                                       </button>

                                    <?php elseif ($pay['status'] === 'verified'): ?>
                                       <!-- Opsi Retur jika ada komplain lebih bayar setelah diverifikasi -->
                                       <button type="button"
                                          onclick="openReturnModal(<?= $pay['id'] ?>, '<?= htmlspecialchars(addslashes($pay['client_business'] ?: $pay['client_name'])) ?>', '<?= htmlspecialchars(addslashes($pay['order_code'])) ?>', <?= $orderAmount ?>, <?= $transferAmount ?>, '<?= htmlspecialchars(addslashes($pay['sender_bank'] ?: '')) ?>', '<?= htmlspecialchars(addslashes($pay['sender_name'] ?: '')) ?>')"
                                          class="px-2.5 py-1.5 bg-purple-50 hover:bg-purple-100 text-purple-700 border border-purple-200 font-bold rounded-lg text-xs transition-colors flex items-center gap-1"
                                          title="Proses Retur Kelebihan Dana untuk Pembayaran Ini">
                                          <span>↩ Retur Dana</span>
                                       </button>

                                    <?php else: ?>
                                       <!-- Opsi Buka Kembali / Ubah jika status rejected atau returned -->
                                       <button type="button"
                                          onclick="openReturnModal(<?= $pay['id'] ?>, '<?= htmlspecialchars(addslashes($pay['client_business'] ?: $pay['client_name'])) ?>', '<?= htmlspecialchars(addslashes($pay['order_code'])) ?>', <?= $orderAmount ?>, <?= $transferAmount ?>, '<?= htmlspecialchars(addslashes($pay['sender_bank'] ?: '')) ?>', '<?= htmlspecialchars(addslashes($pay['sender_name'] ?: '')) ?>')"
                                          class="px-2.5 py-1 rounded bg-slate-100 hover:bg-slate-200 text-slate-600 font-bold text-[11px] transition-colors">
                                          Ubah Retur
                                       </button>
                                    <?php endif; ?>
                                 </div>
                              </td>
                           </tr>
                        <?php endforeach; ?>
                     </tbody>
                  </table>
               </div>
            <?php endif; ?>
         </div>

         <!-- ==================================================================== -->
         <!-- MODAL 1: TOLAK PEMBAYARAN DENGAN ALASAN JELAS -->
         <!-- ==================================================================== -->
         <div id="modalRejectPayment" class="fixed inset-0 z-50 bg-slate-950/70 backdrop-blur-sm hidden items-center justify-center p-4">
            <div class="bg-white rounded-3xl max-w-lg w-full border border-slate-200 shadow-2xl overflow-hidden">
               <div class="p-5 sm:p-6 border-b border-slate-100 flex items-center justify-between bg-rose-50/50">
                  <div class="flex items-center gap-3">
                     <div class="w-10 h-10 rounded-2xl bg-rose-100 text-rose-600 flex items-center justify-center font-bold text-lg">
                        ✕
                     </div>
                     <div>
                        <h3 class="text-base font-extrabold text-slate-900">Tolak Konfirmasi Pembayaran</h3>
                        <p class="text-xs text-slate-500" id="rejectSubtitle">Pembayaran #PAY-0</p>
                     </div>
                  </div>
                  <button type="button" onclick="closeRejectModal()" class="p-2 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-xl transition-colors">
                     <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                  </button>
               </div>

               <form method="POST" class="p-5 sm:p-6 space-y-4">
                  <input type="hidden" name="action" value="admin_verify_payment">
                  <input type="hidden" name="decision" value="rejected">
                  <input type="hidden" name="payment_id" id="rejectPaymentId" value="">

                  <div class="p-3.5 rounded-2xl bg-slate-50 border border-slate-200/80 text-xs space-y-1.5">
                     <div class="flex justify-between text-slate-600">
                        <span>Klien / Usaha:</span>
                        <strong class="text-slate-900 font-bold" id="rejectClientName">-</strong>
                     </div>
                     <div class="flex justify-between text-slate-600">
                        <span>Kode Pesanan:</span>
                        <strong class="text-slate-900 font-mono" id="rejectOrderCode">-</strong>
                     </div>
                     <div class="flex justify-between text-slate-600">
                        <span>Nominal Diklaim:</span>
                        <strong class="text-slate-900 font-black" id="rejectAmount">-</strong>
                     </div>
                  </div>

                  <div>
                     <label class="block text-xs font-bold text-slate-700 mb-1">Alasan Penolakan Pembayaran *</label>
                     <textarea name="admin_notes" rows="3" required
                        class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-medium focus:outline-none focus:border-rose-500 focus:ring-1 focus:ring-rose-500"
                        placeholder="Contoh: Bukti transfer tidak valid / dana belum tercatat pada mutasi rekening bank kami. Silakan lakukan konfirmasi ulang dengan bukti transfer asli."></textarea>
                     <p class="text-[11px] text-slate-400 mt-1">Alasan penolakan ini akan langsung muncul di portal klien dan status order dikembalikan ke menunggu pembayaran.</p>
                  </div>

                  <div class="pt-2 flex items-center justify-end gap-2.5">
                     <button type="button" onclick="closeRejectModal()" class="px-4 py-2.5 rounded-xl border border-slate-200 text-slate-600 hover:bg-slate-50 font-bold text-xs transition-colors">
                        Batal
                     </button>
                     <button type="submit" class="px-5 py-2.5 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-bold text-xs transition-colors shadow-sm">
                        ✕ Tolak Pembayaran Ini
                     </button>
                  </div>
               </form>
            </div>
         </div>

         <!-- ==================================================================== -->
         <!-- MODAL 2: RETURN / REFUND PEMBAYARAN (KELEBIHAN BAYAR / RETUR PENUH) -->
         <!-- ==================================================================== -->
         <div id="modalReturnPayment" class="fixed inset-0 z-50 bg-slate-950/70 backdrop-blur-sm hidden items-center justify-center p-4 overflow-y-auto">
            <div class="bg-white rounded-3xl max-w-xl w-full border border-slate-200 shadow-2xl overflow-hidden my-8">
               <div class="p-5 sm:p-6 border-b border-slate-100 flex items-center justify-between bg-purple-50/50">
                  <div class="flex items-center gap-3">
                     <div class="w-10 h-10 rounded-2xl bg-purple-100 text-purple-700 flex items-center justify-center font-bold text-lg">
                        ↩
                     </div>
                     <div>
                        <h3 class="text-base font-extrabold text-slate-900">Return &amp; Pengembalian Dana</h3>
                        <p class="text-xs text-slate-500" id="returnSubtitle">Penanganan Kelebihan Bayar / Retur Pesanan</p>
                     </div>
                  </div>
                  <button type="button" onclick="closeReturnModal()" class="p-2 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-xl transition-colors">
                     <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                  </button>
               </div>

               <form method="POST" class="p-5 sm:p-6 space-y-4">
                  <input type="hidden" name="action" value="admin_verify_payment">
                  <input type="hidden" name="payment_id" id="returnPaymentId" value="">

                  <!-- Box Ringkasan Kelebihan Bayar -->
                  <div class="p-4 rounded-2xl bg-slate-50 border border-slate-200/80 text-xs grid grid-cols-2 sm:grid-cols-3 gap-3">
                     <div>
                        <span class="text-slate-400 text-[11px] block font-medium">Tagihan Order:</span>
                        <strong class="text-slate-900 text-sm font-black" id="returnOrderAmount">Rp 0</strong>
                     </div>
                     <div>
                        <span class="text-slate-400 text-[11px] block font-medium">Dana Ditransfer:</span>
                        <strong class="text-slate-900 text-sm font-black" id="returnTransferAmount">Rp 0</strong>
                     </div>
                     <div class="col-span-2 sm:col-span-1">
                        <span class="text-slate-400 text-[11px] block font-medium">Selisih Lebih Bayar:</span>
                        <strong class="text-amber-600 text-sm font-black" id="returnDiffAmount">Rp 0</strong>
                     </div>
                  </div>

                  <!-- Pilihan Tipe Penanganan Retur -->
                  <div>
                     <label class="block text-xs font-bold text-slate-700 mb-1.5">Tipe Penanganan Pengembalian Dana *</label>
                     <div class="space-y-2">
                        <!-- Opsi 1: Retur Kelebihan Bayar (Paket Aktif) -->
                        <label class="flex items-start gap-3 p-3 rounded-2xl border border-slate-200 hover:border-purple-300 hover:bg-purple-50/30 cursor-pointer transition-all">
                           <input type="radio" name="decision" value="verified_with_refund" id="typeVerifiedRefund" checked class="mt-0.5 text-purple-600 focus:ring-purple-500">
                           <div>
                              <strong class="text-xs font-bold text-slate-900 block">Setujui Paket &amp; Retur Kelebihan Bayar (Direkomendasikan)</strong>
                              <span class="text-[11px] text-slate-500">Paket klien tetap AKTIF dan berjalan normal. Selisih kelebihan dana ditransfer balik ke rekening klien.</span>
                           </div>
                        </label>

                        <!-- Opsi 2: Retur Penuh / Refund 100% -->
                        <label class="flex items-start gap-3 p-3 rounded-2xl border border-slate-200 hover:border-purple-300 hover:bg-purple-50/30 cursor-pointer transition-all">
                           <input type="radio" name="decision" value="returned" id="typeFullRefund" class="mt-0.5 text-purple-600 focus:ring-purple-500">
                           <div>
                              <strong class="text-xs font-bold text-slate-900 block">Retur Penuh / Batalkan Pesanan (Refund 100%)</strong>
                              <span class="text-[11px] text-slate-500">Seluruh uang dikembalikan ke klien karena salah order / transfer ganda. Pesanan akan dibatalkan.</span>
                           </div>
                        </label>
                     </div>
                  </div>

                  <!-- Nominal Pengembalian Dana -->
                  <div>
                     <label class="block text-xs font-bold text-slate-700 mb-1">Nominal Dana yang Dikembalikan ke Klien (Rp) *</label>
                     <input type="number" name="refund_amount" id="returnRefundInput" required min="1"
                        class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-bold text-slate-900 focus:outline-none focus:border-purple-500 focus:ring-1 focus:ring-purple-500 bg-slate-50/50"
                        placeholder="Contoh: 50000">
                     <span class="text-[10px] text-slate-400 mt-0.5 block">Jumlah uang yang ditransfer kembali oleh admin/finance ke rekening klien.</span>
                  </div>

                  <!-- Rekening Klien Tujuan Retur -->
                  <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                     <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Bank Pengembalian Dana *</label>
                        <input type="text" name="refund_bank" id="returnBankInput" required
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-medium text-slate-900 focus:outline-none focus:border-purple-500 focus:ring-1 focus:ring-purple-500"
                           placeholder="Contoh: BCA / Mandiri / BRI">
                     </div>
                     <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">No. Rekening &amp; Nama Penerima *</label>
                        <input type="text" name="refund_account" id="returnAccountInput" required
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-medium text-slate-900 focus:outline-none focus:border-purple-500 focus:ring-1 focus:ring-purple-500"
                           placeholder="Contoh: 1234567890 a/n CV Usaha Klien">
                     </div>
                  </div>

                  <!-- Catatan / No. Ref Transfer Balik -->
                  <div>
                     <label class="block text-xs font-bold text-slate-700 mb-1">Catatan Admin / No. Referensi Retur</label>
                     <textarea name="admin_notes" id="returnNotesInput" rows="2"
                        class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-medium focus:outline-none focus:border-purple-500 focus:ring-1 focus:ring-purple-500"
                        placeholder="Contoh: Kelebihan transfer Rp 50.000 telah ditransfer balik via BCA ref #RET-8819."></textarea>
                     <span class="text-[10px] text-slate-400">Catatan ini akan dapat dibaca oleh klien pada riwayat pembayaran mereka.</span>
                  </div>

                  <div class="pt-2 flex items-center justify-end gap-2.5">
                     <button type="button" onclick="closeReturnModal()" class="px-4 py-2.5 rounded-xl border border-slate-200 text-slate-600 hover:bg-slate-50 font-bold text-xs transition-colors">
                        Batal
                     </button>
                     <button type="submit" class="px-5 py-2.5 rounded-xl bg-purple-700 hover:bg-purple-800 text-white font-bold text-xs transition-colors shadow-sm flex items-center gap-1.5">
                        <span>↩ Proses Pengembalian Dana</span>
                     </button>
                  </div>
               </form>
            </div>
         </div>
      <?php endif; ?>

      <!-- ==================================================================== -->
      <!-- ADMIN VIEW 3: KELOLA & MENGISIKAN KEGIATAN KLIEN -->
      <!-- ==================================================================== -->
      <?php if ($isAdmin && $activeTab === 'admin_activities'): ?>
         <?php $selectedClientId = (int)($_GET['client_id'] ?? 0); ?>
         <div class="mb-8">
            <span class="text-xs font-extrabold uppercase tracking-wider text-brand-600 bg-brand-50 px-3 py-1 rounded-full border border-brand-200">
               Manajemen Konten &amp; Aktivitas
            </span>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 mt-2">Mengisikan Kegiatan &amp; Laporan Klien</h1>
            <p class="text-sm text-slate-500 mt-1 max-w-2xl">
               Tambahkan draf artikel SEO, jadwal konten media sosial, atau pembaruan teknis website untuk masing-masing akun klien.
            </p>
         </div>

         <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 mb-8">
            <!-- Form Input Kegiatan Baru (Kiri) -->
            <div class="bg-white p-6 sm:p-7 rounded-3xl border border-slate-200/80 shadow-xs h-fit">
               <h3 class="text-base font-extrabold text-slate-900 mb-1">Tambah Kegiatan Baru</h3>
               <p class="text-xs text-slate-500 mb-5">Kegiatan akan langsung muncul di halaman Laporan Kegiatan milik klien.</p>

               <form method="POST" class="space-y-4">
                  <input type="hidden" name="action" value="admin_add_activity">

                  <!-- Pilih Klien Tujuan -->
                  <div>
                     <label class="block text-xs font-bold text-slate-700 mb-1">Pilih Klien Tujuan *</label>
                     <select name="target_user_id" required
                        class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-bold text-slate-800 focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600 bg-slate-50/50">
                        <option value="">-- Pilih Akun Bisnis / Klien --</option>
                        <?php foreach ($allClients as $cl): ?>
                           <option value="<?= $cl['id'] ?>" <?= $selectedClientId === (int)$cl['id'] ? 'selected' : '' ?>>
                              #<?= $cl['id'] ?> - <?= htmlspecialchars($cl['business_name'] ?: $cl['name']) ?> (PIC: <?= htmlspecialchars($cl['name']) ?>)
                           </option>
                        <?php endforeach; ?>
                     </select>
                  </div>

                  <!-- Kategori Kegiatan -->
                  <div>
                     <label class="block text-xs font-bold text-slate-700 mb-1">Kategori Kegiatan *</label>
                     <select name="category" required
                        class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-semibold text-slate-800 focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600 bg-slate-50/50">
                        <option value="article">📝 Artikel SEO Website (Pilar / Blog)</option>
                        <option value="social_media">📱 Konten Media Sosial (Feed, Carousel, Reels)</option>
                        <option value="website">🌐 Pemeliharaan Web / Upload Foto Katalog</option>
                        <option value="lead">💬 Prospek Leads WhatsApp Masuk</option>
                        <option value="system">⚙️ Pengumuman / Info Sistem</option>
                     </select>
                  </div>

                  <!-- Judul Kegiatan -->
                  <div>
                     <label class="block text-xs font-bold text-slate-700 mb-1">Judul / Topik Kegiatan *</label>
                     <input type="text" name="title" required
                        placeholder="Contoh: Optimasi Keyword Sewa Tenda Pernikahan"
                        class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-medium text-slate-800 focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600">
                  </div>

                  <!-- Rincian / Deskripsi -->
                  <div>
                     <label class="block text-xs font-bold text-slate-700 mb-1">Deskripsi / Rincian / Tautan</label>
                     <textarea name="description" rows="3"
                        placeholder="Tuliskan ringkasan artikel, isi konten medsos, atau tautan live..."
                        class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-medium text-slate-800 focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600"></textarea>
                  </div>

                  <!-- Info Metrik / Catatan Tambahan -->
                  <div>
                     <label class="block text-xs font-bold text-slate-700 mb-1">Info Tambahan / Target Metrik</label>
                     <input type="text" name="meta_info"
                        placeholder="Contoh: Rank #1 Google, Carousel 6 Slide, 1.200 Kata"
                        class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-medium text-slate-800 focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600">
                  </div>

                  <!-- Status & Tanggal -->
                  <div class="grid grid-cols-2 gap-3">
                     <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Status Progres</label>
                        <select name="status"
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-semibold text-slate-800 focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600 bg-slate-50/50">
                           <option value="completed" selected>Selesai / Terbit</option>
                           <option value="scheduled">Terjadwal</option>
                           <option value="published">Tayang</option>
                           <option value="review">Review Klien</option>
                           <option value="draft">Draf</option>
                        </select>
                     </div>
                     <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Tanggal Kegiatan</label>
                        <input type="date" name="activity_date" value="<?= date('Y-m-d') ?>"
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-medium text-slate-800 focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600">
                     </div>
                  </div>

                  <button type="submit"
                     class="w-full py-3 bg-brand-600 hover:bg-brand-700 text-white font-extrabold rounded-xl transition-all text-xs shadow-md shadow-brand-600/30 active:scale-95 flex items-center justify-center gap-2">
                     <span>+ Simpan &amp; Terbitkan ke Klien</span>
                  </button>
               </form>
            </div>

            <!-- Tabel Log Kegiatan Seluruh Klien (Kanan) -->
            <div class="lg:col-span-2 space-y-4">
               <div class="bg-white rounded-3xl border border-slate-200/80 shadow-xs overflow-hidden">
                  <div class="p-5 border-b border-slate-200/80 flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-slate-50/50">
                     <div>
                        <h3 class="text-sm font-extrabold text-slate-900">Riwayat Kegiatan Klien Terakhir</h3>
                        <p class="text-[11px] text-slate-500">Seluruh tugas dan konten yang dikerjakan tim untuk klien.</p>
                     </div>
                     <span class="text-xs text-slate-500 font-semibold"><?= count($allActivities) ?> Kegiatan Tercatat</span>
                  </div>

                  <?php if (empty($allActivities)): ?>
                     <div class="py-12 text-center text-slate-400 text-xs">Belum ada kegiatan yang tercatat.</div>
                  <?php else: ?>
                     <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse text-xs">
                           <thead>
                              <tr class="bg-slate-50/80 border-b border-slate-200/80 text-slate-500 uppercase tracking-wider font-bold">
                                 <th class="py-3 px-4">Klien</th>
                                 <th class="py-3 px-4">Kategori &amp; Judul</th>
                                 <th class="py-3 px-4">Metrik / Info</th>
                                 <th class="py-3 px-4">Status &amp; Tgl</th>
                                 <th class="py-3 px-4 text-right">Aksi</th>
                              </tr>
                           </thead>
                           <tbody class="divide-y divide-slate-100">
                              <?php foreach ($allActivities as $act): ?>
                                 <tr class="hover:bg-slate-50/60 transition-colors">
                                    <td class="py-3.5 px-4">
                                       <strong class="text-slate-900 font-bold block"><?= htmlspecialchars($act['client_business'] ?: '-') ?></strong>
                                       <span class="text-[11px] text-slate-500"><?= htmlspecialchars($act['client_name']) ?></span>
                                    </td>
                                    <td class="py-3.5 px-4 max-w-xs">
                                       <span class="text-[10px] font-extrabold uppercase px-2 py-0.5 rounded-md bg-slate-100 text-slate-600 inline-block mb-1">
                                          <?= htmlspecialchars($act['category']) ?>
                                       </span>
                                       <strong class="text-slate-900 block font-semibold leading-snug"><?= htmlspecialchars($act['title']) ?></strong>
                                       <?php if (!empty($act['description'])): ?>
                                          <p class="text-[11px] text-slate-500 truncate mt-0.5"><?= htmlspecialchars($act['description']) ?></p>
                                       <?php endif; ?>
                                    </td>
                                    <td class="py-3.5 px-4">
                                       <span class="text-slate-600 font-medium"><?= htmlspecialchars($act['meta_info'] ?: '-') ?></span>
                                    </td>
                                    <td class="py-3.5 px-4 whitespace-nowrap">
                                       <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700">
                                          <?= strtoupper($act['status']) ?>
                                       </span>
                                       <span class="text-[10px] text-slate-400 block mt-0.5"><?= date('d M Y', strtotime($act['activity_date'])) ?></span>
                                    </td>
                                    <td class="py-3.5 px-4 text-right whitespace-nowrap">
                                       <form method="POST" data-confirm="Hapus catatan kegiatan ini?" data-confirm-danger="1" data-confirm-ok="Ya, Hapus" class="inline">
                                          <input type="hidden" name="action" value="admin_delete_activity">
                                          <input type="hidden" name="activity_id" value="<?= $act['id'] ?>">
                                          <button type="submit" class="text-rose-500 hover:text-rose-700 font-bold text-xs p-1">
                                             ✕ Hapus
                                          </button>
                                       </form>
                                    </td>
                                 </tr>
                              <?php endforeach; ?>
                           </tbody>
                        </table>
                     </div>
                  <?php endif; ?>
               </div>
            </div>
         </div>
      <?php endif; ?>

      <!-- ==================================================================== -->
      <!-- ADMIN VIEW 4: SEMUA INVOICE & PESANAN KLIEN -->
      <!-- ==================================================================== -->
      <?php if ($isAdmin && $activeTab === 'admin_invoices'): ?>
         <div class="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
               <span class="text-xs font-extrabold uppercase tracking-wider text-brand-600 bg-brand-50 px-3 py-1 rounded-full border border-brand-200">
                  Rekapitulasi Tagihan
               </span>
               <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 mt-2">Seluruh Invoice &amp; Pesanan Klien</h1>
               <p class="text-sm text-slate-500 mt-1 max-w-2xl">
                  Daftar seluruh invoice yang diterbitkan untuk semua akun klien di portal OkeIniAja.
               </p>
            </div>
         </div>

         <div class="bg-white rounded-3xl border border-slate-200/80 shadow-xs overflow-hidden mb-8">
            <div class="p-5 border-b border-slate-200/80 flex items-center justify-between bg-slate-50/50">
               <h3 class="text-sm font-extrabold text-slate-900">Seluruh Invoice Terdaftar</h3>
               <span class="text-xs text-slate-500 font-semibold"><?= count($allOrders) ?> Invoice</span>
            </div>

            <?php if (empty($allOrders)): ?>
               <div class="py-12 text-center text-slate-400 text-xs">Belum ada invoice pesanan dibuat.</div>
            <?php else: ?>
               <div class="overflow-x-auto">
                  <table class="w-full text-left border-collapse text-xs">
                     <thead>
                        <tr class="bg-slate-50/80 border-b border-slate-200/80 text-slate-500 uppercase tracking-wider font-bold">
                           <th class="py-3.5 px-5">Kode Invoice</th>
                           <th class="py-3.5 px-5">Klien &amp; Usaha</th>
                           <th class="py-3.5 px-5">Paket &amp; Siklus</th>
                           <th class="py-3.5 px-5">Total Tagihan</th>
                           <th class="py-3.5 px-5">Status</th>
                           <th class="py-3.5 px-5">Tanggal</th>
                        </tr>
                     </thead>
                     <tbody class="divide-y divide-slate-100">
                        <?php foreach ($allOrders as $ord): ?>
                           <tr class="hover:bg-slate-50/60 transition-colors">
                              <td class="py-4 px-5 font-mono font-bold text-slate-900">
                                 <?= htmlspecialchars($ord['order_code']) ?>
                              </td>
                              <td class="py-4 px-5">
                                 <strong class="text-slate-900 font-bold block"><?= htmlspecialchars($ord['client_business'] ?: '-') ?></strong>
                                 <span class="text-slate-500">PIC: <?= htmlspecialchars($ord['client_name']) ?></span>
                              </td>
                              <td class="py-4 px-5">
                                 <span class="font-bold text-slate-900 block"><?= htmlspecialchars($ord['package_name']) ?></span>
                                 <span class="text-[10px] text-brand-600 font-semibold capitalize"><?= htmlspecialchars($ord['billing_cycle']) ?></span>
                              </td>
                              <td class="py-4 px-5">
                                 <span class="text-sm font-black text-slate-900">
                                    Rp <?= number_format($ord['total_amount'], 0, ',', '.') ?>
                                 </span>
                              </td>
                              <td class="py-4 px-5">
                                 <?php if ($ord['status'] === 'active'): ?>
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-emerald-50 text-emerald-700">Lunas / Aktif</span>
                                 <?php elseif ($ord['status'] === 'reviewing'): ?>
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-blue-50 text-blue-700">Sedang Diverifikasi</span>
                                 <?php else: ?>
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-amber-50 text-amber-700">Menunggu Bayar</span>
                                 <?php endif; ?>
                              </td>
                              <td class="py-4 px-5 text-slate-500 text-[11px]">
                                 <?= date('d M Y, H:i', strtotime($ord['created_at'])) ?>
                              </td>
                           </tr>
                        <?php endforeach; ?>
                     </tbody>
                  </table>
               </div>
            <?php endif; ?>
         </div>
      <?php endif; ?>

<!-- ==================================================================== -->
      <!-- TAB / VIEW: AKTIVITAS PEKERJAAN (ARTIKEL, TANGGAL UPLOAD & CEKLIS) -->
      <!-- ==================================================================== -->
      <?php if ($activeTab === 'aktivitas'): ?>
         
         <?php if ($isAdmin): ?>
            <!-- ------------------------------------------------------------- -->
            <!-- ADMIN VIEW: KELOLA AKTIVITAS PEKERJAAN & CEKLIS ARTIKEL       -->
            <!-- ------------------------------------------------------------- -->
            <div class="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
               <div>
                  <span class="text-xs font-extrabold uppercase tracking-wider text-brand-600 bg-brand-50 px-3 py-1 rounded-full border border-brand-200">
                     Aktivitas Pekerjaan &amp; Ceklis
                  </span>
                  <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 mt-2">Daftar Pekerjaan &amp; Publikasi Artikel</h1>
                  <p class="text-sm text-slate-500 mt-1 max-w-2xl">
                     Kelola jadwal upload artikel, status pengerjaan konten, serta ceklis optimasi gambar, post medsos, dan share tautan artikel ke media sosial klien.
                  </p>
               </div>
               <button type="button" onclick="openAddJobModal()"
                  class="px-5 py-2.5 rounded-xl bg-slate-900 hover:bg-brand-600 text-white font-bold text-xs transition-all w-fit shadow-sm flex items-center gap-2">
                  <span>+ Tambah Pekerjaan Artikel</span>
               </button>
            </div>

            <!-- Filter Klien Dropdown -->
            <div class="mb-6 bg-white p-4 rounded-2xl border border-slate-200/80 shadow-xs flex flex-col sm:flex-row sm:items-center justify-between gap-3">
               <div class="flex items-center gap-2 text-xs font-bold text-slate-700">
                  <span>🔍 Filter Klien:</span>
                  <form method="GET" action="dashboard.php" class="inline">
                     <input type="hidden" name="tab" value="aktivitas">
                     <select name="client_id" onchange="this.form.submit()"
                        class="px-3 py-1.5 rounded-xl border border-slate-200 text-xs font-bold text-slate-800 bg-slate-50 focus:outline-none focus:border-brand-600">
                        <option value="0">-- Tampilkan Seluruh Klien (<?= count($allJobActivities) ?> Pekerjaan) --</option>
                        <?php foreach ($allClients as $cl): ?>
                           <option value="<?= $cl['id'] ?>" <?= $adminJobFilterClient === (int)$cl['id'] ? 'selected' : '' ?>>
                              #<?= $cl['id'] ?> - <?= htmlspecialchars($cl['business_name'] ?: $cl['name']) ?> (PIC: <?= htmlspecialchars($cl['name']) ?>)
                           </option>
                        <?php endforeach; ?>
                     </select>
                  </form>
               </div>

               <?php if ($adminJobFilterClient > 0): ?>
                  <a href="dashboard.php?tab=aktivitas" class="text-xs text-brand-600 hover:underline font-bold">
                     &times; Reset Filter Klien
                  </a>
               <?php endif; ?>
            </div>

            <!-- KUOTA PAKET (saat difilter per klien) -->
            <?php if ($adminJobFilterClient > 0): ?>
               <?php
                  $filterClient = null;
                  foreach ($allClients as $cl) {
                     if ((int)$cl['id'] === $adminJobFilterClient) { $filterClient = $cl; break; }
                  }
                  if ($filterClient) {
                     render_quota_panel(package_features($filterClient['package_active'] ?? null), job_usage($allJobActivities, date('Y-m')), $monthLabelId);
                  }
               ?>
            <?php endif; ?>

            <!-- TABEL PEKERJAAN ARTIKEL ADMIN -->
            <div class="bg-white rounded-3xl border border-slate-200/80 shadow-xs overflow-hidden mb-8">
               <div class="p-5 border-b border-slate-200/80 flex items-center justify-between bg-slate-50/50">
                  <div>
                     <h3 class="text-sm font-extrabold text-slate-900">Seluruh Jadwal Pekerjaan Artikel</h3>
                     <p class="text-[11px] text-slate-400 mt-0.5">Pantau paket klien, jumlah pekerjaan, dan ceklis pengerjaan tiap artikel.</p>
                  </div>
                  <span class="text-xs text-slate-500 font-semibold"><?= count($allJobActivities) ?> Pekerjaan Terdata</span>
               </div>

               <?php if (empty($allJobActivities)): ?>
                  <div class="py-16 text-center text-slate-400 text-xs">
                     Belum ada jadwal pekerjaan artikel terdata. Klik tombol "+ Tambah Pekerjaan Artikel" di atas untuk menambahkan.
                  </div>
               <?php else: ?>
                  <div class="overflow-x-auto">
                     <table class="w-full text-left border-collapse text-xs">
                        <thead>
                           <tr class="bg-slate-50/80 border-b border-slate-200/80 text-slate-500 uppercase tracking-wider font-bold">
                              <th class="py-3.5 px-5">Klien &amp; Usaha</th>
                              <th class="py-3.5 px-5">Paket Dipilih</th>
                              <th class="py-3.5 px-5">Judul Artikel &amp; Tautan</th>
                              <th class="py-3.5 px-5">Tanggal Upload</th>
                              <th class="py-3.5 px-5">Jumlah &amp; Ceklis Pengerjaan</th>
                              <th class="py-3.5 px-5">Status</th>
                              <th class="py-3.5 px-5 text-right">Aksi</th>
                           </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                           <?php foreach ($allJobActivities as $job): ?>
                              <?php
                                 $jf = package_features($job['client_package'] ?? null);
                                 $prog = job_progress($job, $jf);
                                 $doneCount = $prog['done'];
                                 $totalChecks = $prog['total'];
                                 $checklist = job_checklist_for($jf);
                                 $pct = $prog['pct'];
                                 $jobJson = $job + [
                                    'enabled' => $jf['enabled'],
                                    'package_label' => $jf['name'] ? 'Paket: ' . $jf['name'] : 'Klien belum memiliki paket aktif (semua item ditampilkan)',
                                 ];
                              ?>
                              <tr class="hover:bg-slate-50/60 transition-colors">
                                 <!-- 1. Klien -->
                                 <td class="py-4 px-5">
                                    <strong class="text-slate-900 font-bold block"><?= htmlspecialchars($job['client_business'] ?: '-') ?></strong>
                                    <span class="text-slate-500">PIC: <?= htmlspecialchars($job['client_name']) ?></span>
                                    <span class="text-[10px] text-slate-400 block font-mono">ID Klien: #<?= $job['user_id'] ?></span>
                                  </td>

                                  <!-- 1b. Paket yang dipilih klien -->
                                  <td class="py-4 px-5">
                                     <?= package_badge_html($jf['name']) ?>
                                 </td>

                                 <!-- 2. Judul Artikel & Tautan -->
                                 <td class="py-4 px-5 max-w-xs">
                                    <span class="text-xs font-bold text-slate-900 block leading-snug">
                                       <?= htmlspecialchars($job['title']) ?>
                                    </span>
                                    <div class="flex items-center gap-2 mt-1">
                                       <?php if (!empty($job['article_url'])): ?>
                                          <a href="<?= htmlspecialchars($job['article_url']) ?>" target="_blank"
                                             class="inline-flex items-center gap-1 text-[11px] text-brand-600 hover:underline font-semibold">
                                             <span>🌐 Buka Artikel</span>
                                             <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                                          </a>
                                       <?php endif; ?>
                                       <?php if (!empty($job['social_url'])): ?>
                                          <a href="<?= htmlspecialchars($job['social_url']) ?>" target="_blank"
                                             class="inline-flex items-center gap-1 text-[11px] text-pink-600 hover:underline font-semibold">
                                             <span>📱 Medsos</span>
                                          </a>
                                       <?php endif; ?>
                                    </div>
                                    <?php if (!empty($job['notes'])): ?>
                                       <p class="text-[10px] text-slate-400 italic mt-1 line-clamp-2">"<?= htmlspecialchars($job['notes']) ?>"</p>
                                    <?php endif; ?>
                                 </td>

                                 <!-- 3. Tanggal Upload -->
                                 <td class="py-4 px-5 font-mono">
                                    <div class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-slate-100 text-slate-800 font-bold text-[11px]">
                                       <span>📅</span> <?= date('d M Y', strtotime($job['upload_date'])) ?>
                                    </div>
                                 </td>

                                 <!-- 4. Ceklis Pengerjaan 3 Poin -->
                                 <td class="py-4 px-5">
                                    <div class="space-y-1.5 min-w-[220px]">
                                       <?= job_counts_html($job, $jf) ?>
                                       <!-- Bar Progress -->
                                       <div class="flex items-center justify-between text-[10px] font-bold">
                                          <span class="<?= ($totalChecks > 0 && $doneCount === $totalChecks) ? 'text-emerald-700' : 'text-slate-600' ?>">
                                             Progres: <?= $doneCount ?> / <?= $totalChecks ?> Ceklis
                                          </span>
                                          <span class="text-slate-400"><?= $pct ?>%</span>
                                       </div>
                                       <div class="w-full bg-slate-100 h-1.5 rounded-full overflow-hidden">
                                          <div class="h-full rounded-full transition-all <?= ($totalChecks > 0 && $doneCount === $totalChecks) ? 'bg-emerald-500' : ($doneCount > 0 ? 'bg-brand-500' : 'bg-slate-300') ?>" style="width: <?= $pct ?>%"></div>
                                       </div>

                                       <!-- Ceklis sesuai paket klien -->
                                       <div class="space-y-1 pt-1">
                                          <?php foreach ($checklist as $field => $def): $isDone = !empty($job[$field]); ?>
                                             <div class="flex items-center gap-1.5 text-[11px] <?= $isDone ? 'text-emerald-700 font-semibold' : 'text-slate-400' ?>">
                                                <span><?= $isDone ? '☑️' : '⬜' ?></span>
                                                <span><?= htmlspecialchars($def['label']) ?></span>
                                             </div>
                                          <?php endforeach; ?>
                                       </div>
                                    </div>
                                 </td>

                                 <!-- 5. Status Keseluruhan -->
                                 <td class="py-4 px-5">
                                    <?php if ($job['status'] === 'completed'): ?>
                                       <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                          <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                          Selesai &amp; Tayang
                                       </span>
                                    <?php elseif ($job['status'] === 'scheduled'): ?>
                                       <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-blue-50 text-blue-700 border border-blue-200">
                                          <span class="w-1.5 h-1.5 rounded-full bg-blue-500"></span>
                                          Terjadwal
                                       </span>
                                    <?php elseif ($job['status'] === 'in_progress'): ?>
                                       <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                          <span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse"></span>
                                          Dalam Proses
                                       </span>
                                    <?php else: ?>
                                       <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-medium bg-slate-100 text-slate-600">
                                          Draf
                                       </span>
                                    <?php endif; ?>
                                 </td>

                                 <!-- 6. Aksi Admin -->
                                 <td class="py-4 px-5 text-right whitespace-nowrap">
                                    <div class="inline-flex items-center gap-1.5">
                                       <button type="button"
                                          onclick='openEditJobModal(<?= json_encode($jobJson, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
                                          class="px-3 py-1.5 bg-slate-900 hover:bg-brand-600 text-white font-bold rounded-lg text-xs transition-colors flex items-center gap-1 shadow-2xs">
                                          <span>✏️ Update Ceklis</span>
                                       </button>

                                       <form method="POST" data-confirm="Apakah Anda yakin ingin menghapus pekerjaan artikel ini?" data-confirm-danger="1" data-confirm-ok="Ya, Hapus" class="inline">
                                          <input type="hidden" name="action" value="admin_delete_job_activity">
                                          <input type="hidden" name="job_id" value="<?= $job['id'] ?>">
                                          <input type="hidden" name="client_id" value="<?= $job['user_id'] ?>">
                                          <button type="submit"
                                             class="p-1.5 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-lg transition-colors"
                                             title="Hapus Pekerjaan">
                                             <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                          </button>
                                       </form>
                                    </div>
                                 </td>
                              </tr>
                           <?php endforeach; ?>
                        </tbody>
                     </table>
                  </div>
               <?php endif; ?>
            </div>

         <!-- ==================================================================== -->
         <!-- MODAL ADD JOB ACTIVITY (TAMBAH PEKERJAAN ARTIKEL BARU) -->
         <!-- ==================================================================== -->
         <div id="modalAddJobActivity" class="fixed inset-0 z-50 bg-slate-950/70 backdrop-blur-sm hidden items-center justify-center p-4 overflow-y-auto">
            <div class="bg-white rounded-3xl max-w-xl w-full border border-slate-200 shadow-2xl overflow-hidden my-8">
               <div class="p-5 sm:p-6 border-b border-slate-100 flex items-center justify-between bg-slate-50/70">
                  <div class="flex items-center gap-3">
                     <div class="w-10 h-10 rounded-2xl bg-brand-100 text-brand-600 flex items-center justify-center font-bold text-lg">
                        📝
                     </div>
                     <div>
                        <h3 class="text-base font-extrabold text-slate-900">Tambah Pekerjaan Artikel Baru</h3>
                        <p class="text-xs text-slate-500">Jadwalkan artikel SEO dan inisialisasi ceklis pengerjaan.</p>
                     </div>
                  </div>
                  <button type="button" onclick="closeAddJobModal()" class="p-2 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-xl transition-colors">
                     <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                  </button>
               </div>

               <form method="POST" class="p-5 sm:p-6 space-y-4">
                  <input type="hidden" name="action" value="admin_add_job_activity">
                  <input type="hidden" name="task_type" value="article">

                  <!-- Pilih Klien Tujuan -->
                  <div>
                     <label class="block text-xs font-bold text-slate-700 mb-1">Pilih Klien Tujuan * <span id="addJobPackageInfo" class="ml-1 text-brand-600 font-bold"></span></label>
                     <select name="target_user_id" required onchange="onAddJobClientChange(this)"
                        class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-bold text-slate-800 bg-slate-50 focus:outline-none focus:border-brand-600">
                        <option value="">-- Pilih Klien / Bisnis --</option>
                        <?php foreach ($allClients as $cl): ?>
                           <?php $clf = package_features($cl['package_active'] ?? null); ?>
                           <option value="<?= $cl['id'] ?>"
                              data-package="<?= htmlspecialchars((string)($clf['name'] ?? '')) ?>"
                              data-enabled="<?= htmlspecialchars(json_encode($clf['enabled']), ENT_QUOTES) ?>"
                              <?= $adminJobFilterClient === (int)$cl['id'] ? 'selected' : '' ?>>
                              #<?= $cl['id'] ?> - <?= htmlspecialchars($cl['business_name'] ?: $cl['name']) ?> (PIC: <?= htmlspecialchars($cl['name']) ?>)
                           </option>
                        <?php endforeach; ?>
                     </select>
                  </div>

                  <!-- Judul Artikel -->
                  <div>
                     <label class="block text-xs font-bold text-slate-700 mb-1">Judul / Topik Artikel *</label>
                     <input type="text" name="title" required
                        class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-medium text-slate-900 focus:outline-none focus:border-brand-600"
                        placeholder="Contoh: Pembuatan Artikel: 7 Tips Memilih Tenda Roder untuk Event Perusahaan">
                  </div>

                  <!-- Tanggal Upload -->
                  <div>
                     <label class="block text-xs font-bold text-slate-700 mb-1">Target Tanggal Upload / Tayang *</label>
                     <input type="date" name="upload_date" required value="<?= date('Y-m-d') ?>"
                        class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-medium text-slate-900 focus:outline-none focus:border-brand-600">
                  </div>

                  <!-- JUMLAH PEKERJAAN (menyesuaikan paket klien) -->
                  <div>
                     <label class="block text-xs font-bold text-slate-700 mb-1.5">Jumlah Pekerjaan:</label>
                     <div class="grid grid-cols-3 gap-3">
                        <div data-feature="article">
                           <span class="block text-[11px] font-semibold text-slate-500 mb-1">📝 Artikel</span>
                           <input type="number" min="0" name="article_count" value="1"
                              class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold text-slate-900 focus:outline-none focus:border-brand-600">
                        </div>
                        <div data-feature="photo">
                           <span class="block text-[11px] font-semibold text-slate-500 mb-1">🖼️ Foto</span>
                           <input type="number" min="0" name="photo_count" value="0"
                              class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold text-slate-900 focus:outline-none focus:border-brand-600">
                        </div>
                        <div data-feature="social">
                           <span class="block text-[11px] font-semibold text-slate-500 mb-1">📱 Post Medsos</span>
                           <input type="number" min="0" name="social_post_count" value="0"
                              class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold text-slate-900 focus:outline-none focus:border-brand-600">
                        </div>
                     </div>
                  </div>

                  <!-- CEKLIS PENGERJAAN (hanya item yang termasuk paket klien) -->
                  <div>
                     <label class="block text-xs font-bold text-slate-700 mb-1.5">Ceklis Pengerjaan:</label>
                     <div class="space-y-2 p-3.5 rounded-2xl bg-slate-50 border border-slate-200 text-xs">
                        <?php foreach (job_checklist_master() as $field => $def): ?>
                           <label data-feature="<?= $def['item'] ?>" class="flex items-center gap-2.5 cursor-pointer font-medium text-slate-700">
                              <input type="checkbox" name="<?= $field ?>" value="1" class="w-4 h-4 text-brand-600 rounded">
                              <span><?= $def['icon'] ?> <?= htmlspecialchars($def['label']) ?></span>
                           </label>
                        <?php endforeach; ?>
                     </div>
                  </div>

                  <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                     <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Tautan Artikel di Website</label>
                        <input type="url" name="article_url"
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-medium focus:outline-none focus:border-brand-600"
                           placeholder="https://website-klien.com/artikel-slug">
                     </div>
                     <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Status Pekerjaan *</label>
                        <select name="status" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-bold text-slate-800 bg-white focus:outline-none focus:border-brand-600">
                           <option value="in_progress">Dalam Proses Pengerjaan</option>
                           <option value="scheduled">Terjadwal Upload</option>
                           <option value="completed">Selesai &amp; Tayang (100%)</option>
                           <option value="draft">Draf Materi</option>
                        </select>
                     </div>
                  </div>

                  <div>
                     <label class="block text-xs font-bold text-slate-700 mb-1">Catatan Pengerjaan / Keywords</label>
                     <textarea name="notes" rows="2"
                        class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-medium focus:outline-none focus:border-brand-600"
                        placeholder="Contoh: Riset keyword selesai, skor SEO Yoast 95+, gambar 3 buah WebP."></textarea>
                  </div>

                  <div class="pt-2 flex items-center justify-end gap-2.5">
                     <button type="button" onclick="closeAddJobModal()" class="px-4 py-2.5 rounded-xl border border-slate-200 text-slate-600 hover:bg-slate-50 font-bold text-xs transition-colors">
                        Batal
                     </button>
                     <button type="submit" class="px-5 py-2.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-bold text-xs transition-colors shadow-sm">
                        Simpan Pekerjaan Artikel
                     </button>
                  </div>
               </form>
            </div>
         </div>

         <!-- ==================================================================== -->
         <!-- MODAL EDIT JOB ACTIVITY (UPDATE CEKLIS & STATUS PEKERJAAN) -->
         <!-- ==================================================================== -->
         <div id="modalEditJobActivity" class="fixed inset-0 z-50 bg-slate-950/70 backdrop-blur-sm hidden items-center justify-center p-4 overflow-y-auto">
            <div class="bg-white rounded-3xl max-w-xl w-full border border-slate-200 shadow-2xl overflow-hidden my-8">
               <div class="p-5 sm:p-6 border-b border-slate-100 flex items-center justify-between bg-slate-50/70">
                  <div class="flex items-center gap-3">
                     <div class="w-10 h-10 rounded-2xl bg-blue-100 text-blue-600 flex items-center justify-center font-bold text-lg">
                        ✏️
                     </div>
                     <div>
                        <h3 class="text-base font-extrabold text-slate-900">Perbarui Ceklis &amp; Status Pekerjaan</h3>
                        <p class="text-xs text-slate-500">Centang tahapan pekerjaan yang sudah selesai dikerjakan.</p>
                         <p id="editJobPackageInfo" class="text-[11px] font-bold text-brand-600 mt-0.5"></p>
                     </div>
                  </div>
                  <button type="button" onclick="closeEditJobModal()" class="p-2 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-xl transition-colors">
                     <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                  </button>
               </div>

               <form method="POST" class="p-5 sm:p-6 space-y-4">
                  <input type="hidden" name="action" value="admin_update_job_activity">
                  <input type="hidden" name="job_id" id="editJobId" value="">

                  <!-- Judul Artikel -->
                  <div>
                     <label class="block text-xs font-bold text-slate-700 mb-1">Judul / Topik Artikel *</label>
                     <input type="text" name="title" id="editJobTitle" required
                        class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-medium text-slate-900 focus:outline-none focus:border-brand-600">
                  </div>

                  <!-- Tanggal Upload -->
                  <div>
                     <label class="block text-xs font-bold text-slate-700 mb-1">Tanggal Upload / Publish *</label>
                     <input type="date" name="upload_date" id="editJobUploadDate" required
                        class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-medium text-slate-900 focus:outline-none focus:border-brand-600">
                  </div>

                  <!-- JUMLAH PEKERJAAN (menyesuaikan paket klien) -->
                  <div>
                     <label class="block text-xs font-bold text-slate-700 mb-1.5">Jumlah Pekerjaan:</label>
                     <div class="grid grid-cols-3 gap-3">
                        <div data-feature="article">
                           <span class="block text-[11px] font-semibold text-slate-500 mb-1">📝 Artikel</span>
                           <input type="number" min="0" name="article_count" id="editJobArticleCount" value="1"
                              class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold text-slate-900 focus:outline-none focus:border-brand-600">
                        </div>
                        <div data-feature="photo">
                           <span class="block text-[11px] font-semibold text-slate-500 mb-1">🖼️ Foto</span>
                           <input type="number" min="0" name="photo_count" id="editJobPhotoCount" value="0"
                              class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold text-slate-900 focus:outline-none focus:border-brand-600">
                        </div>
                        <div data-feature="social">
                           <span class="block text-[11px] font-semibold text-slate-500 mb-1">📱 Post Medsos</span>
                           <input type="number" min="0" name="social_post_count" id="editJobSocialCount" value="0"
                              class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold text-slate-900 focus:outline-none focus:border-brand-600">
                        </div>
                     </div>
                  </div>

                  <!-- CEKLIS PEKERJAAN (hanya item yang termasuk paket klien) -->
                  <div>
                     <label class="block text-xs font-bold text-slate-700 mb-1.5">Ceklis Pengerjaan (Centang jika sudah selesai):</label>
                     <div class="space-y-2.5 p-4 rounded-2xl bg-slate-50 border border-slate-200 text-xs">
                        <?php
                           $editCheckIds = [
                              'check_image_opt'   => 'editCheckImageOpt',
                              'check_social_post' => 'editCheckSocialPost',
                              'check_social_link' => 'editCheckSocialLink',
                              'check_backup'      => 'editCheckBackup',
                           ];
                        ?>
                        <?php foreach (job_checklist_master() as $field => $def): ?>
                           <label data-feature="<?= $def['item'] ?>" class="flex items-start gap-3 cursor-pointer p-2 rounded-xl hover:bg-white transition-colors">
                              <input type="checkbox" name="<?= $field ?>" id="<?= $editCheckIds[$field] ?>" value="1" class="w-4 h-4 text-emerald-600 rounded mt-0.5">
                              <div>
                                 <strong class="text-slate-900 block font-bold"><?= $def['icon'] ?> <?= htmlspecialchars($def['label']) ?></strong>
                                 <span class="text-slate-500 text-[11px]"><?= htmlspecialchars($def['done']) ?></span>
                              </div>
                           </label>
                        <?php endforeach; ?>
                     </div>
                  </div>

                  <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                     <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Tautan Artikel di Website</label>
                        <input type="url" name="article_url" id="editJobArticleUrl"
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-medium focus:outline-none focus:border-brand-600"
                           placeholder="https://website-klien.com/artikel-slug">
                     </div>
                     <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Status Pekerjaan *</label>
                        <select name="status" id="editJobStatus" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-bold text-slate-800 bg-white focus:outline-none focus:border-brand-600">
                           <option value="in_progress">Dalam Proses Pengerjaan</option>
                           <option value="scheduled">Terjadwal Upload</option>
                           <option value="completed">Selesai &amp; Tayang (100%)</option>
                           <option value="draft">Draf Materi</option>
                        </select>
                     </div>
                  </div>

                  <div>
                     <label class="block text-xs font-bold text-slate-700 mb-1">Catatan Tim Pengelola</label>
                     <textarea name="notes" id="editJobNotes" rows="2"
                        class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-medium focus:outline-none focus:border-brand-600"
                        placeholder="Catatan pengerjaan atau hasil performa SEO..."></textarea>
                  </div>

                  <div class="pt-2 flex items-center justify-end gap-2.5">
                     <button type="button" onclick="closeEditJobModal()" class="px-4 py-2.5 rounded-xl border border-slate-200 text-slate-600 hover:bg-slate-50 font-bold text-xs transition-colors">
                        Batal
                     </button>
                     <button type="submit" class="px-5 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs transition-colors shadow-sm">
                        Simpan Perubahan Ceklis
                     </button>
                  </div>
               </form>
            </div>
         </div>


         <?php else: ?>
            <!-- ------------------------------------------------------------- -->
            <!-- CLIENT VIEW: AKTIVITAS PEKERJAAN & CEKLIS ARTIKEL             -->
            <!-- ------------------------------------------------------------- -->
            <div class="mb-8">
               <span class="text-xs font-extrabold uppercase tracking-wider text-brand-600 bg-brand-50 px-3 py-1 rounded-full border border-brand-200">
                  Transparansi Pekerjaan
               </span>
               <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 mt-2">Daftar Pekerjaan &amp; Jadwal Upload Artikel</h1>
               <p class="text-sm text-slate-500 mt-1 max-w-2xl">
                  Pantau transparansi progres pembuatan artikel SEO untuk <strong><?= htmlspecialchars($currentUser['business_name'] ?: 'bisnis Anda') ?></strong>, jadwal tanggal upload, serta ceklis optimasi gambar &amp; media sosial secara real-time.
               </p>
            </div>

            <!-- 4 Metrik Ringkas Klien -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6 mb-8">
               <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs flex items-center justify-between">
                  <div>
                     <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Total Artikel</span>
                     <div class="text-2xl sm:text-3xl font-black text-slate-900 mt-1"><?= $myJobsTotal ?></div>
                     <p class="text-[11px] text-slate-500 mt-1">Seluruh agenda konten</p>
                  </div>
                  <div class="w-12 h-12 rounded-2xl bg-slate-100 text-slate-700 flex items-center justify-center font-bold text-xl">
                     📝
                  </div>
               </div>

               <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs flex items-center justify-between">
                  <div>
                     <span class="text-xs font-bold text-amber-600 uppercase tracking-wider">Dalam Pengerjaan</span>
                     <div class="text-2xl sm:text-3xl font-black text-amber-600 mt-1"><?= $myJobsInProgress ?></div>
                     <p class="text-[11px] text-amber-700/80 mt-1">Sedang diproduksi tim</p>
                  </div>
                  <div class="w-12 h-12 rounded-2xl bg-amber-50 text-amber-600 flex items-center justify-center font-bold text-xl">
                     ⏳
                  </div>
               </div>

               <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs flex items-center justify-between">
                  <div>
                     <span class="text-xs font-bold text-blue-600 uppercase tracking-wider">Terjadwal Upload</span>
                     <div class="text-2xl sm:text-3xl font-black text-blue-600 mt-1"><?= $myJobsScheduled ?></div>
                     <p class="text-[11px] text-blue-700/80 mt-1">Siap tayang sesuai tanggal</p>
                  </div>
                  <div class="w-12 h-12 rounded-2xl bg-blue-50 text-blue-600 flex items-center justify-center font-bold text-xl">
                     📅
                  </div>
               </div>

               <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs flex items-center justify-between">
                  <div>
                     <span class="text-xs font-bold text-emerald-600 uppercase tracking-wider">Selesai &amp; Terbit</span>
                     <div class="text-2xl sm:text-3xl font-black text-emerald-600 mt-1"><?= $myJobsCompleted ?></div>
                     <p class="text-[11px] text-emerald-700/80 mt-1">100% Ceklis lengkap</p>
                  </div>
                  <div class="w-12 h-12 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center font-bold text-xl">
                     ✅
                  </div>
               </div>
            </div>

            <!-- KUOTA PAKET KLIEN -->
            <?php render_quota_panel($clientFeatures, job_usage($myJobActivities, date('Y-m')), $monthLabelId); ?>

            <!-- LIST KARTU PEKERJAAN ARTIKEL KLIEN -->
            <div class="space-y-4 mb-8">
               <?php if (empty($myJobActivities)): ?>
                  <div class="bg-white rounded-3xl p-12 text-center border border-slate-200/80 shadow-xs">
                     <div class="w-16 h-16 mx-auto mb-3 rounded-2xl bg-slate-100 flex items-center justify-center text-2xl">
                        📝
                     </div>
                     <h3 class="text-base font-extrabold text-slate-800">Belum Ada Agenda Pekerjaan Artikel</h3>
                     <p class="text-xs text-slate-500 max-w-md mx-auto mt-1">
                        Tim spesialis kami akan segera menjadwalkan pembuatan artikel dan ceklis optimasi setelah paket Anda aktif.
                     </p>
                  </div>
               <?php else: ?>
                  <?php foreach ($myJobActivities as $job): ?>
                     <?php
                        $prog = job_progress($job, $clientFeatures);
                        $doneCount = $prog['done'];
                        $totalChecks = $prog['total'];
                        $pct = $prog['pct'];
                        $checklist = job_checklist_for($clientFeatures);
                     ?>
                     <div class="bg-white rounded-3xl p-5 sm:p-6 border border-slate-200/80 shadow-xs hover:border-brand-200 transition-all">
                        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4 pb-4 border-b border-slate-100">
                           <div class="space-y-1">
                              <div class="flex items-center gap-2 flex-wrap">
                                 <span class="px-2.5 py-0.5 rounded-md text-[10px] font-extrabold uppercase tracking-wider bg-brand-50 text-brand-700 border border-brand-200">
                                    Artikel SEO Website
                                 </span>
                                 <span class="inline-flex items-center gap-1 font-mono text-xs font-bold text-slate-600 bg-slate-100 px-2.5 py-0.5 rounded-md">
                                    <span>📅 Jadwal Upload:</span> <?= date('d M Y', strtotime($job['upload_date'])) ?>
                                 </span>
                              </div>
                              <h3 class="text-base sm:text-lg font-extrabold text-slate-900 leading-snug">
                                 <?= htmlspecialchars($job['title']) ?>
                              </h3>
                              <div class="pt-1"><?= job_counts_html($job, $clientFeatures) ?></div>
                           </div>

                           <!-- Status & Action Link -->
                           <div class="flex items-center gap-3 shrink-0">
                              <?php if ($job['status'] === 'completed'): ?>
                                 <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                    <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                                    Selesai &amp; Tayang
                                 </span>
                              <?php elseif ($job['status'] === 'scheduled'): ?>
                                 <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-blue-50 text-blue-700 border border-blue-200">
                                    <span class="w-2 h-2 rounded-full bg-blue-500"></span>
                                    Terjadwal Upload
                                 </span>
                              <?php else: ?>
                                 <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                    <span class="w-2 h-2 rounded-full bg-amber-500 animate-pulse"></span>
                                    Sedang Dikerjakan
                                 </span>
                              <?php endif; ?>

                              <?php if (!empty($job['article_url'])): ?>
                                 <a href="<?= htmlspecialchars($job['article_url']) ?>" target="_blank"
                                    class="px-3.5 py-1.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-bold text-xs transition-colors inline-flex items-center gap-1.5 shadow-2xs">
                                    <span>Buka Artikel</span>
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                                 </a>
                              <?php endif; ?>
                           </div>
                        </div>

                        <!-- Progress Bar & Ceklis 3 Tahap -->
                        <div class="pt-4">
                           <div class="flex items-center justify-between text-xs font-bold mb-2">
                              <span class="<?= ($totalChecks > 0 && $doneCount === $totalChecks) ? 'text-emerald-700' : 'text-slate-700' ?>">
                                 Ceklis Pengerjaan: <?= $doneCount ?> dari <?= $totalChecks ?> Selesai
                              </span>
                              <span class="text-slate-500 font-extrabold"><?= $pct ?>%</span>
                           </div>
                           <div class="w-full bg-slate-100 h-2 rounded-full overflow-hidden mb-4">
                              <div class="h-full rounded-full transition-all <?= ($totalChecks > 0 && $doneCount === $totalChecks) ? 'bg-emerald-500' : ($doneCount > 0 ? 'bg-brand-500' : 'bg-slate-300') ?>" style="width: <?= $pct ?>%"></div>
                           </div>

                           <!-- Ceklis Cards (sesuai paket klien) -->
                           <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-3">
                              <?php foreach ($checklist as $field => $def): $isDone = !empty($job[$field]); ?>
                                 <div class="p-3.5 rounded-2xl border transition-all <?= $isDone ? 'bg-emerald-50/70 border-emerald-200 text-emerald-900' : 'bg-slate-50/60 border-slate-200 text-slate-500' ?>">
                                    <div class="flex items-center gap-2.5">
                                       <div class="w-7 h-7 rounded-lg flex items-center justify-center font-bold text-sm shrink-0 <?= $isDone ? 'bg-emerald-600 text-white' : 'bg-slate-200 text-slate-400' ?>">
                                          <?= $isDone ? '✓' : '—' ?>
                                       </div>
                                       <div>
                                          <h4 class="text-xs font-bold leading-tight"><?= htmlspecialchars($def['label']) ?></h4>
                                          <span class="text-[10px] <?= $isDone ? 'text-emerald-700' : 'text-slate-400' ?>">
                                             <?= htmlspecialchars($isDone ? $def['done'] : $def['wait']) ?>
                                          </span>
                                       </div>
                                    </div>
                                 </div>
                              <?php endforeach; ?>
                           </div>

                           <?php if (!empty($job['notes'])): ?>
                              <div class="mt-3 p-3 rounded-xl bg-slate-50 border border-slate-200/80 text-xs text-slate-600">
                                 <strong class="text-slate-800">Catatan Tim:</strong> <?= htmlspecialchars($job['notes']) ?>
                              </div>
                           <?php endif; ?>
                        </div>
                     </div>
                  <?php endforeach; ?>
               <?php endif; ?>
            </div>

         <?php endif; ?>

      <?php endif; ?>

      <!-- ==================================================================== -->
      <!-- TAB 1: RINGKASAN AKUN (OVERVIEW) -->
      <!-- ==================================================================== -->
      <?php if (!$isAdmin && $activeTab === 'ringkasan'): ?>
         <!-- Welcome Banner -->
         <div class="bg-gradient-to-r from-slate-950 via-slate-900 to-brand-950 text-white rounded-3xl p-6 sm:p-8 shadow-xl mb-8 relative overflow-hidden">
            <div class="absolute -right-10 -bottom-10 w-64 h-64 bg-brand-600/25 rounded-full blur-3xl pointer-events-none"></div>
            <div class="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-6">
               <div>
                  <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-white/10 text-brand-300 text-xs font-semibold mb-3 border border-white/10">
                     <span>● Akun Terverifikasi</span>
                     <span class="text-slate-400">•</span>
                     <span>ID Klien #<?= $currentUser['id'] ?></span>
                  </div>
                  <h1 class="text-2xl sm:text-3xl font-extrabold tracking-tight">Halo, <?= htmlspecialchars($currentUser['name']) ?>!</h1>
                  <p class="text-slate-300 text-sm mt-2 max-w-2xl leading-relaxed">
                     Selamat datang di Portal Mandiri bisnis <strong class="text-white"><?= htmlspecialchars($currentUser['business_name'] ?: 'Anda') ?></strong>. Di sini Anda dapat memesan paket layanan baru, mengirimkan bukti pembayaran, memantau laporan kegiatan artikel SEO &amp; medsos, serta memperbarui profil usaha Anda.
                  </p>
               </div>
               <div class="flex flex-wrap items-center gap-3">
                  <a href="dashboard.php?tab=pesan" class="px-5 py-3 rounded-xl bg-gradient-to-r from-brand-600 to-orange-500 hover:from-brand-700 hover:to-orange-600 text-white font-bold text-xs sm:text-sm shadow-lg shadow-brand-600/30 transition-all flex items-center gap-2 active:scale-95">
                     <span>🛒 Pesan Paket Layanan</span>
                  </a>
                  <a href="https://wa.me/<?= $contact_wa ?>?text=Halo%20OkeIniAja%2C%20saya%20klien%20<?= urlencode($currentUser['business_name'] ?: $currentUser['name']) ?>%20ingin%20konsultasi"
                     target="_blank" rel="noopener"
                     class="px-5 py-3 rounded-xl bg-white/10 hover:bg-white/20 text-white font-semibold text-xs sm:text-sm border border-white/15 transition-all flex items-center gap-2">
                     <span>💬 Chat WhatsApp CS</span>
                  </a>
               </div>
            </div>
         </div>

         <!-- 4 Stat Cards Scoped to This User -->
         <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-8">
            <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm">
               <div class="flex items-center justify-between">
                  <span class="text-xs font-bold text-slate-500 uppercase tracking-wider">Artikel SEO Diterbitkan</span>
                  <span class="w-8 h-8 rounded-lg bg-brand-50 text-brand-600 flex items-center justify-center font-bold text-xs">📝</span>
               </div>
               <div class="flex items-baseline gap-2 mt-3">
                  <span class="text-2xl sm:text-3xl font-extrabold text-slate-900"><?= $countArticles ?></span>
                  <span class="text-xs font-bold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded-full">Untuk Web Anda</span>
               </div>
               <p class="text-[11px] text-slate-400 mt-1.5">Artikel ramah algoritma Google</p>
            </div>

            <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm">
               <div class="flex items-center justify-between">
                  <span class="text-xs font-bold text-slate-500 uppercase tracking-wider">Konten Medsos Siap/Tayang</span>
                  <span class="w-8 h-8 rounded-lg bg-orange-50 text-orange-600 flex items-center justify-center font-bold text-xs">📱</span>
               </div>
               <div class="flex items-baseline gap-2 mt-3">
                  <span class="text-2xl sm:text-3xl font-extrabold text-slate-900"><?= $countMedsos ?></span>
                  <span class="text-xs font-bold text-brand-600 bg-brand-50 px-2 py-0.5 rounded-full">Instagram &amp; FB</span>
               </div>
               <p class="text-[11px] text-slate-400 mt-1.5">Desain Feed, Carousel &amp; Reels</p>
            </div>

            <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm">
               <div class="flex items-center justify-between">
                  <span class="text-xs font-bold text-slate-500 uppercase tracking-wider">Lead WhatsApp Masuk</span>
                  <span class="w-8 h-8 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center font-bold text-xs">💬</span>
               </div>
               <div class="flex items-baseline gap-2 mt-3">
                  <span class="text-2xl sm:text-3xl font-extrabold text-slate-900"><?= $countLeads ?> Kontak</span>
                  <span class="text-xs font-bold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded-full">Prospek Pembeli</span>
               </div>
               <p class="text-[11px] text-slate-400 mt-1.5">Tercatat dari tombol web &amp; medsos</p>
            </div>

            <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm">
               <div class="flex items-center justify-between">
                  <span class="text-xs font-bold text-slate-500 uppercase tracking-wider">Status Tagihan / Pesanan</span>
                  <span class="w-8 h-8 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center font-bold text-xs">💳</span>
               </div>
               <div class="flex items-baseline gap-2 mt-3">
                  <span class="text-2xl sm:text-3xl font-extrabold <?= $countPendingOrders > 0 ? 'text-rose-600' : 'text-emerald-600' ?>">
                     <?= $countPendingOrders > 0 ? $countPendingOrders . ' Menunggu' : 'Lunas / Siap' ?>
                  </span>
               </div>
               <p class="text-[11px] text-slate-400 mt-1.5">
                  <a href="dashboard.php?tab=invoice" class="text-brand-600 font-bold hover:underline">Lihat Invoice Tagihan &rarr;</a>
               </p>
            </div>
         </div>

         <!-- Status Paket & Quick Action Panels -->
         <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- Kolom Kiri: Pesanan & Paket Aktif User -->
            <div class="lg:col-span-2 space-y-6">
               <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm">
                  <div class="flex items-center justify-between mb-4">
                     <div>
                        <h2 class="text-base font-extrabold text-slate-900">Pesanan Layanan Terakhir Anda</h2>
                        <p class="text-xs text-slate-500">Daftar paket yang telah Anda pesan di portal ini.</p>
                     </div>
                     <a href="dashboard.php?tab=pesan" class="text-xs font-bold text-brand-600 hover:underline">+ Pesan Paket Baru</a>
                  </div>

                  <?php if (empty($myOrders)): ?>
                     <div class="p-8 text-center bg-slate-50 rounded-2xl border border-dashed border-slate-300">
                        <span class="text-3xl block mb-2">📦</span>
                        <h3 class="font-bold text-slate-800 text-sm">Belum Ada Pesanan Layanan</h3>
                        <p class="text-xs text-slate-500 mt-1 max-w-md mx-auto">
                           Anda belum memiliki pesanan paket pengelolaan. Pilih paket pengelolaan website dan media sosial agar tim kami dapat segera bekerja!
                        </p>
                        <a href="dashboard.php?tab=pesan" class="mt-4 inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-brand-600 text-white font-bold text-xs hover:bg-brand-700 transition-all shadow-sm">
                           <span>Pilih Paket Layanan Sekarang &rarr;</span>
                        </a>
                     </div>
                  <?php else: ?>
                     <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs sm:text-sm">
                           <thead class="bg-slate-50 text-slate-600 border-b border-slate-200">
                              <tr>
                                 <th class="p-3">Kode Order</th>
                                 <th class="p-3">Paket Layanan</th>
                                 <th class="p-3">Tagihan</th>
                                 <th class="p-3">Status</th>
                                 <th class="p-3 text-right">Aksi</th>
                              </tr>
                           </thead>
                           <tbody class="divide-y divide-slate-100">
                              <?php foreach (array_slice($myOrders, 0, 5) as $ord): ?>
                                 <tr>
                                    <td class="p-3 font-mono font-bold text-slate-900"><?= htmlspecialchars($ord['order_code']) ?></td>
                                    <td class="p-3">
                                       <span class="font-bold text-slate-800"><?= htmlspecialchars($ord['package_name']) ?></span>
                                       <span class="block text-[11px] text-slate-400 capitalize"><?= $ord['billing_cycle'] === 'yearly' ? 'Tahunan (Hemat 15%)' : 'Bulanan' ?></span>
                                    </td>
                                    <td class="p-3 font-semibold text-slate-900">Rp <?= number_format($ord['total_amount'], 0, ',', '.') ?></td>
                                    <td class="p-3">
                                       <?php if ($ord['status'] === 'pending_payment'): ?>
                                          <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-amber-100 text-amber-800">Menunggu Bayar</span>
                                       <?php elseif ($ord['status'] === 'reviewing'): ?>
                                          <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-blue-100 text-blue-800">Diverifikasi</span>
                                       <?php elseif ($ord['status'] === 'active'): ?>
                                          <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-emerald-100 text-emerald-800">Aktif Berjalan</span>
                                       <?php else: ?>
                                          <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-slate-100 text-slate-600"><?= htmlspecialchars($ord['status']) ?></span>
                                       <?php endif; ?>
                                    </td>
                                    <td class="p-3 text-right">
                                       <?php if ($ord['status'] === 'pending_payment'): ?>
                                          <a href="dashboard.php?tab=pembayaran&order_id=<?= $ord['id'] ?>" class="px-3 py-1 bg-brand-600 hover:bg-brand-700 text-white font-bold rounded-lg text-xs transition-all shadow-sm">
                                             Konfirmasi Bayar
                                          </a>
                                       <?php else: ?>
                                          <a href="dashboard.php?tab=pembayaran" class="text-xs text-brand-600 font-bold hover:underline">Lihat Rincian</a>
                                       <?php endif; ?>
                                    </td>
                                 </tr>
                              <?php endforeach; ?>
                           </tbody>
                        </table>
                     </div>
                  <?php endif; ?>
               </div>

               <!-- Riwayat Kegiatan Terakhir User Ini -->
               <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm">
                  <div class="flex items-center justify-between mb-4">
                     <div>
                        <h2 class="text-base font-extrabold text-slate-900">Laporan &amp; Log Kegiatan Akun Anda</h2>
                        <p class="text-xs text-slate-500">Catatan aktivitas pengerjaan aset digital bisnis Anda.</p>
                     </div>
                     <a href="dashboard.php?tab=laporan" class="text-xs font-bold text-brand-600 hover:underline">Semua Laporan &rarr;</a>
                  </div>

                  <div class="space-y-3">
                     <?php foreach (array_slice($myActivities, 0, 4) as $act): ?>
                        <div class="p-3.5 rounded-2xl bg-slate-50 border border-slate-200 flex items-start justify-between gap-3">
                           <div class="flex items-start gap-3">
                              <span class="w-8 h-8 rounded-xl bg-white border border-slate-200 flex items-center justify-center text-xs shrink-0 mt-0.5 shadow-2xs">
                                 <?php
                                 switch ($act['category']) {
                                    case 'article': echo '📝'; break;
                                    case 'social_media': echo '📱'; break;
                                    case 'lead': echo '💬'; break;
                                    case 'website': echo '⚡'; break;
                                    default: echo '📌'; break;
                                 }
                                 ?>
                              </span>
                              <div>
                                 <h4 class="font-bold text-slate-900 text-xs sm:text-sm"><?= htmlspecialchars($act['title']) ?></h4>
                                 <p class="text-xs text-slate-500 mt-0.5 leading-relaxed"><?= htmlspecialchars($act['description'] ?: '-') ?></p>
                                 <span class="text-[10px] text-slate-400 mt-1 block"><?= date('d M Y, H:i', strtotime($act['created_at'])) ?> WIB</span>
                              </div>
                           </div>
                           <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase shrink-0 <?= $act['status'] === 'completed' || $act['status'] === 'published' ? 'bg-emerald-100 text-emerald-800' : 'bg-brand-100 text-brand-800' ?>">
                              <?= htmlspecialchars($act['status']) ?>
                           </span>
                        </div>
                     <?php endforeach; ?>
                  </div>
               </div>
            </div>

            <!-- Kolom Kanan: Rangkuman Profil Usaha & Support -->
            <div class="space-y-6">
               <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm">
                  <h2 class="text-base font-extrabold text-slate-900 mb-1">Profil Usaha Anda</h2>
                  <p class="text-xs text-slate-500 mb-4">Informasi identitas bisnis yang terhubung ke layanan kami.</p>

                  <div class="space-y-3 text-xs">
                     <div class="p-3 bg-slate-50 rounded-xl border border-slate-100">
                        <span class="text-slate-400 block text-[11px]">Nama Usaha / Brand</span>
                        <strong class="text-slate-900 font-bold text-sm block mt-0.5"><?= htmlspecialchars($currentUser['business_name'] ?: '-') ?></strong>
                     </div>
                     <div class="p-3 bg-slate-50 rounded-xl border border-slate-100">
                        <span class="text-slate-400 block text-[11px]">Penanggung Jawab (PIC)</span>
                        <strong class="text-slate-900 font-bold block mt-0.5"><?= htmlspecialchars($currentUser['name']) ?></strong>
                     </div>
                     <div class="p-3 bg-slate-50 rounded-xl border border-slate-100">
                        <span class="text-slate-400 block text-[11px]">WhatsApp Terhubung</span>
                        <strong class="text-slate-900 font-bold block mt-0.5">+<?= htmlspecialchars($currentUser['whatsapp']) ?></strong>
                     </div>
                     <div class="p-3 bg-slate-50 rounded-xl border border-slate-100">
                        <span class="text-slate-400 block text-[11px]">Website Usaha</span>
                        <strong class="text-slate-900 font-bold block mt-0.5"><?= htmlspecialchars($currentUser['website_url'] ?: 'Belum diatur') ?></strong>
                     </div>
                  </div>

                  <a href="dashboard.php?tab=profil" class="mt-4 w-full py-2.5 text-center font-bold text-xs text-slate-700 bg-slate-100 hover:bg-slate-200 rounded-xl transition-all block">
                     Edit Profil &amp; Password
                  </a>
               </div>

               <!-- Hubungi Manajer Akun -->
               <div class="bg-gradient-to-br from-emerald-900 to-slate-900 text-white p-6 rounded-3xl shadow-md relative overflow-hidden">
                  <h3 class="font-extrabold text-sm mb-1">Butuh Bantuan Langsung?</h3>
                  <p class="text-xs text-slate-300 leading-relaxed mb-4">
                     Punya materi katalog baru, permintaan artikel khusus, atau ingin konsultasi strategi? Tim manajer akun siap membantu via WhatsApp.
                  </p>
                  <a href="https://wa.me/<?= $contact_wa ?>?text=Halo%20OkeIniAja%2C%20saya%20klien%20<?= urlencode($currentUser['business_name'] ?: $currentUser['name']) ?>"
                     target="_blank" rel="noopener"
                     class="w-full py-3 bg-emerald-600 hover:bg-emerald-500 text-white font-extrabold rounded-xl transition-all text-xs flex items-center justify-center gap-2 shadow-lg">
                     <svg class="w-4 h-4 fill-current" viewBox="0 0 24 24"><path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z"/></svg>
                     <span>Hubungi via WhatsApp</span>
                  </a>
               </div>
            </div>
         </div>
      <?php endif; ?>

      <!-- ==================================================================== -->
      <!-- TAB 2: PESAN LAYANAN PAKET (SELF SERVICE ORDER) -->
      <!-- ==================================================================== -->
      <?php if (!$isAdmin && $activeTab === 'pesan'): ?>
         <div class="mb-8">
            <span class="text-xs font-extrabold uppercase tracking-wider text-brand-600 bg-brand-50 px-3 py-1 rounded-full border border-brand-200">
               Self-Service Pemesanan Paket
            </span>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 mt-2">Pilih Paket Pengelolaan Sesuai Kebutuhan Usaha</h1>
            <p class="text-sm text-slate-500 mt-1 max-w-2xl">
               Semua paket dikerjakan langsung oleh tim spesialis OkeIniAja secara "Terima Beres". Anda bebas memilih paket dan masa berlangganan.
            </p>
         </div>

         <!-- Pricing Cards Grid -->
         <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-12">
            <!-- Card 1: Basic Maintenance -->
            <div class="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm flex flex-col justify-between hover:border-slate-400 transition-all">
               <div>
                  <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Untuk Solopreneur &amp; UMKM Baru</span>
                  <h3 class="text-xl font-extrabold text-slate-900 mt-1 mb-2">Basic Maintenance</h3>
                  <p class="text-xs text-slate-500 mb-5 min-h-[36px]">Website &amp; medsos tetap terawat, rapi, dan aktif tanpa membebani keuangan.</p>

                  <div class="p-4 bg-slate-50 rounded-2xl border border-slate-100 mb-6">
                     <span class="text-2xl sm:text-3xl font-black text-slate-900">Rp 75.000</span>
                     <span class="text-xs text-slate-500">/ bulan</span>
                     <p class="text-[11px] text-slate-400 mt-1">Ditagih bulanan atau tahunan</p>
                  </div>

                  <ul class="space-y-2.5 text-xs text-slate-600 mb-6 border-t border-slate-100 pt-4">
                     <li class="flex items-center gap-2"><span class="text-emerald-600 font-bold">✓</span> <strong>2 Artikel SEO Website</strong> / bulan</li>
                     <li class="flex items-center gap-2"><span class="text-emerald-600 font-bold">✓</span> <strong>8 Postingan Medsos</strong> (Instagram &amp; FB)</li>
                     <li class="flex items-center gap-2"><span class="text-emerald-600 font-bold">✓</span> Kompresi WebP &amp; Upload 10 Foto Produk</li>
                     <li class="flex items-center gap-2"><span class="text-emerald-600 font-bold">✓</span> Integrasi Tombol WhatsApp Direct Lead</li>
                  </ul>
               </div>

               <button onclick="openOrderModal('Basic Maintenance', 75000)"
                  class="w-full py-3 px-4 rounded-xl border-2 border-slate-800 text-slate-900 font-bold text-xs hover:bg-slate-900 hover:text-white transition-all text-center">
                  Pesan Paket Basic
               </button>
            </div>

            <!-- Card 2: Standard Growth (Populer) -->
            <div class="bg-white rounded-3xl p-6 border-2 border-brand-600 shadow-lg flex flex-col justify-between relative transform md:-translate-y-2">
               <div class="absolute -top-3.5 left-1/2 -translate-x-1/2 bg-gradient-to-r from-brand-600 to-orange-500 text-white text-[10px] font-extrabold uppercase px-3 py-1 rounded-full shadow">
                  🔥 PALING BANYAK DIPILIH
               </div>

               <div>
                  <span class="text-[11px] font-bold text-brand-600 uppercase tracking-wider">Untuk Bisnis yang Ingin Tumbuh Cepat</span>
                  <h3 class="text-xl font-extrabold text-slate-900 mt-1 mb-2">Standard Growth</h3>
                  <p class="text-xs text-slate-500 mb-5 min-h-[36px]">Mendongkrak trafik Google, merapikan katalog, dan menjaring leads tiap minggu.</p>

                  <div class="p-4 bg-brand-50/70 rounded-2xl border border-brand-100 mb-6">
                     <span class="text-2xl sm:text-3xl font-black text-brand-700">Rp 150.000</span>
                     <span class="text-xs text-slate-500">/ bulan</span>
                     <p class="text-[11px] text-brand-700/80 mt-1">Garansi pengerjaan rapi &amp; tepat waktu</p>
                  </div>

                  <ul class="space-y-2.5 text-xs text-slate-700 mb-6 border-t border-slate-100 pt-4">
                     <li class="flex items-center gap-2"><span class="text-emerald-600 font-bold">✓</span> <strong>4 Artikel SEO Berbobot</strong> (800-1.000 kata)</li>
                     <li class="flex items-center gap-2"><span class="text-emerald-600 font-bold">✓</span> <strong>12-15 Post Medsos</strong> (Feed, Carousel, Reels)</li>
                     <li class="flex items-center gap-2"><span class="text-emerald-600 font-bold">✓</span> Upload 20 Foto Produk + Alt Text Google</li>
                     <li class="flex items-center gap-2"><span class="text-emerald-600 font-bold">✓</span> Backup Rutin Database &amp; File Website</li>
                     <li class="flex items-center gap-2"><span class="text-emerald-600 font-bold">✓</span> Evaluasi &amp; Konsultasi Konten Berkala</li>
                  </ul>
               </div>

               <button onclick="openOrderModal('Standard Growth', 150000)"
                  class="w-full py-3 px-4 rounded-xl bg-gradient-to-r from-brand-600 to-orange-500 hover:from-brand-700 hover:to-orange-600 text-white font-extrabold text-xs shadow-md shadow-brand-500/30 transition-all text-center">
                  Pesan Paket Standard Growth
               </button>
            </div>

            <!-- Card 3: Premium Pro -->
            <div class="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm flex flex-col justify-between hover:border-slate-400 transition-all">
               <div>
                  <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Untuk Skala Penuh &amp; Prioritas</span>
                  <h3 class="text-xl font-extrabold text-slate-900 mt-1 mb-2">Premium Pro</h3>
                  <p class="text-xs text-slate-500 mb-5 min-h-[36px]">Penetrasi pasar agresif, SEO intensif, konten melimpah, dan support VIP.</p>

                  <div class="p-4 bg-slate-50 rounded-2xl border border-slate-100 mb-6">
                     <span class="text-2xl sm:text-3xl font-black text-slate-900">Rp 275.000</span>
                     <span class="text-xs text-slate-500">/ bulan</span>
                     <p class="text-[11px] text-slate-400 mt-1">Dukungan teknis prioritas VIP</p>
                  </div>

                  <ul class="space-y-2.5 text-xs text-slate-600 mb-6 border-t border-slate-100 pt-4">
                     <li class="flex items-center gap-2"><span class="text-emerald-600 font-bold">✓</span> <strong>8 Artikel SEO Mendalam</strong> / bulan</li>
                     <li class="flex items-center gap-2"><span class="text-emerald-600 font-bold">✓</span> <strong>20 Post Medsos</strong> Lengkap dengan Video</li>
                     <li class="flex items-center gap-2"><span class="text-emerald-600 font-bold">✓</span> Upload Katalog Produk Tak Terbatas</li>
                     <li class="flex items-center gap-2"><span class="text-emerald-600 font-bold">✓</span> Backup Mingguan &amp; Audit Keamanan Web</li>
                     <li class="flex items-center gap-2"><span class="text-emerald-600 font-bold">✓</span> Konsultasi Strategi &amp; Support Prioritas VIP</li>
                  </ul>
               </div>

               <button onclick="openOrderModal('Premium Pro', 275000)"
                  class="w-full py-3 px-4 rounded-xl border-2 border-slate-800 text-slate-900 font-bold text-xs hover:bg-slate-900 hover:text-white transition-all text-center">
                  Pesan Paket Premium Pro
               </button>
            </div>
         </div>

         <!-- Modal Pemesanan Paket -->
         <div id="orderModal" class="fixed inset-0 z-50 bg-slate-950/70 backdrop-blur-sm hidden items-center justify-center p-4">
            <div class="bg-white rounded-3xl max-w-lg w-full p-6 sm:p-8 shadow-2xl border border-slate-200 relative max-h-[90vh] overflow-y-auto">
               <button type="button" onclick="closeOrderModal()" class="absolute top-5 right-5 text-slate-400 hover:text-slate-700 text-lg">✕</button>

               <div class="mb-5">
                  <span class="text-[11px] font-bold text-brand-600 uppercase tracking-wider">Formulir Pemesanan Layanan</span>
                  <h3 class="text-2xl font-extrabold text-slate-900 mt-1" id="modalPlanTitle">Pilih Paket</h3>
                  <p class="text-xs text-slate-500 mt-1">Pesanan akan terdaftar ke akun Anda dan langsung siap diverifikasi setelah konfirmasi pembayaran.</p>
               </div>

               <form method="POST" action="dashboard.php" class="space-y-4">
                  <input type="hidden" name="action" value="order_package">
                  <input type="hidden" name="package_name" id="inputPackageName">

                  <div>
                     <label class="block text-xs font-bold text-slate-700 mb-1">Siklus Berlangganan *</label>
                     <div class="grid grid-cols-2 gap-3">
                        <label class="flex items-center gap-2 p-3 rounded-xl border border-slate-200 cursor-pointer hover:border-brand-500">
                           <input type="radio" name="billing_cycle" value="monthly" checked onchange="calculateOrderTotal()" class="text-brand-600">
                           <div class="text-xs">
                              <strong class="block text-slate-800">Bulanan</strong>
                              <span class="text-slate-400 text-[11px]">Fleksibel, batal kapan saja</span>
                           </div>
                        </label>
                        <label class="flex items-center gap-2 p-3 rounded-xl border border-slate-200 cursor-pointer hover:border-brand-500">
                           <input type="radio" name="billing_cycle" value="yearly" onchange="calculateOrderTotal()" class="text-brand-600">
                           <div class="text-xs">
                              <strong class="block text-slate-800">Tahunan</strong>
                              <span class="text-emerald-600 font-bold text-[11px]">Hemat 15% (2 Bln Gratis)</span>
                           </div>
                        </label>
                     </div>
                  </div>

                  <div>
                     <label class="block text-xs font-bold text-slate-700 mb-1">Kode Voucher Promo</label>
                     <div class="flex gap-2">
                        <input type="text" name="voucher_code" id="voucherCode" placeholder="Masukkan HEMAT40"
                           class="flex-1 px-3.5 py-2 uppercase rounded-xl border border-slate-200 text-xs focus:outline-none focus:border-brand-600">
                        <button type="button" onclick="calculateOrderTotal()" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-800 text-xs font-bold rounded-xl transition-all">
                           Cek
                        </button>
                     </div>
                     <span class="text-[10px] text-slate-400 mt-1 block">Gunakan kode <strong class="text-brand-600">HEMAT40</strong> untuk promo diskon 40%</span>
                  </div>

                  <div>
                     <label class="block text-xs font-bold text-slate-700 mb-1">Catatan Kebutuhan Khusus</label>
                     <textarea name="notes" rows="2" placeholder="Contoh: Fokuskan pada produk tenda sarnafil &amp; target area Jabodetabek"
                        class="w-full px-3.5 py-2 rounded-xl border border-slate-200 text-xs focus:outline-none focus:border-brand-600"></textarea>
                  </div>

                  <!-- Ringkasan Biaya -->
                  <div class="p-4 bg-slate-50 rounded-2xl border border-slate-200 text-xs space-y-1.5">
                     <div class="flex justify-between text-slate-500">
                        <span>Biaya Paket:</span>
                        <span id="txtSubtotal">Rp 0</span>
                     </div>
                     <div class="flex justify-between text-emerald-600 font-medium">
                        <span>Total Hemat / Diskon:</span>
                        <span id="txtDiscount">- Rp 0</span>
                     </div>
                     <div class="flex justify-between text-slate-900 font-extrabold text-sm border-t border-slate-200 pt-2">
                        <span>Total Pembayaran:</span>
                        <span id="txtTotal" class="text-brand-600">Rp 0</span>
                     </div>
                  </div>

                  <button type="submit"
                     class="w-full py-3.5 bg-brand-600 hover:bg-brand-700 text-white font-extrabold rounded-xl transition-all text-xs sm:text-sm shadow-md active:scale-95">
                     Konfirmasi &amp; Lanjutkan ke Pembayaran &rarr;
                  </button>
               </form>
            </div>
         </div>
      <?php endif; ?>

            <!-- ==================================================================== -->
      <!-- TAB INVOICE: DAFTAR TAGIHAN & INVOICE RESMI -->
      <!-- ==================================================================== -->
      <?php if (!$isAdmin && $activeTab === 'invoice'): ?>
         <div class="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
               <span class="text-xs font-extrabold uppercase tracking-wider text-brand-600 bg-brand-50 px-3 py-1 rounded-full border border-brand-200">
                  Tagihan &amp; Invoice Resmi
               </span>
               <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 mt-2">Manajemen Tagihan &amp; Invoice</h1>
               <p class="text-sm text-slate-500 mt-1 max-w-2xl">
                  Pantau seluruh tagihan pesanan paket usaha Anda, cek status pembayaran, dan unduh/cetak nota invoice resmi.
               </p>
            </div>
            <a href="dashboard.php?tab=pesan"
               class="px-5 py-2.5 rounded-xl bg-slate-900 hover:bg-brand-600 text-white font-bold text-xs transition-all w-fit shadow-sm flex items-center gap-2">
               <span>+ Pesan Layanan Baru</span>
            </a>
         </div>

         <!-- 3 SUMMARY STAT CARDS -->
         <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 sm:gap-6 mb-8">
            <!-- 1. Total Invoice -->
            <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs flex items-center justify-between">
               <div>
                  <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Total Invoice</span>
                  <div class="text-2xl sm:text-3xl font-black text-slate-900 mt-1">
                     <?= $totalInvoiceCount ?>
                     <span class="text-xs font-medium text-slate-400">Tagihan</span>
                  </div>
                  <p class="text-xs text-slate-500 font-semibold mt-1">Total Nilai: Rp <?= number_format($totalInvoiceAmount, 0, ',', '.') ?></p>
               </div>
               <div class="w-12 h-12 rounded-2xl bg-slate-100 text-slate-700 flex items-center justify-center font-bold">
                  <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                     <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                  </svg>
               </div>
            </div>

            <!-- 2. Sudah Lunas -->
            <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs flex items-center justify-between">
               <div>
                  <span class="text-xs font-bold text-emerald-600 uppercase tracking-wider">Sudah Lunas</span>
                  <div class="text-2xl sm:text-3xl font-black text-emerald-600 mt-1">
                     <?= $paidInvoiceCount ?>
                     <span class="text-xs font-medium text-emerald-600/70">Terbayar</span>
                  </div>
                  <p class="text-xs text-emerald-700/80 font-semibold mt-1">Rp <?= number_format($paidInvoiceAmount, 0, ',', '.') ?> (Layanan Aktif)</p>
               </div>
               <div class="w-12 h-12 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center font-bold">
                  <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                     <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                  </svg>
               </div>
            </div>

            <!-- 3. Menunggu Bayar -->
            <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs flex items-center justify-between">
               <div>
                  <span class="text-xs font-bold text-amber-600 uppercase tracking-wider">Menunggu Bayar</span>
                  <div class="text-2xl sm:text-3xl font-black text-amber-600 mt-1">
                     <?= $pendingInvoiceCount ?>
                     <span class="text-xs font-medium text-amber-600/70">Tertunda</span>
                  </div>
                  <p class="text-xs text-amber-700/80 font-semibold mt-1">Rp <?= number_format($pendingInvoiceAmount, 0, ',', '.') ?> belum diselesaikan</p>
               </div>
               <div class="w-12 h-12 rounded-2xl bg-amber-50 text-amber-600 flex items-center justify-center font-bold">
                  <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                     <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                  </svg>
               </div>
            </div>
         </div>

         <!-- DAFTAR INVOICE TABLE & FILTER -->
         <div class="bg-white rounded-3xl border border-slate-200/80 shadow-xs overflow-hidden mb-8">
            
            <!-- Filter Bar -->
            <div class="p-5 border-b border-slate-200/80 flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-slate-50/50">
               <div class="flex items-center gap-2">
                  <span class="text-xs font-bold text-slate-700">Filter Tampilan:</span>
                  <div class="inline-flex p-1 bg-slate-200/70 rounded-xl text-xs font-semibold">
                     <a href="dashboard.php?tab=invoice&filter=all" 
                        class="px-3 py-1.5 rounded-lg transition-all <?= $invoiceFilter === 'all' ? 'bg-white text-slate-900 shadow-xs font-bold' : 'text-slate-600 hover:text-slate-900' ?>">
                        Semua (<?= $totalInvoiceCount ?>)
                     </a>
                     <a href="dashboard.php?tab=invoice&filter=unpaid" 
                        class="px-3 py-1.5 rounded-lg transition-all <?= $invoiceFilter === 'unpaid' ? 'bg-white text-amber-700 shadow-xs font-bold' : 'text-slate-600 hover:text-slate-900' ?>">
                        Menunggu Bayar (<?= $pendingInvoiceCount ?>)
                     </a>
                     <a href="dashboard.php?tab=invoice&filter=paid" 
                        class="px-3 py-1.5 rounded-lg transition-all <?= $invoiceFilter === 'paid' ? 'bg-white text-emerald-700 shadow-xs font-bold' : 'text-slate-600 hover:text-slate-900' ?>">
                        Sudah Lunas (<?= $paidInvoiceCount ?>)
                     </a>
                  </div>
               </div>

               <?php if ($pendingInvoiceCount > 0): ?>
                  <a href="dashboard.php?tab=pembayaran" class="text-xs font-bold text-brand-600 hover:text-brand-700 flex items-center gap-1">
                     <span>Bayar Tagihan Tertunda</span>
                     <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                  </a>
               <?php endif; ?>
            </div>

            <!-- List Content -->
            <?php
               $displayedInvoices = $myOrders;
               if ($invoiceFilter === 'unpaid') {
                  $displayedInvoices = $unpaidInvoices;
               } elseif ($invoiceFilter === 'paid') {
                  $displayedInvoices = $paidInvoices;
               }
            ?>

            <?php if (empty($displayedInvoices)): ?>
               <div class="py-16 px-4 text-center">
                  <div class="w-16 h-16 mx-auto mb-4 rounded-2xl bg-slate-100 flex items-center justify-center text-slate-400">
                     <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                     </svg>
                  </div>
                  <h4 class="text-base font-bold text-slate-800">Tidak Ada Tagihan Ditemukan</h4>
                  <p class="text-xs text-slate-500 max-w-sm mx-auto mt-1">
                     <?= $invoiceFilter === 'unpaid' 
                        ? 'Bagus! Semua tagihan Anda saat ini sudah diselesaikan.' 
                        : ($invoiceFilter === 'paid' ? 'Belum ada riwayat invoice yang lunas.' : 'Anda belum memiliki tagihan pesanan paket.') ?>
                  </p>
                  <a href="dashboard.php?tab=pesan" class="mt-4 inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-brand-600 text-white font-bold text-xs hover:bg-brand-700 transition-all shadow-sm">
                     <span>Pesan Paket Sekarang &rarr;</span>
                  </a>
               </div>
            <?php else: ?>
               <div class="overflow-x-auto">
                  <table class="w-full text-left border-collapse text-xs">
                     <thead>
                        <tr class="bg-slate-50/80 border-b border-slate-200/80 text-slate-500 uppercase tracking-wider font-bold">
                           <th class="py-3.5 px-5">No. Invoice</th>
                           <th class="py-3.5 px-5">Tanggal</th>
                           <th class="py-3.5 px-5">Paket &amp; Siklus</th>
                           <th class="py-3.5 px-5">Rincian Nilai</th>
                           <th class="py-3.5 px-5">Status</th>
                           <th class="py-3.5 px-5 text-right">Aksi</th>
                        </tr>
                     </thead>
                     <tbody class="divide-y divide-slate-100">
                        <?php foreach ($displayedInvoices as $inv): ?>
                           <tr class="hover:bg-slate-50/60 transition-colors">
                              <td class="py-4 px-5">
                                 <span class="font-mono font-bold text-slate-900 block"><?= htmlspecialchars($inv['order_code']) ?></span>
                                 <span class="text-[10px] text-slate-400">ID #<?= $inv['id'] ?></span>
                              </td>
                              <td class="py-4 px-5 text-slate-600">
                                 <span class="font-semibold text-slate-800 block"><?= date('d M Y', strtotime($inv['created_at'])) ?></span>
                                 <span class="text-[10px] text-slate-400"><?= date('H:i', strtotime($inv['created_at'])) ?> WIB</span>
                              </td>
                              <td class="py-4 px-5">
                                 <strong class="text-slate-900 block font-bold"><?= htmlspecialchars($inv['package_name']) ?></strong>
                                 <span class="inline-block mt-0.5 px-2 py-0.5 rounded-md text-[10px] font-bold bg-slate-100 text-slate-600">
                                    <?= $inv['billing_cycle'] === 'yearly' ? '📅 Tahunan (Hemat 20%)' : '🗓️ Bulanan' ?>
                                 </span>
                              </td>
                              <td class="py-4 px-5">
                                 <div class="text-sm font-extrabold text-slate-900">
                                    Rp <?= number_format($inv['total_amount'], 0, ',', '.') ?>
                                 </div>
                                 <?php if ($inv['discount'] > 0): ?>
                                    <span class="text-[10px] text-emerald-600 font-semibold block">
                                       Hemat Rp <?= number_format($inv['discount'], 0, ',', '.') ?> <?= $inv['voucher_code'] ? "({$inv['voucher_code']})" : '' ?>
                                    </span>
                                 <?php endif; ?>
                              </td>
                              <td class="py-4 px-5">
                                 <?php if ($inv['status'] === 'active'): ?>
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                       <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                       Lunas / Aktif
                                    </span>
                                 <?php elseif ($inv['status'] === 'reviewing'): ?>
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-blue-50 text-blue-700 border border-blue-200">
                                       <span class="w-1.5 h-1.5 rounded-full bg-blue-500 animate-pulse"></span>
                                       Sedang Diverifikasi
                                    </span>
                                 <?php elseif ($inv['status'] === 'pending_payment'): ?>
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                       <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                                       Menunggu Bayar
                                    </span>
                                 <?php else: ?>
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-slate-100 text-slate-600 border border-slate-200">
                                       <?= htmlspecialchars($inv['status']) ?>
                                    </span>
                                 <?php endif; ?>
                              </td>
                              <td class="py-4 px-5 text-right whitespace-nowrap">
                                 <div class="inline-flex items-center gap-1.5">
                                    <!-- Tombol Pratinjau Invoice -->
                                    <button type="button" 
                                       onclick='openInvoiceModal(<?= json_encode($inv, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
                                       class="px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold rounded-lg transition-colors flex items-center gap-1">
                                       <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                       <span>Lihat Nota</span>
                                    </button>

                                    <!-- Tombol Bayar jika belum lunas -->
                                    <?php if ($inv['status'] === 'pending_payment'): ?>
                                       <a href="dashboard.php?tab=pembayaran&order_id=<?= $inv['id'] ?>"
                                          class="px-3 py-1.5 bg-brand-600 hover:bg-brand-700 text-white font-bold rounded-lg transition-all shadow-xs flex items-center gap-1">
                                          <span>Bayar Sekarang</span>
                                          <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                                       </a>
                                    <?php elseif ($inv['status'] === 'reviewing'): ?>
                                       <a href="dashboard.php?tab=pembayaran"
                                          class="px-3 py-1.5 bg-blue-50 hover:bg-blue-100 text-blue-700 font-bold rounded-lg transition-colors">
                                          Status Transfer
                                       </a>
                                    <?php endif; ?>
                                 </div>
                              </td>
                           </tr>
                        <?php endforeach; ?>
                     </tbody>
                  </table>
               </div>
            <?php endif; ?>

         </div>

         <!-- MODAL PRATINJAU NOTA INVOICE RESMI -->
         <div id="modalInvoice" class="fixed inset-0 z-50 bg-slate-950/70 backdrop-blur-sm hidden items-center justify-center p-4 overflow-y-auto">
            <div class="bg-white rounded-3xl max-w-2xl w-full p-6 sm:p-8 shadow-2xl border border-slate-200 relative my-8">
               <div class="flex items-center justify-between border-b border-slate-100 pb-4 mb-6">
                  <div class="flex items-center gap-2">
                     <span class="w-8 h-8 rounded-lg bg-brand-600 text-white flex items-center justify-center font-black text-sm">O</span>
                     <div>
                        <span class="font-extrabold text-slate-900 text-base">OkeIni<span class="text-brand-600">Aja</span></span>
                        <p class="text-[10px] text-slate-400">Faktur Tagihan Layanan Digital</p>
                     </div>
                  </div>
                  <button onclick="closeInvoiceModal()" class="w-8 h-8 rounded-full bg-slate-100 text-slate-500 hover:text-slate-900 flex items-center justify-center font-bold text-lg">&times;</button>
               </div>

               <!-- Isi Invoice -->
               <div id="invoicePrintArea">
                  <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6 pb-6 border-b border-slate-100 text-xs">
                     <div>
                        <span class="text-[10px] uppercase font-bold text-slate-400 block">DITAGIHKAN KEPADA:</span>
                        <strong class="text-sm font-black text-slate-900 block mt-1"><?= htmlspecialchars($currentUser['business_name'] ?: '-') ?></strong>
                        <p class="text-slate-600">UP: <?= htmlspecialchars($currentUser['name']) ?></p>
                        <p class="text-slate-500">WhatsApp: +<?= htmlspecialchars($currentUser['whatsapp']) ?></p>
                        <p class="text-slate-500">Email: <?= htmlspecialchars($currentUser['email']) ?></p>
                     </div>
                     <div class="sm:text-right">
                        <span class="text-[10px] uppercase font-bold text-slate-400 block">RINCIAN INVOICE:</span>
                        <div class="font-mono font-black text-slate-900 text-sm mt-1" id="invCodeModal">ORD-XXXX</div>
                        <p class="text-slate-600">Tanggal: <span id="invDateModal">-</span></p>
                        <div class="mt-2" id="invStatusBadgeModal">
                           <span class="px-2.5 py-1 rounded-full text-[11px] font-black bg-amber-100 text-amber-800">MENUNGGU BAYAR</span>
                        </div>
                     </div>
                  </div>

                  <!-- Tabel Baris Barang / Layanan -->
                  <div class="border border-slate-200 rounded-xl overflow-hidden mb-6 text-xs">
                     <table class="w-full text-left">
                        <thead class="bg-slate-50 border-b border-slate-200 text-slate-500 font-bold uppercase text-[10px]">
                           <tr>
                              <th class="py-2.5 px-4">Deskripsi Layanan</th>
                              <th class="py-2.5 px-4 text-center">Siklus</th>
                              <th class="py-2.5 px-4 text-right">Subtotal</th>
                           </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                           <tr>
                              <td class="py-3 px-4">
                                 <strong class="font-bold text-slate-900 block" id="invPackageModal">-</strong>
                                 <span class="text-[11px] text-slate-500">Pengelolaan Website, Penulisan Artikel SEO &amp; Konten Media Sosial</span>
                              </td>
                              <td class="py-3 px-4 text-center text-slate-700 capitalize font-medium" id="invCycleModal">-</td>
                              <td class="py-3 px-4 text-right font-bold text-slate-900" id="invSubtotalModal">Rp 0</td>
                           </tr>
                        </tbody>
                        <tfoot class="bg-slate-50/70 border-t border-slate-200">
                           <tr id="invDiscountRow" class="text-emerald-700">
                              <td colspan="2" class="py-2 px-4 font-bold text-right text-[11px]">Potongan Diskon:</td>
                              <td class="py-2 px-4 text-right font-bold text-[11px]" id="invDiscountModal">- Rp 0</td>
                           </tr>
                           <tr class="text-sm font-black text-slate-900 bg-slate-100/70">
                              <td colspan="2" class="py-3 px-4 text-right">TOTAL TAGIHAN:</td>
                              <td class="py-3 px-4 text-right text-brand-600 font-black text-base" id="invTotalModal">Rp 0</td>
                           </tr>
                        </tfoot>
                     </table>
                  </div>

                  <!-- Rekening Pembayaran Resmi -->
                  <div class="p-3.5 bg-slate-50 rounded-xl border border-slate-200 text-xs text-slate-600 mb-6">
                     <p class="font-bold text-slate-800 mb-1">Metode Pembayaran Resmi:</p>
                     <p class="text-[11px]">BCA: <strong>8735-092-121</strong> | Mandiri: <strong>137-00-1982736-1</strong> | BRI: <strong>0341-01-002891-30-5</strong></p>
                     <p class="text-[10px] text-slate-400 mt-1">Atas Nama: PT Oke Ini Aja Digital</p>
                  </div>
               </div>

               <!-- Tombol Aksi Modal -->
               <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
                  <button type="button" onclick="closeInvoiceModal()"
                     class="px-4 py-2.5 rounded-xl border border-slate-200 text-slate-700 font-bold text-xs hover:bg-slate-50 transition-colors">
                     Tutup
                  </button>
                  <button type="button" onclick="window.print()"
                     class="px-5 py-2.5 rounded-xl bg-slate-900 hover:bg-brand-600 text-white font-bold text-xs transition-colors flex items-center gap-1.5 shadow-sm">
                     <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                     <span>Cetak / Simpan PDF</span>
                  </button>
               </div>
            </div>
         </div>
      <?php endif; ?>

<!-- ==================================================================== -->
      <!-- TAB 3: KONFIRMASI PEMBAYARAN (SELF SERVICE PAYMENT CONFIRMATION) -->
      <!-- ==================================================================== -->
      <?php if (!$isAdmin && $activeTab === 'pembayaran'): ?>
         <div class="mb-8">
            <span class="text-xs font-extrabold uppercase tracking-wider text-brand-600 bg-brand-50 px-3 py-1 rounded-full border border-brand-200">
               Konfirmasi &amp; Rekening Pembayaran
            </span>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 mt-2">Konfirmasi Pembayaran Pesanan Paket Anda</h1>
            <p class="text-sm text-slate-500 mt-1 max-w-2xl">
               Setelah mentransfer biaya paket, kirimkan bukti transfer di bawah ini agar tim finance kami dapat langsung memverifikasi dan mengaktifkan layanan.
            </p>
         </div>

         <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 mb-12">
            <!-- Kolom Kiri: Form Konfirmasi Pembayaran -->
            <div class="lg:col-span-2 bg-white p-6 sm:p-8 rounded-3xl border border-slate-200 shadow-sm">
               <h2 class="text-lg font-extrabold text-slate-900 mb-1">Formulir Konfirmasi Transfer</h2>
               <p class="text-xs text-slate-500 mb-6">Pilih pesanan yang Anda bayar dan lengkapi rincian transfer.</p>

               <?php if (empty($myOrders)): ?>
                  <div class="p-6 text-center bg-slate-50 rounded-2xl border border-slate-200 text-xs text-slate-600">
                     <p>Anda belum memiliki tagihan / pesanan paket yang perlu dibayar.</p>
                     <a href="dashboard.php?tab=pesan" class="mt-3 inline-block font-bold text-brand-600 hover:underline">
                        Pesan Paket Layanan Terlebih Dahulu &rarr;
                     </a>
                  </div>
               <?php else: ?>
                  <form method="POST" action="dashboard.php" enctype="multipart/form-data" class="space-y-4">
                     <input type="hidden" name="action" value="confirm_payment">

                     <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Pilih Nomor Pesanan / Tagihan *</label>
                        <select name="order_id" required class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-xs sm:text-sm bg-white focus:outline-none focus:border-brand-600">
                           <?php foreach ($myOrders as $ord): ?>
                              <option value="<?= $ord['id'] ?>" <?= ($selectedOrderId === (int)$ord['id'] || $ord['status'] === 'pending_payment') ? 'selected' : '' ?>>
                                 <?= htmlspecialchars($ord['order_code']) ?> — <?= htmlspecialchars($ord['package_name']) ?> (Rp <?= number_format($ord['total_amount'], 0, ',', '.') ?>) [Status: <?= strtoupper($ord['status']) ?>]
                              </option>
                           <?php endforeach; ?>
                        </select>
                     </div>

                     <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                           <label class="block text-xs font-bold text-slate-700 mb-1">Bank Tujuan Pembayaran *</label>
                           <select name="bank_destination" required class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-xs sm:text-sm bg-white focus:outline-none focus:border-brand-600">
                              <option value="BCA">BCA (Bank Central Asia) - 123-456-7890</option>
                              <option value="Mandiri">Bank Mandiri - 987-654-3210</option>
                              <option value="BRI">Bank BRI - 555-666-7778</option>
                              <option value="QRIS">QRIS All Payment</option>
                           </select>
                        </div>
                        <div>
                           <label class="block text-xs font-bold text-slate-700 mb-1">Bank Asal Pengirim</label>
                           <input type="text" name="sender_bank" placeholder="Contoh: BCA / Mandiri / Jago"
                              class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-xs sm:text-sm focus:outline-none focus:border-brand-600">
                        </div>
                     </div>

                     <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                           <label class="block text-xs font-bold text-slate-700 mb-1">Nama Pemilik Rekening Pengirim *</label>
                           <input type="text" name="sender_name" required value="<?= htmlspecialchars($currentUser['name']) ?>" placeholder="Nama sesuai buku tabungan"
                              class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-xs sm:text-sm focus:outline-none focus:border-brand-600">
                        </div>
                        <div>
                           <label class="block text-xs font-bold text-slate-700 mb-1">Nominal yang Ditransfer (Rp) *</label>
                           <input type="number" name="transfer_amount" required placeholder="Contoh: 150000"
                              class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-xs sm:text-sm focus:outline-none focus:border-brand-600">
                        </div>
                     </div>

                     <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                           <label class="block text-xs font-bold text-slate-700 mb-1">Tanggal Transfer *</label>
                           <input type="date" name="transfer_date" required value="<?= date('Y-m-d') ?>"
                              class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-xs sm:text-sm focus:outline-none focus:border-brand-600">
                        </div>
                        <div>
                           <label class="block text-xs font-bold text-slate-700 mb-1">Unggah Bukti Transfer (Foto/Struk/PDF)</label>
                           <input type="file" name="proof_file" accept="image/*,.pdf"
                              class="w-full px-3 py-1.5 rounded-xl border border-slate-200 text-xs bg-white file:mr-3 file:py-1 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-slate-100 file:text-slate-700 hover:file:bg-slate-200">
                        </div>
                     </div>

                     <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Catatan Tambahan</label>
                        <input type="text" name="notes" placeholder="Contoh: Transfer dari rekening an Budi untuk perpanjangan bulan kedua"
                           class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-xs sm:text-sm focus:outline-none focus:border-brand-600">
                     </div>

                     <button type="submit"
                        class="w-full py-3.5 bg-emerald-600 hover:bg-emerald-700 text-white font-extrabold rounded-xl transition-all text-xs sm:text-sm shadow-md flex items-center justify-center gap-2 active:scale-95">
                        <svg class="w-4 h-4 fill-none stroke-current" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        <span>Kirim Konfirmasi Pembayaran</span>
                     </button>
                  </form>
               <?php endif; ?>
            </div>

            <!-- Kolom Kanan: Rekening Resmi & Panduan -->
            <div class="space-y-6">
               <div class="bg-gradient-to-br from-slate-900 to-slate-950 text-white p-6 rounded-3xl shadow-sm border border-slate-800">
                  <h3 class="font-extrabold text-sm mb-4 flex items-center gap-2">
                     <span>🏦</span> Rekening Resmi Pembayaran OkeIniAja
                  </h3>

                  <div class="space-y-3.5 text-xs">
                     <div class="p-3 bg-white/5 rounded-2xl border border-white/10">
                        <span class="text-slate-400 block text-[11px]">Bank Central Asia (BCA)</span>
                        <div class="flex items-center justify-between mt-1">
                           <strong class="font-mono text-base text-white">123-456-7890</strong>
                           <span class="text-[10px] text-brand-400 font-bold">Salin</span>
                        </div>
                        <span class="text-[10px] text-slate-400">a/n PT Oke Ini Aja Indonesia</span>
                     </div>

                     <div class="p-3 bg-white/5 rounded-2xl border border-white/10">
                        <span class="text-slate-400 block text-[11px]">Bank Mandiri</span>
                        <div class="flex items-center justify-between mt-1">
                           <strong class="font-mono text-base text-white">987-654-3210</strong>
                           <span class="text-[10px] text-brand-400 font-bold">Salin</span>
                        </div>
                        <span class="text-[10px] text-slate-400">a/n PT Oke Ini Aja Indonesia</span>
                     </div>

                     <div class="p-3 bg-white/5 rounded-2xl border border-white/10">
                        <span class="text-slate-400 block text-[11px]">Bank Rakyat Indonesia (BRI)</span>
                        <div class="flex items-center justify-between mt-1">
                           <strong class="font-mono text-base text-white">555-666-7778</strong>
                           <span class="text-[10px] text-brand-400 font-bold">Salin</span>
                        </div>
                        <span class="text-[10px] text-slate-400">a/n PT Oke Ini Aja Indonesia</span>
                     </div>
                  </div>

                  <p class="text-[11px] text-slate-400 mt-4 leading-relaxed">
                     ⚠️ Pastikan nomor rekening dan nominal transfer sesuai. Konfirmasi diproses otomatis dalam 15–60 menit pada jam kerja.
                  </p>
               </div>
            </div>
         </div>

         <!-- Riwayat Pembayaran Pengguna Ini -->
         <div class="bg-white p-6 sm:p-8 rounded-3xl border border-slate-200 shadow-sm">
            <h2 class="text-base font-extrabold text-slate-900 mb-1">Riwayat Konfirmasi Pembayaran Anda</h2>
            <p class="text-xs text-slate-500 mb-6">Status verifikasi pembayaran pesanan yang pernah Anda kirimkan.</p>

            <?php if (empty($myPayments)): ?>
               <p class="text-xs text-slate-400 italic">Belum ada riwayat konfirmasi pembayaran.</p>
            <?php else: ?>
               <div class="overflow-x-auto">
                  <table class="w-full text-left text-xs sm:text-sm">
                     <thead class="bg-slate-50 text-slate-600 border-b border-slate-200">
                        <tr>
                           <th class="p-3">Order / Paket</th>
                           <th class="p-3">Bank Tujuan</th>
                           <th class="p-3">Pengirim</th>
                           <th class="p-3">Nominal Transfer</th>
                           <th class="p-3">Tanggal</th>
                           <th class="p-3">Status Verifikasi</th>
                        </tr>
                     </thead>
                     <tbody class="divide-y divide-slate-100">
                        <?php foreach ($myPayments as $pay): ?>
                           <tr>
                              <td class="p-3 font-semibold text-slate-900">
                                 <?= htmlspecialchars($pay['order_code']) ?>
                                 <span class="block text-[11px] text-slate-400 font-normal"><?= htmlspecialchars($pay['package_name']) ?></span>
                              </td>
                              <td class="p-3 text-slate-800 font-medium"><?= htmlspecialchars($pay['bank_destination']) ?></td>
                              <td class="p-3 text-slate-700">
                                 <?= htmlspecialchars($pay['sender_name']) ?>
                                 <span class="block text-[11px] text-slate-400"><?= htmlspecialchars($pay['sender_bank'] ?: '') ?></span>
                              </td>
                              <td class="p-3 font-bold text-slate-900">
                                 Rp <?= number_format($pay['transfer_amount'], 0, ',', '.') ?>
                                 <?php if (!empty($pay['proof_file'])): ?>
                                    <a href="<?= htmlspecialchars(proof_url((string)$pay['proof_file'])) ?>" target="_blank"
                                       class="block mt-1 text-[11px] font-semibold text-brand-600 hover:underline">
                                       📎 Bukti Transfer
                                    </a>
                                 <?php endif; ?>
                              </td>
                              <td class="p-3 text-slate-500"><?= date('d M Y', strtotime($pay['transfer_date'])) ?></td>
                              <td class="p-3">
                                 <?php if ($pay['status'] === 'pending'): ?>
                                    <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-amber-100 text-amber-800">Menunggu Verifikasi</span>
                                 <?php elseif ($pay['status'] === 'verified'): ?>
                                    <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-emerald-100 text-emerald-800">Terverifikasi (Lunas)</span>
                                    <?php if (!empty($pay['refund_amount']) && (int)$pay['refund_amount'] > 0): ?>
                                       <div class="mt-1 p-1.5 rounded-md bg-purple-50 border border-purple-200 text-purple-800 text-[10px]">
                                          <strong class="block">↩ Selisih Lebih Bayar Diretur: Rp <?= number_format($pay['refund_amount'], 0, ',', '.') ?></strong>
                                          <span class="text-purple-600 block">Tujuan: <?= htmlspecialchars($pay['refund_bank'] ?: 'Rekening') ?> (<?= htmlspecialchars($pay['refund_account'] ?: '-') ?>)</span>
                                       </div>
                                    <?php endif; ?>
                                 <?php elseif ($pay['status'] === 'returned'): ?>
                                    <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-purple-100 text-purple-800">Dana Diretur / Refund</span>
                                    <div class="mt-1 p-1.5 rounded-md bg-purple-50 border border-purple-200 text-purple-800 text-[10px]">
                                       <strong class="block">Nominal Diretur: Rp <?= number_format($pay['refund_amount'] ?: $pay['transfer_amount'], 0, ',', '.') ?></strong>
                                       <span class="text-purple-600 block">Tujuan: <?= htmlspecialchars($pay['refund_bank'] ?: 'Rekening') ?> (<?= htmlspecialchars($pay['refund_account'] ?: '-') ?>)</span>
                                    </div>
                                 <?php else: ?>
                                    <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-rose-100 text-rose-800">Ditolak</span>
                                    <?php if (!empty($pay['admin_notes'])): ?>
                                       <span class="block text-[10px] text-rose-600 italic mt-1 font-medium">
                                          Alasan: "<?= htmlspecialchars($pay['admin_notes']) ?>"
                                       </span>
                                    <?php endif; ?>
                                 <?php endif; ?>
                              </td>
                           </tr>
                        <?php endforeach; ?>
                     </tbody>
                  </table>
               </div>
            <?php endif; ?>
         </div>
      <?php endif; ?>

      <!-- ==================================================================== -->
      <!-- TAB 4: LAPORAN KEGIATAN & KONTEN (SCOPED TO THIS USER) -->
      <!-- ==================================================================== -->
      <?php if (!$isAdmin && $activeTab === 'laporan'): ?>
         <div class="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
               <span class="text-xs font-extrabold uppercase tracking-wider text-brand-600 bg-brand-50 px-3 py-1 rounded-full border border-brand-200">
                  Laporan Kegiatan Pengelolaan
               </span>
               <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 mt-2">Laporan &amp; Konten Khusus <?= htmlspecialchars($currentUser['business_name'] ?: 'Usaha Anda') ?></h1>
               <p class="text-sm text-slate-500 mt-1 max-w-2xl">
                  Seluruh draf artikel SEO, jadwal konten media sosial, dan riwayat leads yang tercatat untuk bisnis Anda.
               </p>
            </div>
            <button onclick="document.getElementById('modalRequest').classList.remove('hidden')"
               class="px-5 py-2.5 rounded-xl bg-slate-900 hover:bg-brand-600 text-white font-bold text-xs transition-all w-fit shadow-sm">
               + Ajukan Permintaan Materi / Revisi
            </button>
         </div>

         <!-- Daftar Laporan Kegiatan -->
         <div class="bg-white rounded-3xl border border-slate-200 shadow-sm p-6 sm:p-8 mb-8">
            <h2 class="text-base font-extrabold text-slate-900 mb-4">Aktivitas &amp; Publikasi Konten Terkini</h2>

            <?php if (empty($myActivities)): ?>
               <p class="text-xs text-slate-400 italic">Belum ada riwayat kegiatan tercatat untuk akun ini.</p>
            <?php else: ?>
               <div class="space-y-4">
                  <?php foreach ($myActivities as $act): ?>
                     <div class="p-4 rounded-2xl bg-slate-50 border border-slate-200 flex flex-col sm:flex-row sm:items-start justify-between gap-3">
                        <div class="flex items-start gap-3">
                           <span class="w-10 h-10 rounded-xl bg-white border border-slate-200 flex items-center justify-center text-base shrink-0 shadow-2xs">
                              <?php
                              switch ($act['category']) {
                                 case 'article': echo '📝'; break;
                                 case 'social_media': echo '📱'; break;
                                 case 'lead': echo '💬'; break;
                                 case 'website': echo '⚡'; break;
                                 default: echo '📌'; break;
                              }
                              ?>
                           </span>
                           <div>
                              <h3 class="font-bold text-slate-900 text-sm"><?= htmlspecialchars($act['title']) ?></h3>
                              <p class="text-xs text-slate-600 mt-1 leading-relaxed"><?= htmlspecialchars($act['description'] ?: '') ?></p>
                              <?php if (!empty($act['meta_info'])): ?>
                                 <span class="inline-block mt-2 px-2.5 py-0.5 rounded-md bg-white border border-slate-200 text-[11px] text-slate-500 font-mono">
                                    <?= htmlspecialchars($act['meta_info']) ?>
                                 </span>
                              <?php endif; ?>
                              <span class="text-[10px] text-slate-400 block mt-1.5"><?= date('d M Y, H:i', strtotime($act['created_at'])) ?> WIB</span>
                           </div>
                        </div>

                        <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold uppercase w-fit shrink-0 <?= $act['status'] === 'completed' || $act['status'] === 'published' ? 'bg-emerald-100 text-emerald-800' : ($act['status'] === 'scheduled' ? 'bg-blue-100 text-blue-800' : 'bg-brand-100 text-brand-800') ?>">
                           <?= htmlspecialchars($act['status']) ?>
                        </span>
                     </div>
                  <?php endforeach; ?>
               </div>
            <?php endif; ?>
         </div>

         <!-- Modal Ajukan Permintaan Materi -->
         <div id="modalRequest" class="fixed inset-0 z-50 bg-slate-950/70 backdrop-blur-sm hidden items-center justify-center p-4">
            <div class="bg-white rounded-3xl max-w-lg w-full p-6 sm:p-8 shadow-2xl border border-slate-200 relative">
               <button type="button" onclick="document.getElementById('modalRequest').classList.add('hidden')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-700 text-lg">✕</button>

               <div class="mb-5">
                  <span class="text-[11px] font-bold text-brand-600 uppercase tracking-wider">Permintaan Materi / Revisi</span>
                  <h3 class="text-xl font-extrabold text-slate-900 mt-1">Kirim Catatan ke Tim Pengelola</h3>
                  <p class="text-xs text-slate-500 mt-1">Kirimkan ide konten, instruksi artikel khusus, atau permintaan revisi desain.</p>
               </div>

               <form method="POST" action="dashboard.php" class="space-y-4">
                  <input type="hidden" name="action" value="request_content">

                  <div>
                     <label class="block text-xs font-bold text-slate-700 mb-1">Kategori Layanan *</label>
                     <select name="category" required class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs bg-white focus:outline-none focus:border-brand-600">
                        <option value="article">Artikel SEO Blog Website</option>
                        <option value="social_media">Desain &amp; Postingan Media Sosial</option>
                        <option value="website">Upload / Optimasi Gambar Produk</option>
                     </select>
                  </div>

                  <div>
                     <label class="block text-xs font-bold text-slate-700 mb-1">Judul / Topik Singkat *</label>
                     <input type="text" name="title" required placeholder="Contoh: Buatkan artikel promo diskon akhir tahun"
                        class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs focus:outline-none focus:border-brand-600">
                  </div>

                  <div>
                     <label class="block text-xs font-bold text-slate-700 mb-1">Detail Instruksi &amp; Penjelasan</label>
                     <textarea name="description" rows="3" placeholder="Sertakan poin-poin yang wajib dimasukkan, harga produk, atau link referensi..."
                        class="w-full px-3.5 py-2 rounded-xl border border-slate-200 text-xs focus:outline-none focus:border-brand-600"></textarea>
                  </div>

                  <button type="submit"
                     class="w-full py-3 bg-slate-900 hover:bg-brand-600 text-white font-extrabold rounded-xl transition-all text-xs shadow-md">
                     Kirimkan ke Tim &rarr;
                  </button>
               </form>
            </div>
         </div>
      <?php endif; ?>

      <!-- ==================================================================== -->
      <!-- TAB 5: PROFIL PENGGUNA (USER PROFILE & SECURITY) -->
      <!-- ==================================================================== -->
      <?php if ($activeTab === 'profil'): ?>
         <div class="mb-8">
            <span class="text-xs font-extrabold uppercase tracking-wider text-brand-600 bg-brand-50 px-3 py-1 rounded-full border border-brand-200">
               Pengaturan Akun Mandiri
            </span>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 mt-2">Profil Pengguna &amp; Keamanan Akun</h1>
            <p class="text-sm text-slate-500 mt-1 max-w-2xl">
               Kelola informasi identitas usaha dan perbarui kata sandi akun portal Anda secara mandiri.
            </p>
         </div>

         <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-12">
            <!-- Form Profil Usaha -->
            <div class="bg-white p-6 sm:p-8 rounded-3xl border border-slate-200 shadow-sm">
               <h2 class="text-base font-extrabold text-slate-900 mb-1">Informasi Usaha &amp; Kontak</h2>
               <p class="text-xs text-slate-500 mb-6">Data ini digunakan untuk penulisan artikel dan materi promosi medsos.</p>

               <form method="POST" action="dashboard.php" class="space-y-4">
                  <input type="hidden" name="action" value="update_profile">

                  <div>
                     <label class="block text-xs font-bold text-slate-700 mb-1">Nama Lengkap / PIC Usaha *</label>
                     <input type="text" name="name" required value="<?= htmlspecialchars($currentUser['name']) ?>"
                        class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-xs sm:text-sm focus:outline-none focus:border-brand-600">
                  </div>

                  <div>
                     <label class="block text-xs font-bold text-slate-700 mb-1">Nama Usaha / Brand *</label>
                     <input type="text" name="business_name" required value="<?= htmlspecialchars($currentUser['business_name'] ?: '') ?>"
                        class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-xs sm:text-sm focus:outline-none focus:border-brand-600">
                  </div>

                  <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                     <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Nomor WhatsApp Aktif *</label>
                        <input type="tel" name="whatsapp" required value="<?= htmlspecialchars($currentUser['whatsapp']) ?>"
                           class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-xs sm:text-sm focus:outline-none focus:border-brand-600">
                     </div>
                     <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Alamat Email *</label>
                        <input type="email" name="email" required value="<?= htmlspecialchars($currentUser['email']) ?>"
                           class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-xs sm:text-sm focus:outline-none focus:border-brand-600">
                     </div>
                  </div>

                  <div>
                     <label class="block text-xs font-bold text-slate-700 mb-1">Link Website Usaha Saat Ini</label>
                     <input type="text" name="website_url" value="<?= htmlspecialchars($currentUser['website_url'] ?: '') ?>" placeholder="https://tokosaya.com"
                        class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-xs sm:text-sm focus:outline-none focus:border-brand-600">
                  </div>

                  <div>
                     <label class="block text-xs font-bold text-slate-700 mb-1">Akun Instagram Usaha</label>
                     <input type="text" name="instagram_handle" value="<?= htmlspecialchars($currentUser['instagram_handle'] ?: '') ?>" placeholder="@tokosaya"
                        class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-xs sm:text-sm focus:outline-none focus:border-brand-600">
                  </div>

                  <button type="submit"
                     class="w-full py-3 bg-slate-900 hover:bg-brand-600 text-white font-extrabold rounded-xl transition-all text-xs sm:text-sm shadow-sm active:scale-95">
                     Simpan Perubahan Profil
                  </button>
               </form>
            </div>

            <!-- Form Ganti Password -->
            <div class="bg-white p-6 sm:p-8 rounded-3xl border border-slate-200 shadow-sm">
               <h2 class="text-base font-extrabold text-slate-900 mb-1">Keamanan Akun &amp; Password</h2>
               <p class="text-xs text-slate-500 mb-6">Ganti password secara berkala untuk menjaga keamanan akses portal Anda.</p>

               <form method="POST" action="dashboard.php" class="space-y-4">
                  <input type="hidden" name="action" value="change_password">

                  <div>
                     <label class="block text-xs font-bold text-slate-700 mb-1">Password Saat Ini *</label>
                     <input type="password" name="old_password" required placeholder="••••••••"
                        class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-xs sm:text-sm focus:outline-none focus:border-brand-600">
                  </div>

                  <div>
                     <div class="flex justify-between items-center mb-1">
                        <label class="block text-xs font-bold text-slate-700">Password Baru *</label>
                        <span class="text-[10px] text-slate-400">Min. 6 Karakter</span>
                     </div>
                     <input type="password" name="new_password" required placeholder="Minimal 6 karakter"
                        class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-xs sm:text-sm focus:outline-none focus:border-brand-600">
                  </div>

                  <div>
                     <label class="block text-xs font-bold text-slate-700 mb-1">Konfirmasi Password Baru *</label>
                     <input type="password" name="confirm_password" required placeholder="Ulangi password baru"
                        class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-xs sm:text-sm focus:outline-none focus:border-brand-600">
                  </div>

                  <button type="submit"
                     class="w-full py-3 bg-slate-100 hover:bg-slate-200 text-slate-900 font-extrabold rounded-xl transition-all text-xs sm:text-sm shadow-sm active:scale-95">
                     Perbarui Password Akun
                  </button>
               </form>
            </div>
         </div>
      <?php endif; ?>

   </main>

   <!-- FOOTER -->
      <footer class="bg-white border-t border-slate-200 py-4 px-6 text-center text-xs text-slate-500">
         <div class="max-w-7xl mx-auto flex flex-col sm:flex-row items-center justify-between gap-2">
            <span>&copy; <?= $current_year ?> <?= $site_name ?> — Layanan Pengelolaan Website &amp; Media Sosial Bisnis Indonesia.</span>
            <div class="flex items-center gap-4 text-slate-400">
               <a href="<?= $site_url ?>" target="_blank" class="hover:text-brand-600 transition-colors">Website Resmi</a>
               <span>•</span>
               <a href="https://wa.me/<?= $contact_wa ?>" target="_blank" class="hover:text-emerald-600 transition-colors">WhatsApp Support</a>
            </div>
         </div>
      </footer>

   </div>

   <!-- SCRIPT CALCULATION & INTERACTIVITY -->
   <script>
      function openInvoiceModal(inv) {
         document.getElementById('invCodeModal').textContent = inv.order_code;
         document.getElementById('invDateModal').textContent = inv.created_at;
         document.getElementById('invPackageModal').textContent = inv.package_name;
         document.getElementById('invCycleModal').textContent = inv.billing_cycle === 'yearly' ? 'Tahunan (12 Bulan)' : 'Bulanan';
         document.getElementById('invSubtotalModal').textContent = 'Rp ' + Number(inv.amount).toLocaleString('id-ID');
         document.getElementById('invDiscountModal').textContent = '- Rp ' + Number(inv.discount).toLocaleString('id-ID');
         document.getElementById('invTotalModal').textContent = 'Rp ' + Number(inv.total_amount).toLocaleString('id-ID');

         const badgeContainer = document.getElementById('invStatusBadgeModal');
         if (inv.status === 'active') {
            badgeContainer.innerHTML = '<span class="px-2.5 py-1 rounded-full text-[11px] font-black bg-emerald-100 text-emerald-800">LUNAS / TERBAYAR</span>';
         } else if (inv.status === 'reviewing') {
            badgeContainer.innerHTML = '<span class="px-2.5 py-1 rounded-full text-[11px] font-black bg-blue-100 text-blue-800">SEDANG DIVERIFIKASI</span>';
         } else {
            badgeContainer.innerHTML = '<span class="px-2.5 py-1 rounded-full text-[11px] font-black bg-amber-100 text-amber-800">MENUNGGU PEMBAYARAN</span>';
         }

         const modal = document.getElementById('modalInvoice');
         modal.classList.remove('hidden');
         modal.classList.add('flex');
      }

      function closeInvoiceModal() {
         const modal = document.getElementById('modalInvoice');
         modal.classList.add('hidden');
         modal.classList.remove('flex');
      }

      // MODAL TOLAK & RETUR PEMBAYARAN ADMINISTRATOR
      let currentOrderTotal = 0;
      let currentTransferTotal = 0;
      let currentDiff = 0;

      function openRejectModal(payId, clientName, orderCode, amount) {
         document.getElementById('rejectPaymentId').value = payId;
         document.getElementById('rejectSubtitle').textContent = 'Pembayaran #PAY-' + payId;
         document.getElementById('rejectClientName').textContent = clientName;
         document.getElementById('rejectOrderCode').textContent = orderCode;
         document.getElementById('rejectAmount').textContent = amount;
         const m = document.getElementById('modalRejectPayment');
         m.classList.remove('hidden');
         m.classList.add('flex');
      }

      function closeRejectModal() {
         const m = document.getElementById('modalRejectPayment');
         m.classList.add('hidden');
         m.classList.remove('flex');
      }

      function openReturnModal(payId, clientName, orderCode, orderTotal, transferTotal, senderBank, senderName) {
         currentOrderTotal = parseInt(orderTotal) || 0;
         currentTransferTotal = parseInt(transferTotal) || 0;
         currentDiff = Math.max(0, currentTransferTotal - currentOrderTotal);

         document.getElementById('returnPaymentId').value = payId;
         document.getElementById('returnSubtitle').textContent = 'Pembayaran #PAY-' + payId + ' (' + clientName + ')';
         document.getElementById('returnOrderAmount').textContent = 'Rp ' + currentOrderTotal.toLocaleString('id-ID');
         document.getElementById('returnTransferAmount').textContent = 'Rp ' + currentTransferTotal.toLocaleString('id-ID');
         document.getElementById('returnDiffAmount').textContent = (currentDiff > 0 ? '+Rp ' : 'Rp ') + currentDiff.toLocaleString('id-ID');

         // Set default refund amount to the difference if overpaid, otherwise full transfer
         const defaultRefund = currentDiff > 0 ? currentDiff : currentTransferTotal;
         document.getElementById('returnRefundInput').value = defaultRefund;

         document.getElementById('returnBankInput').value = senderBank || '';
         document.getElementById('returnAccountInput').value = senderName ? ('a/n ' + senderName) : '';

         if (currentDiff > 0) {
            document.getElementById('typeVerifiedRefund').checked = true;
            document.getElementById('returnNotesInput').value = 'Kelebihan pembayaran sebesar Rp ' + currentDiff.toLocaleString('id-ID') + ' telah dikembalikan ke rekening klien.';
         } else {
            document.getElementById('typeFullRefund').checked = true;
            document.getElementById('returnNotesInput').value = 'Pengembalian dana pembayaran telah diproses.';
         }

         const m = document.getElementById('modalReturnPayment');
         m.classList.remove('hidden');
         m.classList.add('flex');
      }

      function closeReturnModal() {
         const m = document.getElementById('modalReturnPayment');
         m.classList.add('hidden');
         m.classList.remove('flex');
      }

      document.addEventListener('DOMContentLoaded', function() {
         const rVerified = document.getElementById('typeVerifiedRefund');
         const rFull = document.getElementById('typeFullRefund');
         if (rVerified && rFull) {
            rVerified.addEventListener('change', function() {
               if (this.checked) {
                  document.getElementById('returnRefundInput').value = currentDiff > 0 ? currentDiff : currentTransferTotal;
               }
            });
            rFull.addEventListener('change', function() {
               if (this.checked) {
                  document.getElementById('returnRefundInput').value = currentTransferTotal;
               }
            });
         }
      });

      // MODALS AKTIVITAS PEKERJAAN & CEKLIS ARTIKEL
      // Tampilkan hanya item (foto / medsos / backup) yang termasuk paket klien.
      function applyJobFeatures(modal, enabled) {
         if (!modal) return;
         modal.querySelectorAll('[data-feature]').forEach(function (el) {
            const on = !enabled || enabled[el.dataset.feature] !== false;
            el.classList.toggle('hidden', !on);
            el.querySelectorAll('input, select, textarea').forEach(function (inp) { inp.disabled = !on; });
         });
      }

      function onAddJobClientChange(sel) {
         const opt = sel.options[sel.selectedIndex];
         let enabled = null;
         let pkg = 'Pilih klien untuk melihat paket';
         if (opt && opt.value) {
            try { enabled = JSON.parse(opt.dataset.enabled || 'null'); } catch (e) { enabled = null; }
            pkg = opt.dataset.package ? ('Paket: ' + opt.dataset.package) : 'Klien belum memiliki paket aktif (semua item ditampilkan)';
         }
         document.getElementById('addJobPackageInfo').textContent = pkg;
         applyJobFeatures(document.getElementById('modalAddJobActivity'), enabled);
      }

      function openAddJobModal() {
         const m = document.getElementById('modalAddJobActivity');
         if (m) {
            m.classList.remove('hidden');
            m.classList.add('flex');
            const sel = m.querySelector('select[name="target_user_id"]');
            if (sel) onAddJobClientChange(sel);
         }
      }

      function closeAddJobModal() {
         const m = document.getElementById('modalAddJobActivity');
         if (m) {
            m.classList.add('hidden');
            m.classList.remove('flex');
         }
      }

      function openEditJobModal(job) {
         document.getElementById('editJobId').value = job.id;
         document.getElementById('editJobTitle').value = job.title || '';
         document.getElementById('editJobUploadDate').value = job.upload_date || '';
         document.getElementById('editJobArticleUrl').value = job.article_url || '';
         document.getElementById('editJobArticleCount').value = job.article_count != null ? job.article_count : 1;
         document.getElementById('editJobPhotoCount').value = job.photo_count || 0;
         document.getElementById('editJobSocialCount').value = job.social_post_count || 0;
         document.getElementById('editCheckImageOpt').checked = (parseInt(job.check_image_opt) === 1);
         document.getElementById('editCheckSocialPost').checked = (parseInt(job.check_social_post) === 1);
         document.getElementById('editCheckSocialLink').checked = (parseInt(job.check_social_link) === 1);
         document.getElementById('editCheckBackup').checked = (parseInt(job.check_backup) === 1);
         document.getElementById('editJobStatus').value = job.status || 'in_progress';
         document.getElementById('editJobNotes').value = job.notes || '';
         document.getElementById('editJobPackageInfo').textContent = job.package_label || '';

         const m = document.getElementById('modalEditJobActivity');
         if (m) {
            applyJobFeatures(m, job.enabled || null);
            m.classList.remove('hidden');
            m.classList.add('flex');
         }
      }

      function closeEditJobModal() {
         const m = document.getElementById('modalEditJobActivity');
         if (m) {
            m.classList.add('hidden');
            m.classList.remove('flex');
         }
      }

      // DROPDOWN NOTIFIKASI (LONCENG)
      function toggleNotifPanel(e) {
         if (e) e.stopPropagation();
         const p = document.getElementById('notifPanel');
         const b = document.getElementById('notifBell');
         if (!p) return;
         const open = p.classList.toggle('hidden') === false;
         if (b) b.setAttribute('aria-expanded', open ? 'true' : 'false');
      }

      document.addEventListener('click', function (e) {
         const p = document.getElementById('notifPanel');
         if (p && !p.classList.contains('hidden') && !p.contains(e.target)) {
            p.classList.add('hidden');
            const b = document.getElementById('notifBell');
            if (b) b.setAttribute('aria-expanded', 'false');
         }
      });

      function toggleSidebar() {
         const sidebar = document.getElementById('sidebar');
         const backdrop = document.getElementById('sidebarBackdrop');
         if (sidebar.classList.contains('-translate-x-full')) {
            sidebar.classList.remove('-translate-x-full');
            backdrop.classList.remove('hidden');
         } else {
            sidebar.classList.add('-translate-x-full');
            backdrop.classList.add('hidden');
         }
      }

      let currentBasePrice = 0;

      function openOrderModal(packageName, price) {
         currentBasePrice = price;
         document.getElementById('modalPlanTitle').textContent = packageName;
         document.getElementById('inputPackageName').value = packageName;
         document.getElementById('orderModal').classList.remove('hidden');
         document.getElementById('orderModal').classList.add('flex');
         calculateOrderTotal();
      }

      function closeOrderModal() {
         document.getElementById('orderModal').classList.add('hidden');
         document.getElementById('orderModal').classList.remove('flex');
      }

      function calculateOrderTotal() {
         const cycle = document.querySelector('input[name="billing_cycle"]:checked')?.value || 'monthly';
         const voucher = (document.getElementById('voucherCode')?.value || '').trim().toUpperCase();

         let subtotal = currentBasePrice;
         let discount = 0;

         if (cycle === 'yearly') {
            subtotal = currentBasePrice * 12;
            discount = currentBasePrice * 2; // 2 bulan gratis
         }

         if (voucher === 'HEMAT40') {
            const voucherDiscount = Math.round((subtotal - discount) * 0.40);
            discount += voucherDiscount;
         }

         const total = Math.max(0, subtotal - discount);

         document.getElementById('txtSubtotal').textContent = 'Rp ' + subtotal.toLocaleString('id-ID');
         document.getElementById('txtDiscount').textContent = '- Rp ' + discount.toLocaleString('id-ID');
         document.getElementById('txtTotal').textContent = 'Rp ' + total.toLocaleString('id-ID');
      }
   </script>
<?php include __DIR__ . '/toast.php'; ?>
</body>

</html>
