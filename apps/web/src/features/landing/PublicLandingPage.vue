<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import type { Venue } from '@/types/api'
import { ApiError, apiRequest } from '@/services/apiClient'
import AppIcon from '@/components/ui/AppIcon.vue'

const venue = ref<Venue | null>(null)
const loading = ref(true)
const errorMessage = ref<string | null>(null)

// Scroll Craft State
const scrollY = ref(0)
const activeSection = ref<'hero' | 'lapangan' | 'tarif' | 'alur' | 'fasilitas' | 'lokasi'>('hero')
const selectedCourtIndex = ref(0)
const activeHotspot = ref<'glass' | 'turf' | 'lighting' | 'net' | null>('glass')

// Interactive Estimator State
const estimatorDayType = ref<'weekday' | 'weekend'>('weekday')
const estimatorSlotType = ref<'regular' | 'peak'>('regular')

const dayNames = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu']

function handleScroll(): void {
  scrollY.value = window.scrollY
  const sections = ['hero', 'lapangan', 'tarif', 'alur', 'fasilitas', 'lokasi'] as const
  for (const id of [...sections].reverse()) {
    const el = document.getElementById(id)
    if (el) {
      const rect = el.getBoundingClientRect()
      if (rect.top <= 160) {
        activeSection.value = id
        break
      }
    }
  }
}

function scrollToSection(id: string): void {
  const el = document.getElementById(id)
  if (el) {
    const offset = 80
    const bodyRect = document.body.getBoundingClientRect().top
    const elementRect = el.getBoundingClientRect().top
    const elementPosition = elementRect - bodyRect
    const offsetPosition = elementPosition - offset

    window.scrollTo({
      top: offsetPosition,
      behavior: 'smooth',
    })
  }
}

const startingPrice = computed(() => {
  const rules = venue.value?.pricing_rules || []
  if (rules.length === 0) return null
  return Math.min(...rules.map((r) => Number(r.price_per_hour)))
})

const activeCourtCount = computed(
  () => venue.value?.courts?.filter((c) => c.status === 'active').length ?? 0,
)

const selectedCourt = computed(() => {
  if (!venue.value?.courts || venue.value.courts.length === 0) return null
  return venue.value.courts[selectedCourtIndex.value] || venue.value.courts[0]
})

const hoursSummary = computed(() => {
  const hours = (venue.value?.operating_hours || []).filter((h) => !h.is_closed)
  if (hours.length === 0) return null
  const first = hours[0]!
  const uniform = hours.every(
    (h) => h.open_time === first.open_time && h.close_time === first.close_time,
  )
  if (!uniform) return null
  return `${first.open_time.slice(0, 5)} – ${first.close_time.slice(0, 5)}`
})

const estimatedRate = computed(() => {
  if (estimatorSlotType.value === 'peak') return 350000
  if (estimatorDayType.value === 'weekend') return 300000
  return 250000
})

const estimatedDiscount = computed(() => {
  return estimatedRate.value * 0.1 // OPENING10
})

const estimatedTotal = computed(() => {
  return estimatedRate.value - estimatedDiscount.value
})

const whatsappUrl = computed(() => {
  const raw = (venue.value?.phone || '').replace(/[^0-9]/g, '')
  if (!raw) return '#'
  return `https://wa.me/${raw.startsWith('0') ? `62${raw.slice(1)}` : raw}`
})

const mapsUrl = computed(() => {
  if (!venue.value) return '#'
  return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(
    `${venue.value.name} ${venue.value.address}`,
  )}`
})

const steps = [
  {
    step: '01',
    title: 'Pilih Jadwal & Lapangan',
    desc: 'Cek slot kosong real-time Lapangan 1 & 2. Bisa sewa 1 slot atau multi-slot turnamen.',
  },
  {
    step: '02',
    title: 'Isi Data & Klaim Promo',
    desc: 'Masukkan identitas pemesan & nomor WhatsApp aktif, gunakan kode voucher OPENING10.',
  },
  {
    step: '03',
    title: 'Transfer Bank Mandiri',
    desc: 'Lakukan transfer sesuai nominal tagihan dan unggah bukti transfer langsung pada sistem.',
  },
  {
    step: '04',
    title: 'E-Voucher & QR Masuk',
    desc: 'Setelah admin verifikasi, E-Voucher A4 dengan QR Check-In langsung aktif dan siap kirim WA.',
  },
]

const amenities = [
  {
    title: 'Semi-Indoor Court',
    desc: 'Terlindung dari hujan langsung dan terik matahari, sirkulasi udara optimal standar kompetisi.',
    badge: 'Kenyamanan',
  },
  {
    title: 'Rumput Sintetis Monofilament',
    desc: 'Ketahanan grip prima, pantulan bola presisi, meminimalkan risiko cedera sendi dan lutut.',
    badge: 'Spesifikasi FIP',
  },
  {
    title: 'Dinding Kaca Tempered 12mm',
    desc: 'Dinding kaca panoramik standar World Padel Tour dengan pantulan bola yang konsisten.',
    badge: 'Panoramik',
  },
  {
    title: 'Penerangan LED Anti-Silau',
    desc: 'Pencahayaan 8 titik floodlight merata untuk visibilitas malam hari tanpa bayangan tajam.',
    badge: 'Night Match',
  },
  {
    title: 'Rental Raket & Pro Shop',
    desc: 'Tersedia penyewaan raket resmi, bola baru padel, overgrip, serta aksesoris pelengkap.',
    badge: 'Lengkap',
  },
  {
    title: 'Shower, Cafe & Parkir',
    desc: 'Fasilitas ruang ganti, shower bersih, cafe santai, musholla, dan area parkir luas di Hertasning.',
    badge: 'Fasilitas',
  },
]

function formatIdr(amount: number | string): string {
  return new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
  }).format(Number(amount))
}

function formatCourtType(type: string): string {
  if (type === 'semi_indoor') return 'Semi Indoor'
  if (type === 'indoor') return 'Indoor'
  return 'Outdoor'
}

async function loadVenue(): Promise<void> {
  loading.value = true
  errorMessage.value = null
  try {
    const res = await apiRequest<Venue>('/venues/padel-makassar')
    venue.value = res.data
  } catch {
    try {
      const list = await apiRequest<Venue[]>('/venues')
      const first = list.data[0]
      if (first) {
        const res = await apiRequest<Venue>(`/venues/${encodeURIComponent(first.slug)}`)
        venue.value = res.data
      }
    } catch (err) {
      errorMessage.value = err instanceof ApiError ? err.message : 'Gagal memuat data venue.'
    }
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  loadVenue()
  window.addEventListener('scroll', handleScroll, { passive: true })
})

onUnmounted(() => {
  window.removeEventListener('scroll', handleScroll)
})
</script>

<template>
  <div class="relative space-y-20 pb-20">
    <!-- STICKY SCROLL SUB-NAVIGASI -->
    <nav
      v-if="!loading && venue"
      class="sticky top-20 z-20 mx-auto max-w-fit rounded-full border border-stone-200/80 bg-white/90 p-1.5 shadow-md backdrop-blur-md transition-all hidden md:flex items-center gap-1 text-xs font-bold"
    >
      <button
        type="button"
        class="rounded-full px-4 py-1.5 transition-all"
        :class="
          activeSection === 'hero'
            ? 'bg-terracotta-600 text-white shadow-xs'
            : 'text-stone-600 hover:text-stone-900'
        "
        @click="scrollToSection('hero')"
      >
        Ringkasan
      </button>
      <button
        type="button"
        class="rounded-full px-4 py-1.5 transition-all"
        :class="
          activeSection === 'lapangan'
            ? 'bg-terracotta-600 text-white shadow-xs'
            : 'text-stone-600 hover:text-stone-900'
        "
        @click="scrollToSection('lapangan')"
      >
        Eksplor Lapangan
      </button>
      <button
        type="button"
        class="rounded-full px-4 py-1.5 transition-all"
        :class="
          activeSection === 'tarif'
            ? 'bg-terracotta-600 text-white shadow-xs'
            : 'text-stone-600 hover:text-stone-900'
        "
        @click="scrollToSection('tarif')"
      >
        Estimasi Tarif
      </button>
      <button
        type="button"
        class="rounded-full px-4 py-1.5 transition-all"
        :class="
          activeSection === 'alur'
            ? 'bg-terracotta-600 text-white shadow-xs'
            : 'text-stone-600 hover:text-stone-900'
        "
        @click="scrollToSection('alur')"
      >
        Alur Booking
      </button>
      <button
        type="button"
        class="rounded-full px-4 py-1.5 transition-all"
        :class="
          activeSection === 'fasilitas'
            ? 'bg-terracotta-600 text-white shadow-xs'
            : 'text-stone-600 hover:text-stone-900'
        "
        @click="scrollToSection('fasilitas')"
      >
        Fasilitas
      </button>
      <button
        type="button"
        class="rounded-full px-4 py-1.5 transition-all"
        :class="
          activeSection === 'lokasi'
            ? 'bg-terracotta-600 text-white shadow-xs'
            : 'text-stone-600 hover:text-stone-900'
        "
        @click="scrollToSection('lokasi')"
      >
        Lokasi
      </button>
    </nav>

    <!-- LOADING & ERROR STATES -->
    <div
      v-if="loading"
      class="rounded-3xl border border-stone-200 bg-white p-16 text-center text-sm font-semibold text-stone-500 shadow-xs"
    >
      Memuat informasi venue PADEL MAKASSAR...
    </div>

    <div
      v-else-if="errorMessage || !venue"
      class="rounded-3xl border border-red-200 bg-red-50 p-8 text-sm font-semibold text-red-800"
    >
      {{ errorMessage || 'Data venue belum tersedia saat ini.' }}
    </div>

    <template v-else>
      <!-- 1. HERO SECTION (SCROLL CRAFT STAGE) -->
      <section
        id="hero"
        class="relative overflow-hidden rounded-3xl bg-linear-to-br from-stone-950 via-stone-900 to-[#2c1810] text-white shadow-xl"
      >
        <!-- Background geometric court lines -->
        <div class="absolute inset-0 pointer-events-none opacity-10">
          <svg class="w-full h-full" viewBox="0 0 800 400" preserveAspectRatio="none">
            <line x1="0" y1="200" x2="800" y2="200" stroke="#fff" stroke-width="2" />
            <line x1="400" y1="0" x2="400" y2="400" stroke="#fff" stroke-width="3" />
            <rect x="150" y="50" width="500" height="300" fill="none" stroke="#fff" stroke-width="2" />
          </svg>
        </div>

        <div class="relative grid gap-10 p-8 sm:p-12 lg:grid-cols-12 lg:items-center">
          <div class="lg:col-span-7 space-y-6">
            <div class="flex flex-wrap items-center gap-2.5">
              <span
                class="inline-flex items-center gap-1.5 rounded-full border border-terracotta-500/50 bg-terracotta-600/20 px-3.5 py-1 text-xs font-bold uppercase tracking-wider text-terracotta-300"
              >
                <span class="h-2 w-2 rounded-full bg-emerald-400 animate-pulse"></span>
                Venue Padel Resmi • Makassar
              </span>
              <span class="rounded-full bg-white/10 px-3 py-1 text-xs font-semibold text-stone-300">
                Standar Federasi Padel Internasional (FIP)
              </span>
            </div>

            <div class="space-y-2">
              <h1 class="text-4xl sm:text-6xl font-black tracking-tight leading-[1.05]">
                PADEL MAKASSAR
              </h1>
              <p class="text-base sm:text-lg font-medium text-stone-300 max-w-xl">
                Dua lapangan semi-indoor standar turnamen di pusat kota Makassar. Nikmati sensasi olahraga raket paling dinamis dengan proteksi anti bentrok jadwal real-time.
              </p>
            </div>

            <div class="flex flex-wrap items-center gap-3.5 pt-2">
              <RouterLink
                :to="{ name: 'booking' }"
                class="inline-flex items-center gap-2 rounded-xl bg-terracotta-600 px-6 py-3.5 text-sm font-extrabold text-white shadow-lg shadow-terracotta-900/40 hover:bg-terracotta-500 transition-all hover:scale-102"
              >
                <AppIcon name="calendar" class="w-4 h-4 text-white" />
                Booking Lapangan Sekarang
              </RouterLink>
              <button
                type="button"
                class="inline-flex items-center gap-2 rounded-xl border border-white/20 bg-white/5 px-5 py-3.5 text-sm font-bold text-white hover:bg-white/10 transition-all"
                @click="scrollToSection('lapangan')"
              >
                Lihat Spesifikasi Lapangan ↓
              </button>
            </div>
          </div>

          <!-- Hero Blueprint Visual -->
          <div class="lg:col-span-5">
            <div class="relative rounded-2xl border border-white/15 bg-white/5 p-4 backdrop-blur-xs">
              <div class="flex items-center justify-between pb-3 text-xs font-bold text-stone-300">
                <span>BLUEPRINT LAPANGAN RESMI</span>
                <span class="text-terracotta-400">20M × 10M</span>
              </div>
              <svg
                viewBox="0 0 220 120"
                class="w-full rounded-xl bg-terracotta-700/80 p-3 shadow-inner"
                role="img"
                aria-label="Diagram dimensi lapangan padel"
              >
                <rect x="10" y="10" width="200" height="100" fill="none" stroke="white" stroke-width="2" />
                <line x1="110" y1="6" x2="110" y2="114" stroke="white" stroke-width="2.5" />
                <line x1="40.5" y1="10" x2="40.5" y2="110" stroke="white" stroke-width="1.2" stroke-dasharray="2,2" />
                <line x1="179.5" y1="10" x2="179.5" y2="110" stroke="white" stroke-width="1.2" stroke-dasharray="2,2" />
                <line x1="40.5" y1="60" x2="179.5" y2="60" stroke="white" stroke-width="1.2" />
                <text x="110" y="65" fill="white" font-size="8" font-weight="bold" text-anchor="middle">NET</text>
              </svg>
              <div class="mt-3 grid grid-cols-2 gap-2 text-center text-xs">
                <div class="rounded-lg bg-black/30 p-2">
                  <p class="text-stone-400">Dinding Kaca</p>
                  <p class="font-bold text-white">12 mm Tempered</p>
                </div>
                <div class="rounded-lg bg-black/30 p-2">
                  <p class="text-stone-400">Permukaan</p>
                  <p class="font-bold text-white">WPT Turf Grip</p>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Metric Strips -->
        <div class="grid grid-cols-2 sm:grid-cols-4 border-t border-white/10 bg-black/25 backdrop-blur-xs">
          <div class="p-5 border-r border-white/10">
            <p class="text-xs font-semibold uppercase tracking-wider text-stone-400">Lapangan</p>
            <p class="mt-1 text-2xl font-black text-white">{{ activeCourtCount }} Lapangan Aktif</p>
          </div>
          <div class="p-5 sm:border-r border-white/10">
            <p class="text-xs font-semibold uppercase tracking-wider text-stone-400">Jam Operasional</p>
            <p class="mt-1 text-2xl font-black text-white">{{ hoursSummary || '07:00 – 23:00' }}</p>
          </div>
          <div class="p-5 border-r border-t sm:border-t-0 border-white/10">
            <p class="text-xs font-semibold uppercase tracking-wider text-stone-400">Tarif Mulai</p>
            <p class="mt-1 text-2xl font-black text-terracotta-400">
              {{ startingPrice !== null ? formatIdr(startingPrice) : 'Rp 250.000' }}
            </p>
          </div>
          <div class="p-5 border-t sm:border-t-0 border-white/10">
            <p class="text-xs font-semibold uppercase tracking-wider text-stone-400">Batas Hold Booking</p>
            <p class="mt-1 text-2xl font-black text-emerald-400">{{ venue.hold_duration_minutes }} Menit</p>
          </div>
        </div>
      </section>

      <!-- 2. INTERACTIVE COURT EXPLORER (SCROLL CRAFT SPOTLIGHT) -->
      <section id="lapangan" class="space-y-6 scroll-mt-24">
        <div class="flex flex-wrap items-end justify-between gap-4">
          <div>
            <span class="text-xs font-extrabold uppercase tracking-widest text-terracotta-600">
              Eksplorasi Fasilitas
            </span>
            <h2 class="mt-1 text-3xl font-black tracking-tight text-stone-900">
              Spesifikasi Lapangan Padel
            </h2>
          </div>
          <div class="inline-flex rounded-xl border border-stone-200 bg-white p-1 shadow-xs">
            <button
              v-for="(c, idx) in venue.courts"
              :key="c.id"
              type="button"
              class="rounded-lg px-4 py-2 text-xs font-extrabold transition-all"
              :class="
                selectedCourtIndex === idx
                  ? 'bg-terracotta-600 text-white shadow-xs'
                  : 'text-stone-600 hover:text-stone-900'
              "
              @click="selectedCourtIndex = idx"
            >
              {{ c.name }} ({{ c.code }})
            </button>
          </div>
        </div>

        <div v-if="selectedCourt" class="grid gap-6 lg:grid-cols-12">
          <!-- Court Interactive Card -->
          <div class="lg:col-span-7 rounded-3xl border border-stone-200 bg-white p-6 sm:p-8 shadow-xs space-y-6">
            <div class="flex items-center justify-between border-b border-stone-100 pb-4">
              <div>
                <span class="rounded-full bg-terracotta-100 px-3 py-1 text-xs font-bold text-terracotta-700">
                  Kode {{ selectedCourt.code }}
                </span>
                <h3 class="mt-2 text-2xl font-black text-stone-900">{{ selectedCourt.name }}</h3>
              </div>
              <span
                class="rounded-full px-3 py-1 text-xs font-bold"
                :class="
                  selectedCourt.status === 'active'
                    ? 'bg-emerald-50 text-emerald-700 border border-emerald-200'
                    : 'bg-amber-50 text-amber-700 border border-amber-200'
                "
              >
                {{ selectedCourt.status === 'active' ? 'Siap Digunakan' : 'Perawatan' }}
              </span>
            </div>

            <!-- Hotspot Diagram -->
            <div class="relative rounded-2xl bg-stone-950 p-6 text-white overflow-hidden">
              <div class="flex items-center justify-between text-xs font-bold text-stone-400 mb-4">
                <span>INTERACTIVE COURT HOTSPOTS (KLIK TITIK)</span>
                <span class="text-terracotta-400">{{ formatCourtType(selectedCourt.court_type) }}</span>
              </div>

              <!-- Court SVG with clickable hotspots -->
              <div class="relative">
                <svg viewBox="0 0 240 120" class="w-full rounded-xl bg-terracotta-700/80 p-2 shadow-inner">
                  <rect x="10" y="10" width="220" height="100" fill="none" stroke="white" stroke-width="2" />
                  <line x1="120" y1="6" x2="120" y2="114" stroke="white" stroke-width="2.5" />
                  <line x1="45" y1="10" x2="45" y2="110" stroke="white" stroke-width="1.2" stroke-dasharray="2,2" />
                  <line x1="195" y1="10" x2="195" y2="110" stroke="white" stroke-width="1.2" stroke-dasharray="2,2" />
                  <line x1="45" y1="60" x2="195" y2="60" stroke="white" stroke-width="1.2" />
                </svg>

                <!-- Hotspot 1: Glass -->
                <button
                  type="button"
                  class="absolute top-2 left-2 flex h-7 w-7 items-center justify-center rounded-full border-2 border-white transition-transform hover:scale-115"
                  :class="activeHotspot === 'glass' ? 'bg-amber-400 text-stone-950 ring-4 ring-amber-300/40' : 'bg-terracotta-600 text-white'"
                  title="Dinding Kaca"
                  @click="activeHotspot = 'glass'"
                >
                  1
                </button>

                <!-- Hotspot 2: Turf -->
                <button
                  type="button"
                  class="absolute top-1/2 left-1/4 -translate-y-1/2 flex h-7 w-7 items-center justify-center rounded-full border-2 border-white transition-transform hover:scale-115"
                  :class="activeHotspot === 'turf' ? 'bg-amber-400 text-stone-950 ring-4 ring-amber-300/40' : 'bg-terracotta-600 text-white'"
                  title="Rumput Sintetis"
                  @click="activeHotspot = 'turf'"
                >
                  2
                </button>

                <!-- Hotspot 3: Net -->
                <button
                  type="button"
                  class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 flex h-7 w-7 items-center justify-center rounded-full border-2 border-white transition-transform hover:scale-115"
                  :class="activeHotspot === 'net' ? 'bg-amber-400 text-stone-950 ring-4 ring-amber-300/40' : 'bg-terracotta-600 text-white'"
                  title="Jaring Net FIP"
                  @click="activeHotspot = 'net'"
                >
                  3
                </button>

                <!-- Hotspot 4: Lighting -->
                <button
                  type="button"
                  class="absolute top-2 right-4 flex h-7 w-7 items-center justify-center rounded-full border-2 border-white transition-transform hover:scale-115"
                  :class="activeHotspot === 'lighting' ? 'bg-amber-400 text-stone-950 ring-4 ring-amber-300/40' : 'bg-terracotta-600 text-white'"
                  title="Penerangan LED"
                  @click="activeHotspot = 'lighting'"
                >
                  4
                </button>
              </div>

              <!-- Hotspot Explanation Detail -->
              <div class="mt-4 rounded-xl border border-white/10 bg-white/5 p-4">
                <div v-if="activeHotspot === 'glass'" class="space-y-1">
                  <p class="text-xs font-bold text-amber-400 uppercase">Titik 1 • Dinding Tempered Glass 12mm</p>
                  <p class="text-sm font-semibold text-white">Kaca panoramik kokoh anti gores dengan pantulan bola realistis standar turnamen dunia.</p>
                </div>
                <div v-else-if="activeHotspot === 'turf'" class="space-y-1">
                  <p class="text-xs font-bold text-amber-400 uppercase">Titik 2 • Rumput Monofilament Padat</p>
                  <p class="text-sm font-semibold text-white">Traksi kaki optimal untuk pergerakan lateral cepat tanpa selip, menjaga kenyamanan engkel & lutut.</p>
                </div>
                <div v-else-if="activeHotspot === 'net'" class="space-y-1">
                  <p class="text-xs font-bold text-amber-400 uppercase">Titik 3 • Net Tengah Standar FIP</p>
                  <p class="text-sm font-semibold text-white">Ketinggian net 88 cm di bagian tengah dan 92 cm di kedua sisi ujung tiang penyangga baja.</p>
                </div>
                <div v-else class="space-y-1">
                  <p class="text-xs font-bold text-amber-400 uppercase">Titik 4 • Lampu LED Floodlight 8 Titik</p>
                  <p class="text-sm font-semibold text-white">Pencahayaan 500+ Lux merata ke seluruh sudut lapangan, nyaman untuk pertandingan malam hari.</p>
                </div>
              </div>
            </div>

            <div class="flex items-center justify-between pt-2">
              <span class="text-sm font-bold text-stone-600">
                Tipe Lapangan: <span class="text-stone-900">{{ formatCourtType(selectedCourt.court_type) }}</span>
              </span>
              <RouterLink
                :to="{ name: 'booking' }"
                class="inline-flex items-center gap-1.5 text-sm font-extrabold text-terracotta-600 hover:text-terracotta-700"
              >
                Pilih Tanggal & Jam di Lapangan Ini →
              </RouterLink>
            </div>
          </div>

          <!-- Feature Cards for this Court -->
          <div class="lg:col-span-5 flex flex-col justify-between gap-4">
            <div class="rounded-3xl border border-stone-200 bg-white p-6 shadow-xs space-y-4">
              <h4 class="text-sm font-extrabold uppercase tracking-wider text-stone-900">
                Kelebihan Lapangan Semi-Indoor
              </h4>
              <ul class="space-y-3 text-sm text-stone-600">
                <li class="flex items-start gap-2.5">
                  <span class="mt-0.5 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-700 text-xs font-bold">✓</span>
                  <span><strong>Jadwal Main Terjamin:</strong> Tidak terganggu hujan mendadak maupun angin kencang.</span>
                </li>
                <li class="flex items-start gap-2.5">
                  <span class="mt-0.5 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-700 text-xs font-bold">✓</span>
                  <span><strong>Sirkulasi Alami:</strong> Udara tetap sejuk dan tidak pengap dibandingkan indoor tertutup total.</span>
                </li>
                <li class="flex items-start gap-2.5">
                  <span class="mt-0.5 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-700 text-xs font-bold">✓</span>
                  <span><strong>Turnamen & Komunitas:</strong> Siap digunakan untuk sesi latihan, sparring, maupun kejuaraan resmi.</span>
                </li>
              </ul>
            </div>

            <div class="rounded-3xl bg-terracotta-50 border border-terracotta-200 p-6 space-y-3">
              <span class="text-xs font-bold uppercase tracking-wider text-terracotta-700">Kode Promo Pembukaan</span>
              <div class="flex items-center justify-between">
                <span class="font-mono text-xl font-black text-terracotta-800">OPENING10</span>
                <span class="rounded-lg bg-terracotta-600 px-2.5 py-1 text-xs font-extrabold text-white">Diskon 10%</span>
              </div>
              <p class="text-xs text-terracotta-700">
                Gunakan kode voucher ini pada halaman checkout untuk mendapatkan potongan harga langsung.
              </p>
            </div>
          </div>
        </div>
      </section>

      <!-- 3. ESTIMASI TARIF & KALKULATOR INTERAKTIF (SCROLL CRAFT) -->
      <section id="tarif" class="space-y-6 scroll-mt-24">
        <div>
          <span class="text-xs font-extrabold uppercase tracking-widest text-terracotta-600">
            Transparansi Biaya
          </span>
          <h2 class="mt-1 text-3xl font-black tracking-tight text-stone-900">
            Kalkulator Estimasi Tarif Sewa
          </h2>
        </div>

        <div class="grid gap-6 lg:grid-cols-12">
          <!-- Interactive Widget -->
          <div class="lg:col-span-7 rounded-3xl border border-stone-200 bg-white p-6 sm:p-8 shadow-xs space-y-6">
            <div class="space-y-2">
              <label class="text-xs font-bold uppercase tracking-wider text-stone-500">1. Pilih Hari Bermain</label>
              <div class="grid grid-cols-2 gap-3">
                <button
                  type="button"
                  class="rounded-xl border p-4 text-left transition-all"
                  :class="
                    estimatorDayType === 'weekday'
                      ? 'border-terracotta-600 bg-terracotta-50/60 ring-2 ring-terracotta-500/20'
                      : 'border-stone-200 hover:border-stone-300'
                  "
                  @click="estimatorDayType = 'weekday'"
                >
                  <p class="font-extrabold text-stone-900">Senin – Jumat</p>
                  <p class="text-xs text-stone-500">Tarif Reguler Kerja</p>
                </button>
                <button
                  type="button"
                  class="rounded-xl border p-4 text-left transition-all"
                  :class="
                    estimatorDayType === 'weekend'
                      ? 'border-terracotta-600 bg-terracotta-50/60 ring-2 ring-terracotta-500/20'
                      : 'border-stone-200 hover:border-stone-300'
                  "
                  @click="estimatorDayType = 'weekend'"
                >
                  <p class="font-extrabold text-stone-900">Sabtu & Minggu</p>
                  <p class="text-xs text-stone-500">Tarif Weekend</p>
                </button>
              </div>
            </div>

            <div class="space-y-2">
              <label class="text-xs font-bold uppercase tracking-wider text-stone-500">2. Pilih Jam Main</label>
              <div class="grid grid-cols-2 gap-3">
                <button
                  type="button"
                  class="rounded-xl border p-4 text-left transition-all"
                  :class="
                    estimatorSlotType === 'regular'
                      ? 'border-terracotta-600 bg-terracotta-50/60 ring-2 ring-terracotta-500/20'
                      : 'border-stone-200 hover:border-stone-300'
                  "
                  @click="estimatorSlotType = 'regular'"
                >
                  <p class="font-extrabold text-stone-900">07:00 – 16:00 WITA</p>
                  <p class="text-xs text-stone-500">Sesi Pagi & Siang Hari</p>
                </button>
                <button
                  type="button"
                  class="rounded-xl border p-4 text-left transition-all"
                  :class="
                    estimatorSlotType === 'peak'
                      ? 'border-terracotta-600 bg-terracotta-50/60 ring-2 ring-terracotta-500/20'
                      : 'border-stone-200 hover:border-stone-300'
                  "
                  @click="estimatorSlotType = 'peak'"
                >
                  <p class="font-extrabold text-stone-900">16:00 – 23:00 WITA</p>
                  <p class="text-xs text-stone-500">Peak Hours (Malam)</p>
                </button>
              </div>
            </div>

            <!-- Calculation Preview Box -->
            <div class="rounded-2xl bg-stone-950 p-6 text-white space-y-4">
              <div class="flex items-center justify-between border-b border-white/10 pb-3">
                <span class="text-sm text-stone-400">Tarif Normal per Jam</span>
                <span class="font-bold text-white">{{ formatIdr(estimatedRate) }}</span>
              </div>
              <div class="flex items-center justify-between border-b border-white/10 pb-3">
                <span class="text-sm text-emerald-400 flex items-center gap-1.5">
                  <AppIcon name="tag" class="w-4 h-4 text-emerald-400" />
                  Diskon Voucher OPENING10 (10%)
                </span>
                <span class="font-bold text-emerald-400">-{{ formatIdr(estimatedDiscount) }}</span>
              </div>
              <div class="flex items-center justify-between pt-1">
                <div>
                  <p class="text-xs uppercase tracking-wider text-stone-400">Total Per Jam</p>
                  <p class="text-2xl font-black text-terracotta-400">{{ formatIdr(estimatedTotal) }}</p>
                </div>
                <RouterLink
                  :to="{ name: 'booking' }"
                  class="rounded-xl bg-terracotta-600 px-5 py-3 text-xs font-black text-white hover:bg-terracotta-500 transition-all"
                >
                  Pesan Jam Ini Sekarang →
                </RouterLink>
              </div>
            </div>
          </div>

          <!-- Official Tariff Table -->
          <div class="lg:col-span-5 rounded-3xl border border-stone-200 bg-white p-6 sm:p-8 shadow-xs space-y-4">
            <h3 class="text-sm font-extrabold uppercase tracking-wider text-stone-900">
              Daftar Tarif Resmi Venue
            </h3>
            <div class="divide-y divide-stone-100">
              <div
                v-for="rule in venue.pricing_rules"
                :key="rule.id"
                class="flex items-center justify-between py-3.5"
              >
                <div>
                  <p class="text-sm font-extrabold text-stone-900">{{ rule.name }}</p>
                  <p class="text-xs text-stone-500">
                    {{ rule.start_time.slice(0, 5) }} – {{ rule.end_time.slice(0, 5) }} WITA
                    <span v-if="rule.day_of_week !== null"> • {{ dayNames[rule.day_of_week] }}</span>
                  </p>
                </div>
                <span class="text-sm font-black text-terracotta-700">
                  {{ formatIdr(rule.price_per_hour) }}
                </span>
              </div>
            </div>

            <div class="rounded-xl bg-stone-50 p-4 border border-stone-200 text-xs text-stone-600 space-y-1">
              <p class="font-bold text-stone-900">Kebijakan Reschedule & Batal:</p>
              <p>Reschedule mandiri dapat dilakukan maksimal 6 jam sebelum jam bermain dimulai melalui menu Cek Pesanan.</p>
            </div>
          </div>
        </div>
      </section>

      <!-- 4. ALUR BOOKING (SCROLL CRAFT PROGRESS TIMELINE) -->
      <section id="alur" class="rounded-3xl border border-stone-200 bg-white p-8 sm:p-12 shadow-xs space-y-8 scroll-mt-24">
        <div class="text-center max-w-xl mx-auto space-y-2">
          <span class="text-xs font-extrabold uppercase tracking-widest text-terracotta-600">
            Alur Praktis Tanpa Antre
          </span>
          <h2 class="text-3xl font-black tracking-tight text-stone-900">
            4 Langkah Mudah Booking
          </h2>
        </div>

        <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-4 relative">
          <div
            v-for="(st, idx) in steps"
            :key="st.step"
            class="relative rounded-2xl border border-stone-100 bg-[#FAF8F6] p-6 space-y-3 transition-transform hover:-translate-y-1"
          >
            <div class="flex items-center justify-between">
              <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-terracotta-600 text-sm font-black text-white shadow-xs">
                {{ st.step }}
              </span>
              <span class="text-xs font-bold text-stone-400">Step {{ idx + 1 }}/4</span>
            </div>
            <h3 class="text-base font-extrabold text-stone-900">{{ st.title }}</h3>
            <p class="text-xs leading-relaxed text-stone-600">{{ st.desc }}</p>
          </div>
        </div>
      </section>

      <!-- 5. FASILITAS VENUE (GRID AMENITIES) -->
      <section id="fasilitas" class="space-y-6 scroll-mt-24">
        <div>
          <span class="text-xs font-extrabold uppercase tracking-widest text-terracotta-600">
            Kenyamanan Pemain
          </span>
          <h2 class="mt-1 text-3xl font-black tracking-tight text-stone-900">
            Fasilitas Padel Makassar
          </h2>
        </div>

        <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
          <div
            v-for="item in amenities"
            :key="item.title"
            class="rounded-3xl border border-stone-200 bg-white p-6 shadow-xs space-y-3 hover:border-terracotta-300 transition-colors"
          >
            <span class="inline-block rounded-md bg-terracotta-100 px-2.5 py-1 text-xs font-bold text-terracotta-700">
              {{ item.badge }}
            </span>
            <h3 class="text-lg font-extrabold text-stone-900">{{ item.title }}</h3>
            <p class="text-xs leading-relaxed text-stone-600">{{ item.desc }}</p>
          </div>
        </div>
      </section>

      <!-- 6. LOKASI & KONTAK -->
      <section id="lokasi" class="grid gap-6 lg:grid-cols-12 scroll-mt-24">
        <div class="lg:col-span-7 rounded-3xl border border-stone-200 bg-white p-8 shadow-xs space-y-4">
          <span class="text-xs font-extrabold uppercase tracking-widest text-terracotta-600">Lokasi Venue</span>
          <h3 class="text-2xl font-black text-stone-900">Jl. Letjen Hertasning No.2, Makassar</h3>
          <p class="text-sm text-stone-600">
            Lokasi strategis di pusat aktivitas kota Makassar (Kec. Panakkukang), mudah diakses dengan area parkir roda empat dan roda dua yang aman.
          </p>
          <div class="pt-2">
            <a
              :href="mapsUrl"
              target="_blank"
              rel="noopener noreferrer"
              class="inline-flex items-center gap-2 rounded-xl bg-stone-900 px-5 py-3 text-xs font-bold text-white hover:bg-stone-800 transition-all"
            >
              <AppIcon name="external" class="w-4 h-4 text-white" />
              Petunjuk Arah Google Maps
            </a>
          </div>
        </div>

        <div class="lg:col-span-5 rounded-3xl bg-terracotta-600 text-white p-8 shadow-xs space-y-4 flex flex-col justify-between">
          <div class="space-y-2">
            <span class="text-xs font-extrabold uppercase tracking-widest text-terracotta-200">Customer Support</span>
            <h3 class="text-2xl font-black">Bantuan & Reservasi</h3>
            <p class="text-sm text-terracotta-100">
              Ada pertanyaan seputar turnamen, sewa raket, atau kendala transfer? Hubungi tim admin operasional kami.
            </p>
          </div>
          <div class="pt-4">
            <a
              :href="whatsappUrl"
              target="_blank"
              rel="noopener noreferrer"
              class="inline-flex items-center gap-2 rounded-xl bg-white px-5 py-3 text-xs font-extrabold text-terracotta-700 shadow-md hover:bg-stone-100 transition-all"
            >
              Chat WhatsApp (0823-4783-8252)
            </a>
          </div>
        </div>
      </section>

      <!-- 7. FLOATING QUICK-BOOKING ACTION BAR (SCROLL CRAFT ELEMENT) -->
      <transition
        enter-active-class="transition duration-300 ease-out"
        enter-from-class="translate-y-full opacity-0"
        enter-to-class="translate-y-0 opacity-100"
        leave-active-class="transition duration-200 ease-in"
        leave-from-class="translate-y-0 opacity-100"
        leave-to-class="translate-y-full opacity-0"
      >
        <div
          v-if="scrollY > 400"
          class="fixed bottom-4 inset-x-4 z-40 mx-auto max-w-4xl rounded-2xl border border-stone-800 bg-stone-950/95 p-3.5 text-white shadow-2xl backdrop-blur-md flex items-center justify-between gap-4"
        >
          <div class="hidden sm:flex items-center gap-3">
            <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-terracotta-600 text-sm font-black">
              PM
            </span>
            <div>
              <p class="text-xs font-black tracking-tight">PADEL MAKASSAR</p>
              <p class="text-[11px] text-stone-400">2 Lapangan Semi-Indoor • 07:00–23:00 WITA</p>
            </div>
          </div>

          <div class="flex items-center justify-between sm:justify-end w-full sm:w-auto gap-4">
            <div class="text-left sm:text-right">
              <span class="text-[10px] uppercase font-bold text-terracotta-400">Tarif Mulai</span>
              <p class="text-sm font-black text-white">
                {{ startingPrice !== null ? formatIdr(startingPrice) : 'Rp 250.000' }}/jam
              </p>
            </div>
            <RouterLink
              :to="{ name: 'booking' }"
              class="rounded-xl bg-terracotta-600 px-5 py-2.5 text-xs font-black text-white hover:bg-terracotta-500 transition-all shadow-md"
            >
              Booking Sekarang →
            </RouterLink>
          </div>
        </div>
      </transition>
    </template>
  </div>
</template>
