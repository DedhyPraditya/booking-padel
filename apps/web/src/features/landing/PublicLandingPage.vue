<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import type { Venue } from '@/types/api'
import { ApiError, apiRequest } from '@/services/apiClient'
import AppIcon from '@/components/ui/AppIcon.vue'

const venue = ref<Venue | null>(null)
const loading = ref(true)
const errorMessage = ref<string | null>(null)

// Interactive Court Explorer State
const selectedCourtIndex = ref(0)
const activeHotspot = ref<'glass' | 'turf' | 'lighting' | 'net'>('glass')

const hotspotDetails: Record<
  'glass' | 'turf' | 'lighting' | 'net',
  { title: string; desc: string }
> = {
  glass: {
    title: '1. Dinding Tempered Glass 12mm',
    desc: 'Kaca panoramik kokoh anti gores dengan pantulan bola realistis standar turnamen dunia.',
  },
  turf: {
    title: '2. Rumput Monofilament WPT',
    desc: 'Traksi kaki optimal untuk pergerakan lateral cepat tanpa selip, menjaga kenyamanan engkel & lutut.',
  },
  net: {
    title: '3. Net Tengah Standar FIP',
    desc: 'Ketinggian net 88 cm di bagian tengah dan 92 cm di kedua sisi ujung tiang penyangga baja.',
  },
  lighting: {
    title: '4. Floodlight LED 8 Titik',
    desc: 'Pencahayaan 500+ Lux merata ke seluruh sudut lapangan, nyaman untuk pertandingan malam hari.',
  },
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
    desc: 'Cek kalender ketersediaan slot real-time Lapangan 1 & Lapangan 2.',
  },
  {
    step: '02',
    title: 'Isi Data & Klaim Promo',
    desc: 'Isi nama dan nomor WhatsApp pemesan, masukkan kode promo OPENING10.',
  },
  {
    step: '03',
    title: 'Transfer Bank Mandiri',
    desc: 'Kirim pembayaran sesuai invoice lalu unggah bukti transfer di sistem.',
  },
  {
    step: '04',
    title: 'Terima E-Voucher & QR',
    desc: 'Admin verifikasi pembayaran, E-Voucher resmi A4 dengan QR Check-In langsung terbit.',
  },
]

const amenities = [
  {
    title: 'Semi-Indoor Court',
    desc: 'Bebas dari hujan langsung dan terik matahari, sirkulasi udara optimal standar kompetisi.',
    tag: 'Struktur',
  },
  {
    title: 'Rumput Monofilament WPT',
    desc: 'Grip stabil dengan pantulan bola presisi, meminimalkan resiko cedera sendi dan lutut.',
    tag: 'Permukaan',
  },
  {
    title: 'Dinding Tempered Glass 12mm',
    desc: 'Kaca panoramik kokoh standar internasional untuk pantulan bola yang konsisten.',
    tag: 'Keamanan',
  },
  {
    title: 'Floodlight LED Anti-Silau',
    desc: 'Pencahayaan 8 titik floodlight merata 500+ Lux untuk kenyamanan match malam hari.',
    tag: 'Pencahayaan',
  },
  {
    title: 'Pro Shop & Rental Raket',
    desc: 'Sewa raket padel resmi, bola bertekanan standar, overgrip, dan perlengkapan lainnya.',
    tag: 'Peralatan',
  },
  {
    title: 'Shower, Cafe & Parkir Luas',
    desc: 'Kamar ganti bersih, shower air segar, lounge cafe, musholla, dan parkir mobil/motor aman.',
    tag: 'Fasilitas',
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

onMounted(loadVenue)
</script>

<template>
  <div class="space-y-16 pb-12">
    <!-- SMOOTH SKELETON LOADING -->
    <div v-if="loading" class="space-y-8 animate-pulse">
      <div class="h-96 rounded-3xl bg-stone-200/80"></div>
      <div class="grid gap-6 lg:grid-cols-12">
        <div class="lg:col-span-8 h-80 rounded-3xl bg-stone-200/70"></div>
        <div class="lg:col-span-4 h-80 rounded-3xl bg-stone-200/70"></div>
      </div>
    </div>

    <div
      v-else-if="errorMessage || !venue"
      class="rounded-3xl border border-red-200 bg-red-50 p-8 text-sm font-semibold text-red-800"
    >
      {{ errorMessage || 'Data venue belum tersedia saat ini.' }}
    </div>

    <template v-else>
      <!-- 1. HERO SECTION (SPORTY & CLEAN) -->
      <section
        v-reveal
        class="relative overflow-hidden rounded-3xl bg-linear-to-br from-stone-950 via-stone-900 to-[#27140e] text-white shadow-xl"
      >
        <div class="relative p-8 sm:p-12 lg:p-16 space-y-8">
          <div class="max-w-3xl space-y-4">
            <h1 class="text-4xl sm:text-6xl font-black tracking-tight leading-[1.08]">
              PADEL MAKASSAR
            </h1>
            <p class="text-base sm:text-lg text-stone-300 font-medium leading-relaxed max-w-2xl">
              Pusat olahraga padel pertama di Makassar dengan 2 lapangan semi-indoor berstandar kompetisi. Didukung sistem booking online anti bentrok jadwal real-time.
            </p>
          </div>

          <div class="flex flex-wrap items-center gap-4 pt-2">
            <RouterLink
              :to="{ name: 'booking' }"
              class="btn-tactile inline-flex items-center gap-2.5 rounded-xl bg-terracotta-600 px-7 py-3.5 text-sm font-extrabold text-white shadow-lg shadow-terracotta-900/50 hover:bg-terracotta-500 hover:-translate-y-0.5"
            >
              <AppIcon name="calendar" class="w-4 h-4 text-white" />
              Booking Lapangan Sekarang
            </RouterLink>
            <RouterLink
              :to="{ name: 'booking-lookup' }"
              class="btn-tactile inline-flex items-center gap-2 rounded-xl border border-white/20 bg-white/5 px-6 py-3.5 text-sm font-bold text-white hover:bg-white/10"
            >
              Cek Pesanan Saya
            </RouterLink>
          </div>

          <!-- Highlight Bar -->
          <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 pt-6 border-t border-white/10">
            <div>
              <p class="text-xs uppercase font-semibold text-stone-400">Fasilitas Lapangan</p>
              <p class="text-xl sm:text-2xl font-black text-white mt-1">{{ activeCourtCount }} Lapangan Aktif</p>
            </div>
            <div>
              <p class="text-xs uppercase font-semibold text-stone-400">Jam Operasional</p>
              <p class="text-xl sm:text-2xl font-black text-white mt-1">{{ hoursSummary || '07:00 – 23:00' }} WITA</p>
            </div>
            <div>
              <p class="text-xs uppercase font-semibold text-stone-400">Tarif Mulai</p>
              <p class="text-xl sm:text-2xl font-black text-terracotta-400 mt-1">
                {{ startingPrice !== null ? formatIdr(startingPrice) : 'Rp 250.000' }}/jam
              </p>
            </div>
            <div>
              <p class="text-xs uppercase font-semibold text-stone-400">Batas Hold Booking</p>
              <p class="text-xl sm:text-2xl font-black text-emerald-400 mt-1">{{ venue.hold_duration_minutes }} Menit</p>
            </div>
          </div>
        </div>
      </section>

      <!-- 2. SPESIFIKASI LAPANGAN (INTERACTIVE BLUEPRINT SINGLE STAGE) -->
      <section v-reveal="80" class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
          <div>
            <h2 class="text-3xl font-black tracking-tight text-stone-900">
              Standar Federasi Padel Internasional (20m × 10m)
            </h2>
          </div>
          <div class="inline-flex rounded-xl border border-stone-200 bg-white p-1 shadow-xs">
            <button
              v-for="(c, idx) in venue.courts"
              :key="c.id"
              type="button"
              class="btn-tactile rounded-lg px-4 py-2 text-xs font-extrabold"
              :class="
                selectedCourtIndex === idx
                  ? 'bg-terracotta-600 text-white shadow-xs'
                  : 'text-stone-600 hover:text-stone-900 hover:bg-stone-50'
              "
              @click="selectedCourtIndex = idx"
            >
              {{ c.name }} (Kode: {{ c.code }})
            </button>
          </div>
        </div>

        <div v-if="selectedCourt" class="grid gap-6 lg:grid-cols-12">
          <!-- Left: Blueprint with interactive clickable spots -->
          <div class="lg:col-span-8 rounded-3xl border border-stone-200 bg-white p-6 sm:p-8 shadow-xs space-y-6">
            <Transition name="fade-slide" mode="out-in">
              <div
                :key="selectedCourt.id"
                class="flex items-center justify-between border-b border-stone-100 pb-4"
              >
                <div>
                  <span class="rounded-full bg-terracotta-100 px-3 py-1 text-xs font-bold text-terracotta-700">
                    Kode Lapangan: {{ selectedCourt.code }}
                  </span>
                  <h3 class="mt-2 text-2xl font-black text-stone-900">{{ selectedCourt.name }}</h3>
                </div>
                <span
                  class="rounded-full px-3 py-1 text-xs font-bold border"
                  :class="
                    selectedCourt.status === 'active'
                      ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
                      : 'bg-amber-50 text-amber-700 border-amber-200'
                  "
                >
                  {{ selectedCourt.status === 'active' ? 'Tersedia untuk Booking' : 'Dalam Perawatan' }}
                </span>
              </div>
            </Transition>

            <!-- Single Blueprint Visual -->
            <div class="relative rounded-2xl bg-stone-950 p-6 text-white overflow-hidden">
              <div class="flex items-center justify-between text-xs font-bold text-stone-400 mb-4">
                <span>KLIK TITIK NOMOR UNTUK MELIHAT SPESIFIKASI</span>
                <span class="text-terracotta-400">{{ formatCourtType(selectedCourt.court_type) }}</span>
              </div>

              <div class="relative">
                <svg viewBox="0 0 240 120" class="w-full rounded-xl bg-terracotta-700/80 p-2 shadow-inner">
                  <rect
                    x="10"
                    y="10"
                    width="220"
                    height="100"
                    fill="none"
                    stroke="white"
                    stroke-width="2"
                    class="court-line-draw"
                  />
                  <line x1="120" y1="6" x2="120" y2="114" stroke="white" stroke-width="2.5" />
                  <line x1="45" y1="10" x2="45" y2="110" stroke="white" stroke-width="1.2" stroke-dasharray="2,2" />
                  <line x1="195" y1="10" x2="195" y2="110" stroke="white" stroke-width="1.2" stroke-dasharray="2,2" />
                  <line x1="45" y1="60" x2="195" y2="60" stroke="white" stroke-width="1.2" />
                </svg>

                <!-- Hotspot 1: Glass -->
                <button
                  type="button"
                  class="btn-tactile absolute top-2 left-2 flex h-7 w-7 items-center justify-center rounded-full border-2 border-white text-xs font-bold hover:scale-110"
                  :class="
                    activeHotspot === 'glass'
                      ? 'bg-amber-400 text-stone-950 ring-4 ring-amber-300/40 scale-110'
                      : 'bg-terracotta-600 text-white'
                  "
                  title="Dinding Kaca"
                  @click="activeHotspot = 'glass'"
                >
                  1
                </button>

                <!-- Hotspot 2: Turf -->
                <button
                  type="button"
                  class="btn-tactile absolute top-1/2 left-1/4 -translate-y-1/2 flex h-7 w-7 items-center justify-center rounded-full border-2 border-white text-xs font-bold hover:scale-110"
                  :class="
                    activeHotspot === 'turf'
                      ? 'bg-amber-400 text-stone-950 ring-4 ring-amber-300/40 scale-110'
                      : 'bg-terracotta-600 text-white'
                  "
                  title="Rumput Sintetis"
                  @click="activeHotspot = 'turf'"
                >
                  2
                </button>

                <!-- Hotspot 3: Net -->
                <button
                  type="button"
                  class="btn-tactile absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 flex h-7 w-7 items-center justify-center rounded-full border-2 border-white text-xs font-bold hover:scale-110"
                  :class="
                    activeHotspot === 'net'
                      ? 'bg-amber-400 text-stone-950 ring-4 ring-amber-300/40 scale-110'
                      : 'bg-terracotta-600 text-white'
                  "
                  title="Jaring Net"
                  @click="activeHotspot = 'net'"
                >
                  3
                </button>

                <!-- Hotspot 4: Lighting -->
                <button
                  type="button"
                  class="btn-tactile absolute top-2 right-4 flex h-7 w-7 items-center justify-center rounded-full border-2 border-white text-xs font-bold hover:scale-110"
                  :class="
                    activeHotspot === 'lighting'
                      ? 'bg-amber-400 text-stone-950 ring-4 ring-amber-300/40 scale-110'
                      : 'bg-terracotta-600 text-white'
                  "
                  title="Penerangan LED"
                  @click="activeHotspot = 'lighting'"
                >
                  4
                </button>
              </div>

              <!-- Animated Hotspot Info Box -->
              <div class="mt-4 rounded-xl border border-white/10 bg-white/5 p-4 min-h-[76px]">
                <Transition name="fade-slide" mode="out-in">
                  <div :key="activeHotspot" class="space-y-1">
                    <p class="text-xs font-bold text-amber-400 uppercase">
                      {{ hotspotDetails[activeHotspot].title }}
                    </p>
                    <p class="text-sm font-semibold text-white">
                      {{ hotspotDetails[activeHotspot].desc }}
                    </p>
                  </div>
                </Transition>
              </div>
            </div>

            <div class="flex items-center justify-between pt-2">
              <span class="text-xs text-stone-500 font-semibold">
                Konstruksi: Semi-Indoor (Atap Lindung + Ventilasi Terbuka)
              </span>
              <RouterLink
                :to="{ name: 'booking' }"
                class="group inline-flex items-center gap-1.5 text-sm font-extrabold text-terracotta-600 hover:text-terracotta-700"
              >
                <span>Pilih Jadwal di {{ selectedCourt.name }}</span>
                <span class="transition-transform duration-200 group-hover:translate-x-1">→</span>
              </RouterLink>
            </div>
          </div>

          <!-- Right: Key Advantages & Promo -->
          <div class="lg:col-span-4 flex flex-col justify-between gap-4">
            <div class="card-interactive rounded-3xl border border-stone-200 bg-white p-6 shadow-xs space-y-4">
              <h4 class="text-xs font-extrabold uppercase tracking-wider text-stone-900">
                Keunggulan Lapangan Padel Makassar
              </h4>
              <ul class="space-y-3 text-xs leading-relaxed text-stone-600">
                <li class="flex items-start gap-2.5">
                  <span class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-700 text-xs font-bold">✓</span>
                  <span><strong>Jadwal Main Aman:</strong> Bebas basah hujan dan tidak silau sinar matahari langsung.</span>
                </li>
                <li class="flex items-start gap-2.5">
                  <span class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-700 text-xs font-bold">✓</span>
                  <span><strong>Sirkulasi Udara Alami:</strong> Hawa tetap sejuk dan tidak pengap.</span>
                </li>
                <li class="flex items-start gap-2.5">
                  <span class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-700 text-xs font-bold">✓</span>
                  <span><strong>Multi-Slot Turnamen:</strong> Siap sewa multi-jam atau gabung 2 lapangan untuk event komunitas.</span>
                </li>
              </ul>
            </div>

            <div class="card-interactive rounded-3xl bg-terracotta-50 border border-terracotta-200 p-6 space-y-3">
              <span class="text-xs font-bold uppercase tracking-wider text-terracotta-700">Promo Pembukaan</span>
              <div class="flex items-center justify-between">
                <span class="font-mono text-xl font-black text-terracotta-800">OPENING10</span>
                <span class="rounded-lg bg-terracotta-600 px-2.5 py-1 text-xs font-extrabold text-white">Diskon 10%</span>
              </div>
              <p class="text-xs text-terracotta-700 leading-relaxed">
                Klaim potongan harga 10% pada saat mengisi data pemesanan di halaman booking.
              </p>
            </div>
          </div>
        </div>
      </section>

      <!-- 3. TARIF SEWA TRANSPARAN (3-TIER CLEAN CARDS) -->
      <section class="space-y-6">
        <div v-reveal>
          <h2 class="text-3xl font-black tracking-tight text-stone-900">
            Tarif Sewa Lapangan Per Jam
          </h2>
        </div>

        <div class="grid gap-6 sm:grid-cols-3">
          <!-- Tier 1: Weekday Regular -->
          <div
            v-reveal="40"
            class="card-interactive rounded-3xl border border-stone-200 bg-white p-6 shadow-xs space-y-4 flex flex-col justify-between"
          >
            <div class="space-y-2">
              <span class="text-xs font-bold uppercase tracking-wider text-stone-500">Senin – Jumat</span>
              <h3 class="text-lg font-black text-stone-900">Weekday Reguler</h3>
              <p class="text-xs text-stone-500">Sesi Pagi & Siang (07:00 – 16:00 WITA)</p>
              <div class="pt-2">
                <span class="text-3xl font-black text-stone-900">Rp 250.000</span>
                <span class="text-xs text-stone-500"> / jam</span>
              </div>
            </div>
            <RouterLink
              :to="{ name: 'booking' }"
              class="btn-tactile w-full text-center rounded-xl border border-stone-200 bg-stone-50 py-2.5 text-xs font-bold text-stone-800 hover:bg-stone-100 hover:border-stone-300"
            >
              Pilih Jam Weekday
            </RouterLink>
          </div>

          <!-- Tier 2: Weekend Regular -->
          <div
            v-reveal="120"
            class="card-interactive rounded-3xl border border-stone-200 bg-white p-6 shadow-xs space-y-4 flex flex-col justify-between"
          >
            <div class="space-y-2">
              <span class="text-xs font-bold uppercase tracking-wider text-stone-500">Sabtu & Minggu</span>
              <h3 class="text-lg font-black text-stone-900">Weekend Reguler</h3>
              <p class="text-xs text-stone-500">Sesi Pagi & Siang (07:00 – 16:00 WITA)</p>
              <div class="pt-2">
                <span class="text-3xl font-black text-stone-900">Rp 300.000</span>
                <span class="text-xs text-stone-500"> / jam</span>
              </div>
            </div>
            <RouterLink
              :to="{ name: 'booking' }"
              class="btn-tactile w-full text-center rounded-xl border border-stone-200 bg-stone-50 py-2.5 text-xs font-bold text-stone-800 hover:bg-stone-100 hover:border-stone-300"
            >
              Pilih Jam Weekend
            </RouterLink>
          </div>

          <!-- Tier 3: Peak Hours (Highlighted) -->
          <div
            v-reveal="200"
            class="card-interactive rounded-3xl border-2 border-terracotta-600 bg-terracotta-50/40 p-6 shadow-md space-y-4 flex flex-col justify-between"
          >
            <div class="space-y-2">
              <span class="inline-block rounded-md bg-terracotta-600 px-2 py-0.5 text-[10px] font-black uppercase tracking-wider text-white">
                Paling Favorit
              </span>
              <h3 class="text-lg font-black text-stone-900">Peak Hours (Malam)</h3>
              <p class="text-xs text-stone-600">Setiap Hari (16:00 – 23:00 WITA)</p>
              <div class="pt-2">
                <span class="text-3xl font-black text-terracotta-700">Rp 350.000</span>
                <span class="text-xs text-stone-500"> / jam</span>
              </div>
            </div>
            <RouterLink
              :to="{ name: 'booking' }"
              class="btn-tactile w-full text-center rounded-xl bg-terracotta-600 py-2.5 text-xs font-black text-white hover:bg-terracotta-700 shadow-sm"
            >
              Booking Peak Hours
            </RouterLink>
          </div>
        </div>
      </section>

      <!-- 4. ALUR BOOKING PRAKTIS -->
      <section
        v-reveal
        class="rounded-3xl border border-stone-200 bg-white p-8 sm:p-12 shadow-xs space-y-8"
      >
        <div class="text-center max-w-xl mx-auto space-y-1">
          <span class="text-xs font-extrabold uppercase tracking-widest text-terracotta-600">
            Alur Pemesanan
          </span>
          <h2 class="text-3xl font-black tracking-tight text-stone-900">
            4 Langkah Mudah Booking
          </h2>
        </div>

        <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
          <div
            v-for="(st, idx) in steps"
            :key="st.step"
            v-reveal="idx * 70"
            class="card-interactive rounded-2xl border border-stone-100 bg-[#FAF8F6] p-6 space-y-3 hover:border-terracotta-200"
          >
            <div class="flex items-center justify-between">
              <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-terracotta-600 text-sm font-black text-white">
                {{ st.step }}
              </span>
              <span class="text-xs font-bold text-stone-400">Step {{ idx + 1 }}/4</span>
            </div>
            <h3 class="text-base font-extrabold text-stone-900">{{ st.title }}</h3>
            <p class="text-xs leading-relaxed text-stone-600">{{ st.desc }}</p>
          </div>
        </div>
      </section>

      <!-- 5. FASILITAS VENUE -->
      <section class="space-y-6">
        <div v-reveal>
          <h2 class="text-3xl font-black tracking-tight text-stone-900">
            Fasilitas Padel Makassar
          </h2>
        </div>

        <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
          <div
            v-for="(item, idx) in amenities"
            :key="item.title"
            v-reveal="(idx % 3) * 70"
            class="card-interactive rounded-3xl border border-stone-200 bg-white p-6 shadow-xs space-y-2 hover:border-terracotta-300"
          >
            <span class="inline-block rounded-md bg-terracotta-100 px-2.5 py-0.5 text-xs font-bold text-terracotta-700">
              {{ item.tag }}
            </span>
            <h3 class="text-base font-extrabold text-stone-900">{{ item.title }}</h3>
            <p class="text-xs leading-relaxed text-stone-600">{{ item.desc }}</p>
          </div>
        </div>
      </section>

      <!-- 6. LOKASI & KONTAK -->
      <section class="grid gap-6 lg:grid-cols-12">
        <div
          v-reveal="40"
          class="card-interactive lg:col-span-7 rounded-3xl border border-stone-200 bg-white p-8 shadow-xs space-y-4"
        >
          <h3 class="text-2xl font-black text-stone-900">Jl. Letjen Hertasning No.2, Makassar</h3>
          <p class="text-sm text-stone-600 leading-relaxed">
            Terletak strategis di pusat kota Makassar (Kec. Panakkukang), mudah dijangkau dengan area parkir kendaraan yang aman dan luas.
          </p>
          <div class="pt-2">
            <a
              :href="mapsUrl"
              target="_blank"
              rel="noopener noreferrer"
              class="btn-tactile inline-flex items-center gap-2 rounded-xl bg-stone-900 px-5 py-3 text-xs font-bold text-white hover:bg-stone-800"
            >
              <AppIcon name="external" class="w-4 h-4 text-white" />
              Petunjuk Arah Google Maps
            </a>
          </div>
        </div>

        <div
          v-reveal="120"
          class="card-interactive lg:col-span-5 rounded-3xl bg-terracotta-600 text-white p-8 shadow-xs space-y-4 flex flex-col justify-between"
        >
          <div class="space-y-2">
            <span class="text-xs font-extrabold uppercase tracking-widest text-terracotta-200">Bantuan & Reservasi</span>
            <h3 class="text-2xl font-black">Kontak Operasional</h3>
            <p class="text-sm text-terracotta-100 leading-relaxed">
              Hubungi staf kami untuk pertanyaan turnamen, sewa raket, atau konfirmasi bukti transfer manual.
            </p>
          </div>
          <div class="pt-4">
            <a
              :href="whatsappUrl"
              target="_blank"
              rel="noopener noreferrer"
              class="btn-tactile inline-flex items-center gap-2 rounded-xl bg-white px-5 py-3 text-xs font-extrabold text-terracotta-700 shadow-md hover:bg-stone-100"
            >
              Chat WhatsApp (0823-4783-8252)
            </a>
          </div>
        </div>
      </section>
    </template>
  </div>
</template>
