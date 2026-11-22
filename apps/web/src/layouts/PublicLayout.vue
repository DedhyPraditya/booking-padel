<script setup lang="ts">
import { RouterLink, RouterView, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()

async function handleLogout(): Promise<void> {
  await auth.logout()
  await router.push({ name: 'home' })
}
</script>

<template>
  <div class="min-h-screen flex flex-col bg-[#FAF8F6] text-stone-900">
    <header class="sticky top-0 z-30 border-b border-stone-200/80 bg-white/95 backdrop-blur-xs">
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

          <template v-if="auth.isAuthenticated">
            <RouterLink
              :to="{ name: 'admin-overview' }"
              class="rounded-lg bg-terracotta-50 px-3.5 py-1.5 text-terracotta-700 font-semibold hover:bg-terracotta-100 transition-colors"
            >
              Dashboard Admin
            </RouterLink>
            <button
              type="button"
              class="text-xs text-stone-500 hover:text-stone-900"
              @click="handleLogout"
            >
              Keluar
            </button>
          </template>
          <RouterLink
            v-else
            :to="{ name: 'login' }"
            class="rounded-lg bg-terracotta-600 px-4 py-2 text-xs font-semibold text-white shadow-2xs hover:bg-terracotta-700 transition-colors"
          >
            Login Admin
          </RouterLink>
        </nav>
      </div>
    </header>

    <main class="flex-1 mx-auto w-full max-w-6xl px-4 sm:px-6 py-8">
      <RouterView />
    </main>

    <footer class="border-t border-stone-200 bg-white py-6">
      <div
        class="mx-auto max-w-6xl px-4 sm:px-6 flex flex-col sm:flex-row items-center justify-between gap-2 text-xs text-stone-500"
      >
        <span>PADEL MAKASSAR • Jl. Letjen Hertasning No.2, Panakkukang, Kota Makassar</span>
        <span>WhatsApp Operasional: 082347838252 • Zona Waktu WITA</span>
      </div>
    </footer>
  </div>
</template>
