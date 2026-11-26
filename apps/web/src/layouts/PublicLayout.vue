<script setup lang="ts">
import { onMounted, onUnmounted, ref, watch } from 'vue'
import { RouterLink, RouterView, useRoute } from 'vue-router'
import Lenis from 'lenis'

const route = useRoute()
const mobileOpen = ref(false)
const scrollProgress = ref(0)

let lenis: Lenis | null = null
let rafId: number | null = null

const navItems = [
  { label: 'Booking Lapangan', to: { name: 'booking' }, routeName: 'booking' },
  { label: 'Cek Pesanan', to: { name: 'booking-lookup' }, routeName: 'booking-lookup' },
  { label: 'Aturan & Kebijakan', to: { name: 'policies' }, routeName: 'policies' },
]

function updateScrollProgress(): void {
  const docHeight = document.documentElement.scrollHeight - window.innerHeight
  if (docHeight <= 0) {
    scrollProgress.value = 0
    return
  }
  scrollProgress.value = Math.min(100, Math.max(0, (window.scrollY / docHeight) * 100))
}

watch(
  () => route.fullPath,
  () => {
    mobileOpen.value = false
    if (lenis) {
      lenis.scrollTo(0, { immediate: true })
    } else {
      window.scrollTo({ top: 0 })
    }
    requestAnimationFrame(updateScrollProgress)
  },
)

onMounted(() => {
  const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches
  if (!prefersReduced) {
    lenis = new Lenis({
      duration: 1.15,
      easing: (t) => Math.min(1, 1.001 - Math.pow(2, -10 * t)),
      smoothWheel: true,
      wheelMultiplier: 1,
      touchMultiplier: 1.6,
    })

    const raf = (time: number) => {
      lenis?.raf(time)
      rafId = requestAnimationFrame(raf)
    }
    rafId = requestAnimationFrame(raf)
  }

  window.addEventListener('scroll', updateScrollProgress, { passive: true })
  updateScrollProgress()
})

onUnmounted(() => {
  window.removeEventListener('scroll', updateScrollProgress)
  if (rafId !== null) cancelAnimationFrame(rafId)
  lenis?.destroy()
  lenis = null
})
</script>

<template>
  <div class="min-h-screen flex flex-col bg-[#FAF8F6] text-stone-900 print:min-h-0 print:bg-white">
    <header
      class="sticky top-0 z-30 border-b border-stone-200/80 bg-white/95 backdrop-blur-md transition-shadow print:hidden"
      :class="{ 'shadow-2xs': scrollProgress > 2 }"
    >
      <!-- Subtle Scroll Reading Progress Bar -->
      <div class="h-0.5 w-full bg-transparent overflow-hidden">
        <div
          class="h-full bg-terracotta-600 transition-[width] duration-150 ease-out"
          :style="{ width: `${scrollProgress}%` }"
        ></div>
      </div>

      <div class="mx-auto max-w-6xl px-4 sm:px-6 h-16 flex items-center justify-between gap-4">
        <RouterLink
          :to="{ name: 'home' }"
          class="group flex items-center gap-2.5 btn-tactile"
          @click="mobileOpen = false"
        >
          <span
            class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-terracotta-600 text-sm font-extrabold text-white transition-transform duration-300 group-hover:scale-105"
          >
            PM
          </span>
          <span class="text-base font-extrabold tracking-tight text-stone-900">PADEL MAKASSAR</span>
        </RouterLink>

        <nav class="hidden md:flex items-center gap-7 text-sm font-semibold">
          <RouterLink
            v-for="item in navItems"
            :key="item.label"
            :to="item.to"
            class="relative py-1.5 text-stone-600 hover:text-terracotta-600 transition-colors after:absolute after:bottom-0 after:left-0 after:h-0.5 after:w-full after:origin-left after:scale-x-0 after:rounded-full after:bg-terracotta-600 after:transition-transform after:duration-300 hover:after:scale-x-100"
            :class="{ '!text-terracotta-600 after:!scale-x-100': route.name === item.routeName }"
          >
            {{ item.label }}
          </RouterLink>
        </nav>

        <button
          type="button"
          class="md:hidden btn-tactile rounded-lg border border-stone-200 px-3 py-1.5 text-xs font-semibold text-stone-700 hover:bg-stone-50"
          :aria-expanded="mobileOpen"
          @click="mobileOpen = !mobileOpen"
        >
          {{ mobileOpen ? 'Tutup' : 'Menu' }}
        </button>
      </div>

      <Transition name="mobile-menu">
        <nav
          v-if="mobileOpen"
          class="md:hidden border-t border-stone-100 bg-white px-4 py-3 space-y-1 shadow-md"
        >
          <RouterLink
            v-for="item in navItems"
            :key="item.label"
            :to="item.to"
            class="block rounded-lg px-3 py-2 text-sm font-semibold text-stone-700 hover:bg-terracotta-50 transition-colors"
            active-class="!bg-terracotta-50 !text-terracotta-700"
            @click="mobileOpen = false"
          >
            {{ item.label }}
          </RouterLink>
        </nav>
      </Transition>
    </header>

    <main class="flex-1 mx-auto w-full max-w-6xl px-4 sm:px-6 py-8 print:p-0 print:max-w-none">
      <RouterView v-slot="{ Component }">
        <Transition name="page-fade" mode="out-in">
          <component :is="Component" />
        </Transition>
      </RouterView>
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
          <RouterLink :to="{ name: 'home' }" class="block hover:text-white transition-colors">
            Beranda
          </RouterLink>
          <RouterLink
            v-for="item in navItems"
            :key="item.label"
            :to="item.to"
            class="block hover:text-white transition-colors"
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
            class="block hover:text-white transition-colors"
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
