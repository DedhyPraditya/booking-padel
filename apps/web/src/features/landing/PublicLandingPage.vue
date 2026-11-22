<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import type { Venue } from '@/types/api'
import { ApiError, apiRequest } from '@/services/apiClient'
import AppIcon from '@/components/ui/AppIcon.vue'

const venue = ref<Venue | null>(null)
const loading = ref(true)
const errorMessage = ref<string | null>(null)

const dayNames = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu']

const startingPrice = computed(() => {
  const rules = venue.value?.pricing_rules || []
  if (rules.length === 0) return null
  return Math.min(...rules.map((r) => Number(r.price_per_hour)))
})

const activeCourtCount = computed(
  () => venue.value?.courts?.filter((c) => c.status === 'active').length ?? 0,
)

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
  { title: 'Pilih Jadwal', text: 'Pilih tanggal, lapangan, dan jam yang tersedia.' },
  { title: 'Isi Data', text: 'Masukkan nama dan nomor WhatsApp pemesan.' },
  { title: 'Transfer & Upload', text: 'Transfer sesuai tagihan, lalu unggah bukti transfer.' },
  { title: 'Terima E-Voucher', text: 'Setelah diverifikasi admin, tunjukkan QR saat check-in.' },
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
  <div class="space-y-16 pb-8">
    <div
      v-if="loading"
      class="rounded-2xl border border-stone-200 bg-white p-12 text-center text-sm text-stone-500"
    >
      Memuat...
    </div>

    <div
      v-else-if="errorMessage || !venue"
      class="rounded-2xl border border-red-200 bg-red-50 p-6 text-sm text-red-800"
    >
      {{ errorMessage || 'Data venue belum tersedia.' }}
    </div>

    <template v-else>
      <!-- HERO -->
      <section class="relative overflow-hidden rounded-3xl bg-stone-950 text-white">
        <div class="grid gap-10 p-8 sm:p-12 lg:grid-cols-12 lg:items-center">
          <div class="lg:col-span-7 space-y-6">
            <span
              class="inline-block rounded-full border border-terracotta-500/40 bg-terracotta-600/15 px-3 py-1 text-xs font-semibold uppercase tracking-wider text-terracotta-300"
            >
              {{ venue.city }}
            </span>
            <h1 class="text-4xl sm:text-6xl font-extrabold tracking-tight leading-[1.05]">
              {{ venue.name }}
            </h1>
            <p v-if="venue.description" class="max-w-xl text-base text-stone-300">
              {{ venue.description }}
            </p>
            <div class="flex flex-wrap gap-3 pt-2">
              <RouterLink
                :to="{ name: 'booking' }"
                class="rounded-xl bg-terracotta-600 px-6 py-3 text-sm font-bold text-white hover:bg-terracotta-500 transition-colors"
              >
                Booking Lapangan
              </RouterLink>
              <RouterLink
                :to="{ name: 'booking-lookup' }"
                class="rounded-xl border border-white/20 px-6 py-3 text-sm font-semibold text-white hover:bg-white/10 transition-colors"
              >
                Cek Pesanan
              </RouterLink>
            </div>
          </div>

          <!-- Diagram lapangan padel 20 x 10 m -->
          <div class="lg:col-span-5">
            <svg
              viewBox="0 0 220 120"
              class="w-full rounded-2xl bg-terracotta-700/90 p-3"
              role="img"
              aria-label="Diagram lapangan padel"
            >
              <rect x="10" y="10" width="200" height="100" fill="none" stroke="white" stroke-width="2" />
              <line x1="110" y1="6" x2="110" y2="114" stroke="white" stroke-width="2.5" />
              <line x1="40.5" y1="10" x2="40.5" y2="110" stroke="white" stroke-width="1.2" />
              <line x1="179.5" y1="10" x2="179.5" y2="110" stroke="white" stroke-width="1.2" />
              <line x1="40.5" y1="60" x2="179.5" y2="60" stroke="white" stroke-width="1.2" />
            </svg>
            <p class="mt-2 text-center text-xs text-stone-400">Lapangan padel standar 20 × 10 m</p>
          </div>
        </div>

        <!-- Stats strip -->
        <div class="grid grid-cols-2 sm:grid-cols-4 border-t border-white/10">
          <div class="p-5 sm:p-6 border-r border-white/10">
            <p class="text-2xl sm:text-3xl font-extrabold">{{ activeCourtCount }}</p>
            <p class="text-xs text-stone-400">Lapangan Aktif</p>
          </div>
          <div class="p-5 sm:p-6 sm:border-r border-white/10">
            <p class="text-2xl sm:text-3xl font-extrabold">{{ hoursSummary || '—' }}</p>
            <p class="text-xs text-stone-400">Jam Operasional (WITA)</p>
          </div>
          <div class="p-5 sm:p-6 border-r border-t sm:border-t-0 border-white/10">
            <p class="text-2xl sm:text-3xl font-extrabold">
              {{ startingPrice !== null ? formatIdr(startingPrice) : '—' }}
            </p>
            <p class="text-xs text-stone-400">Tarif Mulai / Jam</p>
          </div>
          <div class="p-5 sm:p-6 border-t sm:border-t-0 border-white/10">
            <p class="text-2xl sm:text-3xl font-extrabold">{{ venue.hold_duration_minutes }} mnt</p>
            <p class="text-xs text-stone-400">Batas Bayar Setelah Booking</p>
          </div>
        </div>
      </section>

      <!-- TENTANG PADEL -->
      <section class="grid gap-8 lg:grid-cols-12">
        <div class="lg:col-span-4">
          <p class="text-xs font-bold uppercase tracking-wider text-terracotta-600">Tentang Padel</p>
          <h2 class="mt-2 text-3xl font-extrabold tracking-tight text-stone-900">
            Olahraga raket yang dimainkan berpasangan
          </h2>
        </div>
        <div class="lg:col-span-8 grid gap-4 sm:grid-cols-3">
          <div class="rounded-2xl border border-stone-200 bg-white p-5">
            <p class="text-3xl font-extrabold text-terracotta-600">2 vs 2</p>
            <p class="mt-2 text-sm text-stone-600">Dimainkan ganda, dua pemain di tiap sisi.</p>
          </div>
          <div class="rounded-2xl border border-stone-200 bg-white p-5">
            <p class="text-3xl font-extrabold text-terracotta-600">20×10 m</p>
            <p class="mt-2 text-sm text-stone-600">Lapangan tertutup dinding dan pagar, dibagi net.</p>
          </div>
          <div class="rounded-2xl border border-stone-200 bg-white p-5">
            <p class="text-3xl font-extrabold text-terracotta-600">Dinding</p>
            <p class="mt-2 text-sm text-stone-600">Bola boleh memantul dari dinding setelah menyentuh lantai.</p>
          </div>
        </div>
      </section>

      <!-- LAPANGAN -->
      <section class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
          <div>
            <p class="text-xs font-bold uppercase tracking-wider text-terracotta-600">Lapangan</p>
            <h2 class="mt-2 text-3xl font-extrabold tracking-tight text-stone-900">Pilih Lapangan</h2>
          </div>
          <RouterLink
            :to="{ name: 'booking' }"
            class="text-sm font-bold text-terracotta-700 hover:underline"
          >
            Lihat jadwal kosong →
          </RouterLink>
        </div>

        <div class="grid gap-5 sm:grid-cols-2">
          <article
            v-for="court in venue.courts"
            :key="court.id"
            class="group overflow-hidden rounded-3xl border border-stone-200 bg-white"
          >
            <div class="flex items-center justify-between bg-terracotta-600 px-6 py-8 text-white">
              <div>
                <p class="text-xs font-semibold uppercase tracking-wider text-terracotta-100">
                  Kode {{ court.code }}
                </p>
                <h3 class="mt-1 text-2xl font-extrabold">{{ court.name }}</h3>
              </div>
              <AppIcon name="court" class="w-12 h-12 text-white/70" />
            </div>
            <div class="flex items-center justify-between px-6 py-4">
              <span class="text-sm font-medium text-stone-700">
                {{ formatCourtType(court.court_type) }}
              </span>
              <span
                class="rounded-full px-2.5 py-0.5 text-xs font-semibold"
                :class="
                  court.status === 'active'
                    ? 'bg-emerald-50 text-emerald-700'
                    : 'bg-amber-50 text-amber-700'
                "
              >
                {{ court.status === 'active' ? 'Tersedia' : 'Maintenance' }}
              </span>
            </div>
          </article>
        </div>
      </section>

      <!-- CARA BOOKING -->
      <section class="rounded-3xl border border-stone-200 bg-white p-8 sm:p-10 space-y-8">
        <div>
          <p class="text-xs font-bold uppercase tracking-wider text-terracotta-600">Cara Booking</p>
          <h2 class="mt-2 text-3xl font-extrabold tracking-tight text-stone-900">4 Langkah</h2>
        </div>
        <ol class="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
          <li v-for="(step, idx) in steps" :key="step.title" class="space-y-2">
            <span
              class="flex h-10 w-10 items-center justify-center rounded-full bg-terracotta-600 text-sm font-bold text-white"
            >
              {{ idx + 1 }}
            </span>
            <p class="font-bold text-stone-900">{{ step.title }}</p>
            <p class="text-sm text-stone-600">{{ step.text }}</p>
          </li>
        </ol>
      </section>

      <!-- TARIF & JAM -->
      <section class="grid gap-6 lg:grid-cols-2">
        <div class="rounded-3xl border border-stone-200 bg-white p-8 space-y-4">
          <p class="text-xs font-bold uppercase tracking-wider text-terracotta-600">Tarif</p>
          <ul class="divide-y divide-stone-100">
            <li
              v-for="rule in venue.pricing_rules"
              :key="rule.id"
              class="flex items-center justify-between gap-4 py-3"
            >
              <div>
                <p class="text-sm font-semibold text-stone-900">{{ rule.name }}</p>
                <p class="text-xs text-stone-500">
                  {{ rule.start_time.slice(0, 5) }} – {{ rule.end_time.slice(0, 5) }} WITA
                  <span v-if="rule.day_of_week !== null"> • {{ dayNames[rule.day_of_week] }}</span>
                </p>
              </div>
              <span class="shrink-0 text-sm font-bold text-terracotta-700">
                {{ formatIdr(rule.price_per_hour) }}/jam
              </span>
            </li>
          </ul>
        </div>

        <div class="rounded-3xl border border-stone-200 bg-white p-8 space-y-4">
          <p class="text-xs font-bold uppercase tracking-wider text-terracotta-600">Jam Operasional</p>
          <ul class="divide-y divide-stone-100">
            <li
              v-for="item in venue.operating_hours"
              :key="item.day_of_week"
              class="flex items-center justify-between py-2.5 text-sm"
            >
              <span class="font-medium text-stone-700">{{ dayNames[item.day_of_week] }}</span>
              <span v-if="item.is_closed" class="font-semibold text-red-700">Tutup</span>
              <span v-else class="font-semibold text-stone-900">
                {{ item.open_time.slice(0, 5) }} – {{ item.close_time.slice(0, 5) }}
              </span>
            </li>
          </ul>
        </div>
      </section>

      <!-- LOKASI & KONTAK -->
      <section class="grid gap-6 lg:grid-cols-12">
        <div class="lg:col-span-7 rounded-3xl border border-stone-200 bg-white p-8 space-y-4">
          <p class="text-xs font-bold uppercase tracking-wider text-terracotta-600">Lokasi</p>
          <p class="text-lg font-semibold text-stone-900">{{ venue.address }}</p>
          <a
            :href="mapsUrl"
            target="_blank"
            rel="noopener noreferrer"
            class="inline-flex items-center gap-1.5 text-sm font-bold text-terracotta-700 hover:underline"
          >
            Buka di Google Maps
            <AppIcon name="external" class="w-3.5 h-3.5" />
          </a>
        </div>
        <div class="lg:col-span-5 rounded-3xl border border-stone-200 bg-white p-8 space-y-4">
          <p class="text-xs font-bold uppercase tracking-wider text-terracotta-600">Kontak</p>
          <p class="text-lg font-semibold text-stone-900">{{ venue.phone }}</p>
          <a
            :href="whatsappUrl"
            target="_blank"
            rel="noopener noreferrer"
            class="inline-flex items-center gap-1.5 text-sm font-bold text-terracotta-700 hover:underline"
          >
            Chat WhatsApp
            <AppIcon name="external" class="w-3.5 h-3.5" />
          </a>
        </div>
      </section>

      <!-- CTA -->
      <section
        class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-6 rounded-3xl bg-terracotta-600 p-8 sm:p-10 text-white"
      >
        <h2 class="text-2xl sm:text-3xl font-extrabold tracking-tight">Siap main padel?</h2>
        <div class="flex flex-wrap gap-3">
          <RouterLink
            :to="{ name: 'booking' }"
            class="rounded-xl bg-white px-6 py-3 text-sm font-bold text-terracotta-700 hover:bg-terracotta-50"
          >
            Booking Lapangan
          </RouterLink>
          <RouterLink
            :to="{ name: 'policies' }"
            class="rounded-xl border border-white/40 px-6 py-3 text-sm font-semibold text-white hover:bg-white/10"
          >
            Aturan & Kebijakan
          </RouterLink>
        </div>
      </section>
    </template>
  </div>
</template>
