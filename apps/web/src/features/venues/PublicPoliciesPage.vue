<script setup lang="ts">
import { RouterLink } from 'vue-router'
</script>

<template>
  <section class="mx-auto max-w-3xl space-y-6">
    <div
      v-reveal
      class="rounded-3xl border border-stone-200 bg-white p-6 sm:p-8 shadow-xs space-y-6"
    >
      <div class="border-b border-stone-100 pb-4">
        <span class="rounded-full bg-terracotta-50 px-3 py-1 text-xs font-semibold text-terracotta-700">
          Informasi & Kebijakan Resmi
        </span>
        <h1 class="mt-2 text-2xl font-extrabold tracking-tight text-stone-900">
          Aturan Bermain, Pembayaran & Kebijakan Reschedule
        </h1>
        <p class="mt-1 text-sm text-stone-500">
          PADEL MAKASSAR — Jl. Letjen Hertasning No.2, Kec. Panakkukang, Kota Makassar
        </p>
      </div>

      <div class="space-y-4 text-sm text-stone-700">
        <div
          v-reveal="40"
          class="card-interactive rounded-2xl border border-stone-100 bg-[#FAF8F6] p-5 space-y-1.5"
        >
          <h2 class="font-bold text-stone-900">1. Pemesanan & Batas Waktu Hold Slot (10 Menit)</h2>
          <p>
            Setiap pemesanan online menahan slot jadwal pilihan Anda selama
            <strong>10 menit</strong>. Segera lakukan transfer ke rekening resmi
            <strong>Bank Mandiri 1520037845860 a.n. NURHASDI NANDAR</strong> dan unggah bukti
            transfer pada halaman status booking agar slot Anda diamankan sampai diverifikasi Admin.
          </p>
        </div>

        <div
          v-reveal="100"
          class="card-interactive rounded-2xl border border-stone-100 bg-[#FAF8F6] p-5 space-y-1.5"
        >
          <h2 class="font-bold text-stone-900">
            2. Booking Grup, Komunitas & Turnamen (Multi-Jam / 2 Lapangan)
          </h2>
          <p>
            Anda dapat memilih beberapa blok jam berurutan sekaligus maupun menyewa
            <strong>Lapangan 1 (001)</strong> dan <strong>Lapangan 2 (002)</strong> secara bersamaan
            dalam satu transaksi booking.
          </p>
        </div>

        <div
          v-reveal="160"
          class="card-interactive rounded-2xl border border-stone-100 bg-[#FAF8F6] p-5 space-y-1.5"
        >
          <h2 class="font-bold text-stone-900">3. Kebijakan Perubahan Jadwal (Reschedule) & Pembatalan</h2>
          <p>
            Pengajuan perubahan jadwal (reschedule) atau pembatalan wajib disampaikan maksimal
            <strong>24 jam sebelum waktu sesi dimulai</strong> melalui Admin Operasional WhatsApp di
            <strong>082347838252</strong>.
          </p>
        </div>

        <div
          v-reveal="220"
          class="card-interactive rounded-2xl border border-stone-100 bg-[#FAF8F6] p-5 space-y-1.5"
        >
          <h2 class="font-bold text-stone-900">4. Ketentuan Bermain di Lapangan Semi-Indoor</h2>
          <p>
            Pemain wajib hadir 10 menit sebelum jadwal untuk proses check-in, menggunakan sepatu
            khusus olahraga lapangan (non-marking court shoes), dan menjaga ketertiban area lapangan.
          </p>
        </div>
      </div>

      <div class="pt-2 border-t border-stone-100 flex flex-wrap items-center justify-between gap-3">
        <RouterLink
          :to="{ name: 'booking' }"
          class="btn-tactile rounded-xl bg-terracotta-600 px-5 py-2.5 text-xs font-semibold text-white hover:bg-terracotta-700"
        >
          ← Kembali ke Jadwal & Booking
        </RouterLink>
        <a
          href="https://wa.me/6282347838252"
          target="_blank"
          rel="noopener noreferrer"
          class="text-xs font-semibold text-terracotta-700 hover:underline"
        >
          Hubungi WhatsApp Admin (082347838252) ↗
        </a>
      </div>
    </div>
  </section>
</template>
