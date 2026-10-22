<?php

declare(strict_types=1);

/**
 * Partial toast notification.
 * Pakai: set $toastQueue = [['type' => 'success|error|warning|info', 'title' => '...', 'message' => '...'], ...]
 * lalu `include __DIR__ . '/toast.php';` sebelum </body>.
 * Dari JavaScript: showToast('success', 'Pesan', 'Judul opsional');
 */
$toastQueue = $toastQueue ?? [];
?>
<style>
   #toastContainer {
      position: fixed; top: 1rem; right: 1rem; z-index: 9999;
      display: flex; flex-direction: column; gap: .625rem;
      width: min(380px, calc(100vw - 2rem)); pointer-events: none;
   }
   .toast {
      pointer-events: auto; position: relative; overflow: hidden;
      display: flex; align-items: flex-start; gap: .75rem;
      padding: .875rem 1rem; border-radius: 1rem; background: #fff;
      border: 1px solid #e2e8f0; border-left-width: 4px;
      box-shadow: 0 12px 32px -8px rgba(15, 23, 42, .25);
      font-family: 'Plus Jakarta Sans', Inter, sans-serif;
      opacity: 0; transform: translateX(24px) scale(.98);
      transition: opacity .25s ease, transform .25s ease;
   }
   .toast.show { opacity: 1; transform: none; }
   .toast.hide { opacity: 0; transform: translateX(24px) scale(.98); }
   .toast-icon { flex: none; width: 1.75rem; height: 1.75rem; border-radius: .6rem; display: flex; align-items: center; justify-content: center; }
   .toast-icon svg { width: 1rem; height: 1rem; }
   .toast-body { flex: 1; min-width: 0; }
   .toast-title { font-size: .8rem; font-weight: 800; color: #0f172a; line-height: 1.2; }
   .toast-msg { font-size: .75rem; color: #475569; margin-top: .15rem; line-height: 1.45; word-break: break-word; }
   .toast-close { flex: none; color: #94a3b8; font-size: 1rem; line-height: 1; padding: .1rem .25rem; border-radius: .4rem; background: none; border: 0; cursor: pointer; }
   .toast-close:hover { color: #334155; background: #f1f5f9; }
   .toast-bar { position: absolute; left: 0; bottom: 0; height: 3px; width: 100%; transform-origin: left; animation: toastBar linear forwards; }
   @keyframes toastBar { from { transform: scaleX(1); } to { transform: scaleX(0); } }
   .toast:hover .toast-bar { animation-play-state: paused; }
   .toast-success { border-left-color: #10b981; } .toast-success .toast-icon { background: #d1fae5; color: #059669; } .toast-success .toast-bar { background: #10b981; }
   .toast-error   { border-left-color: #f43f5e; } .toast-error .toast-icon   { background: #ffe4e6; color: #e11d48; } .toast-error .toast-bar   { background: #f43f5e; }
   .toast-warning { border-left-color: #f59e0b; } .toast-warning .toast-icon { background: #fef3c7; color: #d97706; } .toast-warning .toast-bar { background: #f59e0b; }
   .toast-info    { border-left-color: #3b82f6; } .toast-info .toast-icon    { background: #dbeafe; color: #2563eb; } .toast-info .toast-bar    { background: #3b82f6; }
   .toast-confirm-actions { display: flex; justify-content: flex-end; gap: .5rem; margin-top: .65rem; }
   .toast-btn { font: inherit; font-size: .72rem; font-weight: 800; padding: .4rem .9rem; border-radius: .6rem; border: 1px solid #e2e8f0; background: #fff; color: #475569; cursor: pointer; transition: background .15s, color .15s; }
   .toast-btn:hover { background: #f1f5f9; }
   .toast-btn-ok { border-color: transparent; background: #0f172a; color: #fff; }
   .toast-btn-ok:hover { background: #d54e32; }
   .toast-btn-danger { background: #e11d48; }
   .toast-btn-danger:hover { background: #be123c; }
</style>

<div id="toastContainer" role="region" aria-label="Notifikasi" aria-live="polite"></div>

<script>
   (function () {
      var ICONS = {
         success: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>',
         error: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/>',
         warning: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 9v4m0 4h.01M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z"/>',
         info: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>'
      };
      var TITLES = { success: 'Berhasil!', error: 'Perhatian!', warning: 'Peringatan', info: 'Informasi' };

      window.showToast = function (type, message, title, duration) {
         type = ICONS[type] ? type : 'info';
         duration = duration || (type === 'error' ? 7000 : 5000);
         var box = document.getElementById('toastContainer');
         if (!box) return;

         var el = document.createElement('div');
         el.className = 'toast toast-' + type;
         el.setAttribute('role', type === 'error' ? 'alert' : 'status');

         var icon = document.createElement('div');
         icon.className = 'toast-icon';
         icon.innerHTML = '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24">' + ICONS[type] + '</svg>';

         var body = document.createElement('div');
         body.className = 'toast-body';
         var t = document.createElement('div');
         t.className = 'toast-title';
         t.textContent = title || TITLES[type];
         var m = document.createElement('div');
         m.className = 'toast-msg';
         m.textContent = message;
         body.appendChild(t);
         body.appendChild(m);

         var close = document.createElement('button');
         close.type = 'button';
         close.className = 'toast-close';
         close.setAttribute('aria-label', 'Tutup notifikasi');
         close.innerHTML = '&times;';

         var bar = document.createElement('div');
         bar.className = 'toast-bar';
         bar.style.animationDuration = duration + 'ms';

         el.appendChild(icon); el.appendChild(body); el.appendChild(close); el.appendChild(bar);
         box.appendChild(el);
         requestAnimationFrame(function () { el.classList.add('show'); });

         function dismiss() {
            if (el.dataset.closing) return;
            el.dataset.closing = '1';
            el.classList.remove('show');
            el.classList.add('hide');
            setTimeout(function () { el.remove(); }, 260);
         }
         close.addEventListener('click', dismiss);
         bar.addEventListener('animationend', dismiss);
         return el;
      };

      // Konfirmasi berbentuk toast (pengganti confirm() bawaan browser)
      // Pakai: toastConfirm('Pesan?', function () { ... }, { title, okText, cancelText, danger })
      // atau deklaratif: <form data-confirm="Pesan?"> / <a href="..." data-confirm="Pesan?">
      window.toastConfirm = function (message, onOk, opts) {
         opts = opts || {};
         var box = document.getElementById('toastContainer');
         if (!box) return;
         var type = opts.danger ? 'error' : 'warning';

         var el = document.createElement('div');
         el.className = 'toast toast-' + type;
         el.setAttribute('role', 'alertdialog');

         var icon = document.createElement('div');
         icon.className = 'toast-icon';
         icon.innerHTML = '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24">' + ICONS.warning + '</svg>';

         var body = document.createElement('div');
         body.className = 'toast-body';
         var t = document.createElement('div');
         t.className = 'toast-title';
         t.textContent = opts.title || 'Konfirmasi';
         var m = document.createElement('div');
         m.className = 'toast-msg';
         m.textContent = message;

         var actions = document.createElement('div');
         actions.className = 'toast-confirm-actions';
         var cancel = document.createElement('button');
         cancel.type = 'button';
         cancel.className = 'toast-btn';
         cancel.textContent = opts.cancelText || 'Batal';
         var ok = document.createElement('button');
         ok.type = 'button';
         ok.className = 'toast-btn toast-btn-ok' + (opts.danger ? ' toast-btn-danger' : '');
         ok.textContent = opts.okText || 'Ya, Lanjutkan';
         actions.appendChild(cancel);
         actions.appendChild(ok);

         body.appendChild(t); body.appendChild(m); body.appendChild(actions);
         el.appendChild(icon); el.appendChild(body);
         box.appendChild(el);
         requestAnimationFrame(function () { el.classList.add('show'); });
         ok.focus();

         function close() {
            document.removeEventListener('keydown', onKey);
            el.classList.remove('show');
            el.classList.add('hide');
            setTimeout(function () { el.remove(); }, 260);
         }
         function onKey(e) { if (e.key === 'Escape') close(); }
         document.addEventListener('keydown', onKey);
         cancel.addEventListener('click', close);
         ok.addEventListener('click', function () { close(); if (onOk) onOk(); });
      };

      document.addEventListener('submit', function (e) {
         var f = e.target;
         if (!f.matches || !f.matches('form[data-confirm]') || f.dataset.confirmed === '1') return;
         e.preventDefault();
         var submitter = e.submitter || null;
         window.toastConfirm(f.dataset.confirm, function () {
            f.dataset.confirmed = '1';
            if (f.requestSubmit) { f.requestSubmit(submitter); } else { f.submit(); }
         }, { danger: f.dataset.confirmDanger === '1', okText: f.dataset.confirmOk });
      }, true);

      document.addEventListener('click', function (e) {
         var a = e.target.closest ? e.target.closest('a[data-confirm]') : null;
         if (!a) return;
         e.preventDefault();
         window.toastConfirm(a.dataset.confirm, function () { window.location.href = a.href; }, {
            danger: a.dataset.confirmDanger === '1', okText: a.dataset.confirmOk
         });
      });

      var queue = <?= json_encode($toastQueue, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      queue.forEach(function (n, i) {
         setTimeout(function () { window.showToast(n.type, n.message, n.title); }, 150 + i * 180);
      });
   })();
</script>
