<script setup lang="ts">
import { RouterLink, RouterView } from 'vue-router'
</script>

<template>
  <div class="min-h-screen flex flex-col bg-[#FAF8F6] text-stone-900 print:min-h-0 print:bg-white">
    <header class="sticky top-0 z-30 border-b border-stone-200/80 bg-white/95 backdrop-blur-xs print:hidden">
      <div class="mx-auto max-w-6xl px-4 sm:px-6 h-16 flex items-center justify-between gap-4">
        <RouterLink :to="{ name: 'home' }" class="flex items-center gap-2.5">
          <span
            class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-terracotta-600 text-sm font-bold text-white"
          >
            PM
          </span>
          <span class="text-base font-bold tracking-tight text-stone-900">PADEL MAKASSAR</span>
        </RouterLink>

        <nav class="flex items-center gap-5 text-sm font-medium">
          <RouterLink
            :to="{ name: 'home' }"
            class="text-stone-600 hover:text-terracotta-600 transition-colors"
            active-class="text-terracotta-600 font-semibold"
          >
            Booking Lapangan
          </RouterLink>
          <RouterLink
            :to="{ name: 'booking-lookup' }"
            class="text-stone-600 hover:text-terracotta-600 transition-colors"
            active-class="text-terracotta-600 font-semibold"
          >
            Cek Pesanan
          </RouterLink>
          <RouterLink
            :to="{ name: 'policies' }"
            class="text-stone-600 hover:text-terracotta-600 transition-colors"
            active-class="text-terracotta-600 font-semibold"
          >
            Aturan & Kebijakan
          </RouterLink>
        </nav>
      </div>
    </header>

    <main class="flex-1 mx-auto w-full max-w-6xl px-4 sm:px-6 py-8 print:p-0 print:max-w-none">
      <RouterView />
    </main>

    <footer class="border-t border-stone-200 bg-white py-6 print:hidden">
      <div
        class="mx-auto max-w-6xl px-4 sm:px-6 flex flex-col sm:flex-row items-center justify-between gap-2 text-xs text-stone-500"
      >
        <span>PADEL MAKASSAR • Jl. Letjen Hertasning No.2, Panakkukang, Kota Makassar</span>
        <span>WhatsApp Operasional: 082347838252 • Zona Waktu WITA</span>
      </div>
    </footer>
  </div>
</template>
