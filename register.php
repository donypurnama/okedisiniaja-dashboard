<?php

declare(strict_types=1);

/**
 * OkeIniAja Admin - Halaman Pendaftaran Akun Pengguna Baru (Portal Klien)
 */
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notifications.php';

$site_name = "OkeIniAja.com";
$site_url = "https://okeiniaja.com/";
$contact_wa = "6281291311218";
$current_year = date('Y');

$errors = [];
$name = '';
$business_name = '';
$whatsapp = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $business_name = trim($_POST['business_name'] ?? '');
    $whatsapp = trim($_POST['whatsapp'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';
    $agree = isset($_POST['agree']);

    // Validasi input
    if ($name === '') {
        $errors[] = 'Nama lengkap / penanggung jawab usaha wajib diisi.';
    }

    if ($business_name === '') {
        $errors[] = 'Nama usaha / brand bisnis wajib diisi.';
    }

    // Normalisasi WhatsApp (hanya angka)
    $cleanWa = preg_replace('/[^0-9]/', '', $whatsapp);
    if ($cleanWa === '') {
        $errors[] = 'Nomor WhatsApp aktif wajib diisi.';
    } elseif (strlen($cleanWa) < 9 || strlen($cleanWa) > 16) {
        $errors[] = 'Format nomor WhatsApp tidak valid (minimal 9 digit).';
    } else {
        if (str_starts_with($cleanWa, '0')) {
            $cleanWa = '62' . substr($cleanWa, 1);
        }
    }

    if ($email === '') {
        $errors[] = 'Alamat email aktif wajib diisi.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Format alamat email tidak valid.';
    }

    if ($password === '') {
        $errors[] = 'Password akun wajib diisi.';
    } elseif (strlen($password) < 6) {
        $errors[] = 'Password minimal harus 6 karakter.';
    }

    if ($password !== $password_confirm) {
        $errors[] = 'Konfirmasi password tidak cocok dengan password yang dimasukkan.';
    }

    if (!$agree) {
        $errors[] = 'Anda harus menyetujui syarat & ketentuan layanan untuk mendaftar.';
    }

    // Pengecekan duplikasi ke database
    if (empty($errors)) {
        try {
            $pdo = db();

            // Cek apakah email sudah terdaftar
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([strtolower($email)]);
            if ($stmt->fetch()) {
                $errors[] = 'Alamat email ini sudah terdaftar. Silakan masuk menggunakan akun Anda atau gunakan email lain.';
            }

            // Cek apakah nomor WhatsApp sudah terdaftar
            if (empty($errors)) {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE whatsapp = ? OR whatsapp = ? LIMIT 1");
                $stmt->execute([$cleanWa, $whatsapp]);
                if ($stmt->fetch()) {
                    $errors[] = 'Nomor WhatsApp ini sudah terdaftar. Silakan gunakan nomor lain atau masuk ke akun Anda.';
                }
            }

            // Simpan akun pengguna baru
            if (empty($errors)) {
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $insertStmt = $pdo->prepare("
                    INSERT INTO users (name, email, whatsapp, password_hash, business_name, role, status, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, 'client', 'active', datetime('now'), datetime('now'))
                ");
                $insertStmt->execute([
                    $name,
                    strtolower($email),
                    $cleanWa,
                    $password_hash,
                    $business_name
                ]);

                // Notifikasi ke seluruh admin: klien baru mendaftar
                $newClientId = (int)$pdo->lastInsertId();
                notify_admins($pdo, 'client', 'Klien baru mendaftar',
                    $business_name . ' (PIC: ' . $name . ') baru saja membuat akun.',
                    'dashboard.php?tab=admin_client_detail&client_id=' . $newClientId, $newClientId);

                // Redirect ke halaman login dengan query registered=1
                header("Location: index.php?registered=1&u=" . urlencode($email));
                exit;
            }
        } catch (Throwable $e) {
            $errors[] = 'Terjadi kesalahan sistem saat menyimpan data: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>Daftar Akun Baru - <?= $site_name ?></title>
   <meta name="description"
      content="Daftar akun pengguna baru di Portal Klien OkeIniAja.com untuk memantau pengelolaan website dan media sosial bisnis Anda secara terpadu.">
   <meta name="robots" content="noindex, nofollow">

   <link rel="preconnect" href="https://fonts.googleapis.com">
   <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
   <link
      href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap"
      rel="stylesheet">

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
      .fade-slide {
         animation: fadeSlide .6s ease both;
      }

      @keyframes fadeSlide {
         from {
            opacity: 0;
            transform: translateY(12px);
         }

         to {
            opacity: 1;
            transform: none;
         }
      }
   </style>
</head>

<body class="font-sans text-slate-800 antialiased bg-white">
   <main class="min-h-screen grid grid-cols-1 lg:grid-cols-2">

      <!-- KOLOM 1: Branding, Penjelasan & Nilai Tambah Layanan -->
      <section
         class="relative overflow-hidden bg-slate-950 text-white px-6 sm:px-12 xl:px-20 py-10 lg:py-14 flex flex-col justify-between order-2 lg:order-1">
         <div class="absolute -top-32 -left-24 w-96 h-96 rounded-full bg-brand-600/30 blur-3xl"></div>
         <div class="absolute -bottom-32 -right-24 w-96 h-96 rounded-full bg-emerald-500/20 blur-3xl"></div>

         <div class="relative z-10">
            <a href="<?= $site_url ?>" class="inline-flex items-center gap-2 font-extrabold text-xl tracking-tight">
               <span class="w-9 h-9 rounded-xl bg-brand-600 flex items-center justify-center text-white font-black">O</span>
               <?= $site_name ?>
            </a>

            <div class="mt-10 lg:mt-16 max-w-xl">
               <span
                  class="text-xs font-extrabold uppercase tracking-wider text-brand-300 bg-white/10 px-3 py-1 rounded-full border border-white/10">
                  Portal Pengelolaan Terpadu
               </span>
               <h1 class="text-3xl sm:text-4xl xl:text-5xl font-extrabold leading-tight mt-5">
                  Daftar akun &amp; pantau kemajuan aset digital bisnis Anda.
               </h1>
               <p class="text-slate-300 mt-5 leading-relaxed text-sm sm:text-base">
                  Dengan membuat akun di Portal Klien OkeIniAja, Anda dapat memantau setiap tahap pengerjaan aset digital usaha Anda: mulai dari draf artikel SEO, jadwal konten media sosial, hingga lead calon pembeli yang masuk ke WhatsApp.
               </p>

               <ul class="mt-6 space-y-3.5 text-sm text-slate-200">
                  <li class="flex items-center gap-3">
                     <span class="w-5 h-5 rounded-full bg-emerald-500/20 text-emerald-400 flex items-center justify-center shrink-0">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                           <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
                        </svg>
                     </span>
                     <span>Akses kalender konten Instagram &amp; Facebook siap tayang</span>
                  </li>
                  <li class="flex items-center gap-3">
                     <span class="w-5 h-5 rounded-full bg-emerald-500/20 text-emerald-400 flex items-center justify-center shrink-0">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                           <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
                        </svg>
                     </span>
                     <span>Draf &amp; laporan publikasi artikel SEO Google teratur</span>
                  </li>
                  <li class="flex items-center gap-3">
                     <span class="w-5 h-5 rounded-full bg-emerald-500/20 text-emerald-400 flex items-center justify-center shrink-0">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                           <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
                        </svg>
                     </span>
                     <span>Pemantauan leads WhatsApp dan optimasi katalog produk</span>
                  </li>
                  <li class="flex items-center gap-3">
                     <span class="w-5 h-5 rounded-full bg-emerald-500/20 text-emerald-400 flex items-center justify-center shrink-0">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                           <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
                        </svg>
                     </span>
                     <span>Layanan terkelola dikerjakan "Terima Beres" oleh tim spesialis</span>
                  </li>
               </ul>
            </div>
         </div>

         <!-- Trust Banner -->
         <div class="relative z-10 mt-10 lg:mt-6 max-w-xl">
            <div class="rounded-2xl bg-white/5 border border-white/10 p-4 sm:p-5 flex items-center gap-4">
               <div class="w-12 h-12 rounded-xl bg-brand-500/20 text-brand-400 flex items-center justify-center font-black text-xl shrink-0">
                  ★
               </div>
               <div>
                  <p class="text-sm font-bold text-white">Dipercaya 1.850+ Bisnis &amp; UMKM</p>
                  <p class="text-xs text-slate-400 mt-0.5">Solusi hemat tenaga &amp; biaya mengelola website dan akun media sosial tanpa harus rekrut tim in-house mahal.</p>
               </div>
            </div>
            <p class="text-xs text-slate-500 mt-5">&copy; <?= $current_year ?> <?= $site_name ?>. Semua hak dilindungi.</p>
         </div>
      </section>

      <!-- KOLOM 2: Form Pendaftaran Akun Pengguna Baru -->
      <section class="flex items-center justify-center px-6 sm:px-12 py-10 lg:py-14 bg-slate-50 order-1 lg:order-2 overflow-y-auto">
         <div class="w-full max-w-md fade-slide my-auto">
            <div class="w-12 h-12 rounded-2xl bg-brand-50 text-brand-600 flex items-center justify-center mb-5 shadow-sm">
               <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                     d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
               </svg>
            </div>
            <h2 class="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight">Daftar Akun Pengguna Baru</h2>
            <p class="text-sm text-slate-500 mt-1.5 leading-relaxed">
               Buat akun untuk mengakses Portal Klien dan mulai mengelola kemitraan layanan Anda.
            </p>

            <!-- Form -->
            <form method="POST" action="register.php" class="space-y-4 mt-6" id="registerForm" novalidate>
               <div>
                  <label for="name" class="block text-xs font-bold text-slate-700 mb-1">Nama Lengkap / PIC Usaha *</label>
                  <input type="text" id="name" name="name" required autocomplete="name"
                     value="<?= htmlspecialchars($name) ?>"
                     placeholder="Contoh: Budi Santoso"
                     class="w-full px-4 py-2.5 rounded-xl border border-slate-200 bg-white text-sm focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600 transition-all">
               </div>

               <div>
                  <label for="business_name" class="block text-xs font-bold text-slate-700 mb-1">Nama Usaha / Brand *</label>
                  <input type="text" id="business_name" name="business_name" required
                     value="<?= htmlspecialchars($business_name) ?>"
                     placeholder="Contoh: CV Berkah Jaya Mandiri"
                     class="w-full px-4 py-2.5 rounded-xl border border-slate-200 bg-white text-sm focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600 transition-all">
               </div>

               <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  <div>
                     <label for="whatsapp" class="block text-xs font-bold text-slate-700 mb-1">No. WhatsApp Aktif *</label>
                     <input type="tel" id="whatsapp" name="whatsapp" required autocomplete="tel"
                        value="<?= htmlspecialchars($whatsapp) ?>"
                        placeholder="081234567890"
                        class="w-full px-4 py-2.5 rounded-xl border border-slate-200 bg-white text-sm focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600 transition-all">
                  </div>
                  <div>
                     <label for="email" class="block text-xs font-bold text-slate-700 mb-1">Alamat Email *</label>
                     <input type="email" id="email" name="email" required autocomplete="email"
                        value="<?= htmlspecialchars($email) ?>"
                        placeholder="nama@bisnisanda.com"
                        class="w-full px-4 py-2.5 rounded-xl border border-slate-200 bg-white text-sm focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600 transition-all">
                  </div>
               </div>

               <div>
                  <div class="flex justify-between items-center mb-1">
                     <label for="password" class="block text-xs font-bold text-slate-700">Password / PIN Akun *</label>
                     <span class="text-[10px] text-slate-400 font-medium">Min. 6 Karakter</span>
                  </div>
                  <div class="relative">
                     <input type="password" id="password" name="password" required autocomplete="new-password"
                        placeholder="Minimal 6 karakter"
                        class="w-full px-4 py-2.5 pr-16 rounded-xl border border-slate-200 bg-white text-sm focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600 transition-all">
                     <button type="button" onclick="toggleVisibility('password', this)"
                        class="absolute inset-y-0 right-3 text-xs font-semibold text-slate-500 hover:text-slate-800 focus:outline-none">Lihat</button>
                  </div>
               </div>

               <div>
                  <div class="flex justify-between items-center mb-1">
                     <label for="password_confirm" class="block text-xs font-bold text-slate-700">Konfirmasi Password *</label>
                  </div>
                  <div class="relative">
                     <input type="password" id="password_confirm" name="password_confirm" required autocomplete="new-password"
                        placeholder="Ulangi password di atas"
                        class="w-full px-4 py-2.5 pr-16 rounded-xl border border-slate-200 bg-white text-sm focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600 transition-all">
                     <button type="button" onclick="toggleVisibility('password_confirm', this)"
                        class="absolute inset-y-0 right-3 text-xs font-semibold text-slate-500 hover:text-slate-800 focus:outline-none">Lihat</button>
                  </div>
                  <p id="matchNotice" class="hidden text-[11px] text-rose-600 mt-1 font-medium">Password konfirmasi belum cocok.</p>
               </div>

               <div class="pt-1">
                  <label class="flex items-start gap-2.5 cursor-pointer text-xs text-slate-600 select-none">
                     <input type="checkbox" name="agree" id="agree" required checked
                        class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                     <span>Saya menyetujui syarat &amp; ketentuan serta kebijakan privasi data layanan pengelolaan <?= $site_name ?>.</span>
                  </label>
               </div>

               <button type="submit" id="submitBtn"
                  class="w-full py-3.5 bg-slate-900 hover:bg-brand-600 text-white font-extrabold rounded-xl transition-all text-sm shadow-md hover:shadow-brand-500/25 active:scale-95 flex items-center justify-center gap-2 mt-2">
                  <span>Daftar Akun Sekarang</span>
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                     <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                  </svg>
               </button>
            </form>

            <div class="mt-6 pt-5 border-t border-slate-200 text-center text-xs text-slate-500">
               Sudah memiliki akun?
               <a href="index.php" class="font-bold text-brand-600 hover:underline">Masuk disini</a>
            </div>
         </div>
      </section>
   </main>

   <script>
      function toggleVisibility(id, btn) {
         const input = document.getElementById(id);
         const isPassword = input.type === 'password';
         input.type = isPassword ? 'text' : 'password';
         btn.textContent = isPassword ? 'Sembunyikan' : 'Lihat';
      }

      // Validasi interaktif password match
      const p1 = document.getElementById('password');
      const p2 = document.getElementById('password_confirm');
      const matchNotice = document.getElementById('matchNotice');

      function checkMatch() {
         if (p2.value && p1.value !== p2.value) {
            matchNotice.classList.remove('hidden');
         } else {
            matchNotice.classList.add('hidden');
         }
      }

      p1.addEventListener('input', checkMatch);
      p2.addEventListener('input', checkMatch);

      document.getElementById('registerForm').addEventListener('submit', function (e) {
         if (p1.value !== p2.value) {
            e.preventDefault();
            matchNotice.classList.remove('hidden');
            showToast('error', 'Konfirmasi password tidak cocok dengan password yang dimasukkan.', 'Password tidak cocok');
            p2.focus();
         }
      });
   </script>
<?php
$toastQueue = [];
foreach ($errors as $err) {
   $toastQueue[] = ['type' => 'error', 'title' => 'Periksa Kembali', 'message' => (string)$err];
}
include __DIR__ . '/toast.php';
?>
</body>

</html>