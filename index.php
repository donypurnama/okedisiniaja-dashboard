<?php

declare(strict_types=1);

/**
 * OkeIniAja Admin - Halaman Login Portal Klien (full page, 2 kolom)
 */
session_start();
require_once __DIR__ . '/db.php';

// Jika sudah login, langsung alihkan ke dashboard
if (isset($_SESSION['user_id'])) {
   header("Location: dashboard.php");
   exit;
}

$site_name = "OkeIniAja.com";
$site_url = "https://okeiniaja.com/";
$contact_wa = "6281291311218";
$current_year = date('Y');
$login_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
   $user = trim($_POST['user'] ?? '');
   $pass = $_POST['pass'] ?? '';

   if ($user === '' || $pass === '') {
      $login_error = 'Email / No. WhatsApp dan password wajib diisi.';
   } else {
      try {
         $pdo = db();
         $cleanWa = preg_replace('/[^0-9]/', '', $user);
         $cleanWaAlt = str_starts_with($cleanWa, '0') ? '62' . substr($cleanWa, 1) : $cleanWa;

         $stmt = $pdo->prepare("
                SELECT * FROM users 
                WHERE (LOWER(email) = LOWER(?) OR whatsapp = ? OR whatsapp = ? OR whatsapp = ?) 
                AND status = 'active' 
                LIMIT 1
            ");
         $stmt->execute([$user, $user, $cleanWa, $cleanWaAlt]);
         $foundUser = $stmt->fetch();

         if ($foundUser && password_verify($pass, $foundUser['password_hash'])) {
            $upStmt = $pdo->prepare("UPDATE users SET last_login_at = datetime('now') WHERE id = ?");
            $upStmt->execute([$foundUser['id']]);

            $_SESSION['user_id'] = $foundUser['id'];
            $_SESSION['user_name'] = $foundUser['name'];
            $_SESSION['business_name'] = $foundUser['business_name'];
            $_SESSION['user_email'] = $foundUser['email'];
            $_SESSION['user_whatsapp'] = $foundUser['whatsapp'];
            $_SESSION['user_role'] = $foundUser['role'];

            header("Location: dashboard.php");
            exit;
         } else {
            $login_error = 'Email / No. WhatsApp atau password yang Anda masukkan tidak sesuai.';
         }
      } catch (Throwable $e) {
         $login_error = 'Terjadi kesalahan sistem: ' . $e->getMessage();
      }
   }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>Masuk Portal Klien - <?= $site_name ?></title>
   <meta name="description"
      content="Masuk ke portal klien OkeIniAja.com untuk memantau progres artikel SEO, kalender media sosial, dan laporan bulanan bisnis Anda.">
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

      .testi {
         display: none;
      }

      .testi.active {
         display: block;
         animation: fadeSlide .5s ease both;
      }
   </style>
</head>

<body class="font-sans text-slate-800 antialiased bg-white">
   <main class="min-h-screen grid grid-cols-1 lg:grid-cols-2">

      <!-- KOLOM 1: Penjelasan layanan & testimoni -->
      <section
         class="relative overflow-hidden bg-slate-950 text-white px-6 sm:px-12 xl:px-20 py-10 lg:py-14 flex flex-col justify-between order-2 lg:order-1">
         <div class="absolute -top-32 -left-24 w-96 h-96 rounded-full bg-brand-600/30 blur-3xl"></div>
         <div class="absolute -bottom-32 -right-24 w-96 h-96 rounded-full bg-emerald-500/20 blur-3xl"></div>

         <div class="relative z-10">
            <a href="<?= $site_url ?>" class="inline-flex items-center gap-2 font-extrabold text-xl tracking-tight">
               <span class="w-9 h-9 rounded-xl bg-brand-600 flex items-center justify-center text-white">O</span>
               <?= $site_name ?>
            </a>

            <div class="mt-12 lg:mt-20 max-w-xl">
               <span
                  class="text-xs font-extrabold uppercase tracking-wider text-brand-300 bg-white/10 px-3 py-1 rounded-full">
                  Terima Beres
               </span>
               <h1 class="text-3xl sm:text-4xl xl:text-5xl font-extrabold leading-tight mt-5">
                  Website terawat &amp; media sosial aktif, tanpa pusing.
               </h1>
               <p class="text-slate-300 mt-5 leading-relaxed">
                  OkeIniAja mengelola website dan media sosial bisnis Anda setiap bulan: artikel SEO, desain konten
                  Instagram &amp; Facebook, update katalog produk, optimasi kecepatan, hingga integrasi WhatsApp.
                  Mulai dari Rp 75.000/bulan.
               </p>

               <ul class="mt-6 space-y-3 text-sm text-slate-200">
                  <?php foreach (['Artikel SEO bulanan siap tayang', 'Kalender &amp; desain konten medsos', 'Laporan progres bulanan transparan'] as $f): ?>
                     <li class="flex items-center gap-3">
                        <span
                           class="w-5 h-5 rounded-full bg-emerald-500/20 text-emerald-400 flex items-center justify-center">
                           <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
                           </svg>
                        </span>
                        <?= $f ?>
                     </li>
                  <?php endforeach; ?>
               </ul>
            </div>
         </div>

         <!-- Testimoni -->
         <div class="relative z-10 mt-12 lg:mt-0 max-w-xl">
            <div class="rounded-3xl bg-white/5 border border-white/10 backdrop-blur p-6 sm:p-7">
               <p class="text-amber-400 text-sm mb-3">★★★★★</p>

               <div id="testiWrap" aria-live="polite">
                  <div class="testi active">
                     <p class="text-sm sm:text-base text-slate-100 leading-relaxed italic">
                        "Dulu bayar agensi 4 juta per bulan tapi postingannya jarang dan website lemot. Setelah
                        dikelola tim OkeIniAja, website sewa tenda kami tembus halaman 1 Google dan chat WA masuk
                        tiap hari!"
                     </p>
                     <div class="flex items-center gap-3 mt-5">
                        <img class="w-11 h-11 rounded-full object-cover"
                           src="https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=120&auto=format&fit=crop&q=80"
                           alt="Budi Santoso">
                        <div>
                           <p class="text-sm font-bold">Budi Santoso</p>
                           <p class="text-xs text-slate-400">Owner Sewa Tenda &amp; Event Organizer</p>
                        </div>
                     </div>
                  </div>
                  <div class="testi">
                     <p class="text-sm sm:text-base text-slate-100 leading-relaxed italic">
                        "Saya nggak sempat nulis artikel blog atau mikir ide postingan IG tiap hari. Semua materi
                        dibuatkan rapi oleh tim OkeIniAja. Followers naik dan pesanan katering bertambah drastis."
                     </p>
                     <div class="flex items-center gap-3 mt-5">
                        <img class="w-11 h-11 rounded-full object-cover"
                           src="https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=120&auto=format&fit=crop&q=80"
                           alt="Dewi Lestari">
                        <div>
                           <p class="text-sm font-bold">Dewi Lestari</p>
                           <p class="text-xs text-slate-400">Founder Sambal Bu Dewi Solo</p>
                        </div>
                     </div>
                  </div>
                  <div class="testi">
                     <p class="text-sm sm:text-base text-slate-100 leading-relaxed italic">
                        "Paling puas dengan kompresi foto katalog dan tombol direct WhatsApp-nya. Website katalog
                        kami sekarang ngebut di HP dan sangat membantu penjualan B2B kami!"
                     </p>
                     <div class="flex items-center gap-3 mt-5">
                        <img class="w-11 h-11 rounded-full object-cover"
                           src="https://images.unsplash.com/photo-1500648767791-00dcc994a43e?w=120&auto=format&fit=crop&q=80"
                           alt="Hendra Wijaya">
                        <div>
                           <p class="text-sm font-bold">Hendra Wijaya</p>
                           <p class="text-xs text-slate-400">Direktur CV Sumber Rezeki Logistik</p>
                        </div>
                     </div>
                  </div>
               </div>

               <div class="flex gap-2 mt-5" id="testiDots">
                  <button type="button" class="h-1.5 w-8 rounded-full bg-brand-500" aria-label="Testimoni 1"></button>
                  <button type="button" class="h-1.5 w-4 rounded-full bg-white/25" aria-label="Testimoni 2"></button>
                  <button type="button" class="h-1.5 w-4 rounded-full bg-white/25" aria-label="Testimoni 3"></button>
               </div>
            </div>
            <p class="text-xs text-slate-500 mt-6">&copy; <?= $current_year ?> <?= $site_name ?>. Semua hak dilindungi.
            </p>
         </div>
      </section>

      <!-- KOLOM 2: Form login -->
      <section class="flex items-center justify-center px-6 sm:px-12 py-12 bg-slate-50 order-1 lg:order-2">
         <div class="w-full max-w-md fade-slide">
            <div class="w-12 h-12 rounded-2xl bg-brand-50 text-brand-600 flex items-center justify-center mb-5">
               <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                     d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1" />
               </svg>
            </div>
            <h2 class="text-3xl font-extrabold text-slate-900">Masuk ke Portal Klien</h2>

            <?php if (isset($_GET['registered'])): ?>
               <div
                  class="mt-4 p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs flex items-start gap-3">
                  <svg class="w-5 h-5 text-emerald-600 shrink-0 mt-0.5" fill="none" stroke="currentColor"
                     viewBox="0 0 24 24">
                     <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                  </svg>
                  <div>
                     <strong class="font-bold block text-sm mb-0.5 text-emerald-900">Pendaftaran Berhasil!</strong>
                     Akun Anda telah aktif. Silakan masuk menggunakan email atau nomor WhatsApp yang telah Anda daftarkan.
                  </div>
               </div>
            <?php endif; ?>
            <p class="text-sm text-slate-500 mt-2">Pantau progres artikel, kalender medsos, dan laporan bulanan bisnis
               Anda.</p>

            <form id="loginForm" method="POST" action="index.php" class="space-y-5 mt-8">
               <div>
                  <label for="loginUser" class="block text-xs font-bold text-slate-700 mb-1.5">Email atau No. WhatsApp
                     Terdaftar *</label>
                  <input type="text" id="loginUser" name="user" required autocomplete="username"
                     value="<?= htmlspecialchars($_GET['u'] ?? '') ?>" placeholder="nama@bisnisanda.com atau 0812xxx"
                     class="w-full px-4 py-3 rounded-xl border border-slate-200 bg-white text-sm focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600 transition-all">
               </div>
               <div>
                  <div class="flex justify-between items-center mb-1.5">
                     <label for="loginPass" class="block text-xs font-bold text-slate-700">Password / PIN Akun *</label>
                     <a href="https://wa.me/<?= $contact_wa ?>?text=Halo%20OkeIniAja%2C%20saya%20lupa%20password%20akun%20saya"
                        target="_blank" rel="noopener" class="text-[11px] text-brand-600 hover:underline">Lupa
                        Password?</a>
                  </div>
                  <div class="relative">
                     <input type="password" id="loginPass" name="pass" required autocomplete="current-password"
                        placeholder="••••••••"
                        class="w-full px-4 py-3 pr-16 rounded-xl border border-slate-200 bg-white text-sm focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600 transition-all">
                     <button type="button" id="togglePass"
                        class="absolute inset-y-0 right-3 text-xs font-semibold text-slate-500 hover:text-slate-800">Lihat</button>
                  </div>
               </div>

               <button type="submit"
                  class="w-full py-3.5 bg-slate-900 hover:bg-brand-600 text-white font-extrabold rounded-xl transition-all text-sm shadow-md hover:shadow-brand-500/25 active:scale-95 flex items-center justify-center gap-2">
                  <span>Masuk ke Portal</span>
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                     <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M14 5l7 7m0 0l-7 7m7-7H3" />
                  </svg>
               </button>
            </form>

            <div class="mt-8 pt-5 border-t border-slate-200 text-center text-xs text-slate-500">
               Belum memiliki akun?
               <a href="register.php" class="font-bold text-brand-600 hover:underline">Daftar disini</a>
            </div>
         </div>
      </section>
   </main>

   <script>
      // Rotasi testimoni
      const items = document.querySelectorAll('.testi');
      const dots = document.querySelectorAll('#testiDots button');
      let idx = 0, timer;
      function show(i) {
         idx = i;
         items.forEach((el, n) => el.classList.toggle('active', n === i));
         dots.forEach((d, n) => {
            d.className = 'h-1.5 rounded-full ' + (n === i ? 'w-8 bg-brand-500' : 'w-4 bg-white/25');
         });
      }
      function start() { clearInterval(timer); timer = setInterval(() => show((idx + 1) % items.length), 6000); }
      dots.forEach((d, n) => d.addEventListener('click', () => { show(n); start(); }));
      start();

      // Tampilkan / sembunyikan password
      document.getElementById('togglePass').addEventListener('click', function () {
         const p = document.getElementById('loginPass');
         const hide = p.type === 'password';
         p.type = hide ? 'text' : 'password';
         this.textContent = hide ? 'Sembunyikan' : 'Lihat';
      });

      // Validasi sederhana form login sebelum dikirim ke server
      document.getElementById('loginForm').addEventListener('submit', function (e) {
         const user = document.getElementById('loginUser').value.trim();
         const pass = document.getElementById('loginPass').value;
         if (!user || !pass) {
            e.preventDefault();
            showToast('error', 'Email/No. WhatsApp dan password wajib diisi.', 'Data belum lengkap');
            return;
         }
      });
   </script>
<?php
$toastQueue = !empty($login_error)
   ? [['type' => 'error', 'title' => 'Login Gagal', 'message' => (string)$login_error]]
   : [];
include __DIR__ . '/toast.php';
?>
</body>

</html>