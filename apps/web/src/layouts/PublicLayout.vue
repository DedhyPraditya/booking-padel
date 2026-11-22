<script setup lang="ts">
import { ref } from 'vue'
import { RouterLink, RouterView } from 'vue-router'

const mobileOpen = ref(false)

const navItems = [
  { label: 'Booking Lapangan', to: { name: 'booking' } },
  { label: 'Cek Pesanan', to: { name: 'booking-lookup' } },
  { label: 'Aturan & Kebijakan', to: { name: 'policies' } },
]
</script>

<template>
  <div class="min-h-screen flex flex-col bg-[#FAF8F6] text-stone-900 print:min-h-0 print:bg-white">
    <header
      class="sticky top-0 z-30 border-b border-stone-200/80 bg-white/95 backdrop-blur-xs print:hidden"
    >
      <div class="mx-auto max-w-6xl px-4 sm:px-6 h-16 flex items-center justify-between gap-4">
        <RouterLink :to="{ name: 'home' }" class="flex items-center gap-2.5" @click="mobileOpen = false">
          <span
            class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-terracotta-600 text-sm font-extrabold text-white"
          >
            PM
          </span>
          <span class="text-base font-extrabold tracking-tight text-stone-900">PADEL MAKASSAR</span>
        </RouterLink>

        <nav class="hidden md:flex items-center gap-6 text-sm font-semibold">
          <RouterLink
            v-for="item in navItems"
            :key="item.label"
            :to="item.to"
            class="text-stone-600 hover:text-terracotta-600 transition-colors"
            active-class="!text-terracotta-600"
          >
            {{ item.label }}
          </RouterLink>
          <RouterLink
            :to="{ name: 'booking' }"
            class="rounded-lg bg-terracotta-600 px-4 py-2 text-xs font-bold text-white hover:bg-terracotta-700 transition-colors"
          >
            Booking Sekarang
          </RouterLink>
        </nav>

        <button
          type="button"
          class="md:hidden rounded-lg border border-stone-200 px-3 py-1.5 text-xs font-semibold text-stone-700"
          :aria-expanded="mobileOpen"
          @click="mobileOpen = !mobileOpen"
        >
          {{ mobileOpen ? 'Tutup' : 'Menu' }}
        </button>
      </div>

      <nav v-if="mobileOpen" class="md:hidden border-t border-stone-100 bg-white px-4 py-3 space-y-1">
        <RouterLink
          v-for="item in navItems"
          :key="item.label"
          :to="item.to"
          class="block rounded-lg px-3 py-2 text-sm font-semibold text-stone-700 hover:bg-terracotta-50"
          active-class="!bg-terracotta-50 !text-terracotta-700"
          @click="mobileOpen = false"
        >
          {{ item.label }}
        </RouterLink>
      </nav>
    </header>

    <main class="flex-1 mx-auto w-full max-w-6xl px-4 sm:px-6 py-8 print:p-0 print:max-w-none">
      <RouterView />
    </main>

    <footer class="bg-stone-950 text-stone-400 print:hidden">
      <div class="mx-auto max-w-6xl px-4 sm:px-6 py-12 grid gap-8 sm:grid-cols-3">
        <div class="space-y-3">
          <div class="flex items-center gap-2.5">
            <span
              class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-terracotta-600 text-sm font-extrabold text-white"
            >
              PM
            </span>
            <span class="text-base font-extrabold text-white">PADEL MAKASSAR</span>
          </div>
          <p class="text-sm">Jl. Letjen Hertasning No.2, Panakkukang, Kota Makassar</p>
        </div>

        <div class="space-y-2 text-sm">
          <p class="text-xs font-bold uppercase tracking-wider text-terracotta-400">Navigasi</p>
          <RouterLink :to="{ name: 'home' }" class="block hover:text-white">Beranda</RouterLink>
          <RouterLink
            v-for="item in navItems"
            :key="item.label"
            :to="item.to"
            class="block hover:text-white"
          >
            {{ item.label }}
          </RouterLink>
        </div>

        <div class="space-y-2 text-sm">
          <p class="text-xs font-bold uppercase tracking-wider text-terracotta-400">Kontak</p>
          <a
            href="https://wa.me/6282347838252"
            target="_blank"
            rel="noopener noreferrer"
            class="block hover:text-white"
          >
            WhatsApp 082347838252
          </a>
          <p>Zona waktu WITA (Asia/Makassar)</p>
        </div>
      </div>
      <div class="border-t border-white/10 py-4 text-center text-xs">
        © {{ new Date().getFullYear() }} PADEL MAKASSAR
      </div>
    </footer>
  </div>
</template>
