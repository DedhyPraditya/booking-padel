<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import type {
  AvailabilitySlot,
  Booking,
  CourtAvailability,
  DailyAvailability,
  PriceQuote,
  Venue,
} from '@/types/api'
import { ApiError, apiRequest } from '@/services/apiClient'
import AppIcon from '@/components/ui/AppIcon.vue'

interface SelectedSlotItem {
  key: string
  court_id: string
  court_name: string
  court_code: string
  start_at: string
  end_at: string
  start_time: string
  end_time: string
  price: number
}

const router = useRouter()
const venue = ref<Venue | null>(null)
const loading = ref(true)
const errorMessage = ref<string | null>(null)

// Booking / Availability state
const selectedDate = ref(new Date().toISOString().slice(0, 10))
const selectedDuration = ref(60)
const availability = ref<DailyAvailability | null>(null)
const availabilityLoading = ref(false)

// Multi-slot & Multi-court selection cart
const selectedItems = ref<SelectedSlotItem[]>([])
const isModalOpen = ref(false)
const quote = ref<PriceQuote | null>(null)
const quoteLoading = ref(false)

const customerForm = ref({
  customer_name: '',
  customer_phone: '',
  customer_email: '',
  notes: '',
  promotion_code: '',
})
const holdSubmitting = ref(false)
const bookingError = ref<string | null>(null)

const dayNames = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu']

const facilities = [
  '2 Court Semi-Indoor (Atap Pelindung Cuaca)',
  'Kaca Tempered Panoramic & Rumput Sintetis Standar Turnamen',
  'Lampu LED High-Bay Anti-Silau',
  'Area Tunggu Pemain & Tribun Penonton',
  'Penyewaan Raket & Bola Padel',
  'Toilet, Ruang Ganti, Musholla & Parkir Luas',
]

const estimatedTotal = computed(() =>
  selectedItems.value.reduce((sum, item) => sum + (item.price || 0), 0),
)

const totalSelectedHours = computed(() => {
  const totalMinutes = selectedItems.value.length * selectedDuration.value
  return (totalMinutes / 60).toFixed(totalMinutes % 60 === 0 ? 0 : 1)
})

const startingPrice = computed(() => {
  const rules = venue.value?.pricing_rules || []
  if (rules.length === 0) return 250000
  return Math.min(...rules.map((r) => Number(r.price_per_hour)))
})

function formatIdr(amount: string | number | null): string {
  if (amount === null) return '-'
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

function slotStatusText(status: AvailabilitySlot['status']): string {
  const map: Record<AvailabilitySlot['status'], string> = {
    available: 'Tersedia',
    held: 'Ditahan',
    booked: 'Terpesan',
    blocked: 'Diblokir',
    maintenance: 'Maintenance',
    unpriced: 'Tutup',
  }
  return map[status] || status
}

function scrollToSection(id: string): void {
  document.getElementById(id)?.scrollIntoView({ behavior: 'smooth' })
}

function makeSlotKey(courtId: string, startAt: string): string {
  return `${courtId}::${startAt}`
}

function isSlotSelected(courtId: string, startAt: string): boolean {
  const key = makeSlotKey(courtId, startAt)
  return selectedItems.value.some((item) => item.key === key)
}

function overlapsWithExistingOnSameCourt(courtId: string, startAt: string, endAt: string): boolean {
  const reqStart = new Date(startAt).getTime()
  const reqEnd = new Date(endAt).getTime()
  return selectedItems.value.some((item) => {
    if (item.court_id !== courtId) return false
    const exStart = new Date(item.start_at).getTime()
    const exEnd = new Date(item.end_at).getTime()
    return exStart < reqEnd && exEnd > reqStart
  })
}

function toggleSlot(court: CourtAvailability, slot: AvailabilitySlot): void {
  if (!slot.available) return
  bookingError.value = null
  const key = makeSlotKey(court.court_id, slot.start_at)
  const existingIndex = selectedItems.value.findIndex((i) => i.key === key)

  if (existingIndex !== -1) {
    selectedItems.value.splice(existingIndex, 1)
    return
  }

  if (overlapsWithExistingOnSameCourt(court.court_id, slot.start_at, slot.end_at)) {
    bookingError.value =
      'Slot yang dipilih beririsan dengan jam lain yang sudah Anda pilih di lapangan yang sama.'
    return
  }

  selectedItems.value.push({
    key,
    court_id: court.court_id,
    court_name: court.court_name,
    court_code: court.court_code,
    start_at: slot.start_at,
    end_at: slot.end_at,
    start_time: slot.start_time,
    end_time: slot.end_time,
    price: slot.price || 0,
  })
}

function selectAllAvailableForCourt(court: CourtAvailability): void {
  bookingError.value = null
  for (const slot of court.slots) {
    if (!slot.available) continue
    if (isSlotSelected(court.court_id, slot.start_at)) continue
    if (overlapsWithExistingOnSameCourt(court.court_id, slot.start_at, slot.end_at)) continue

    selectedItems.value.push({
      key: makeSlotKey(court.court_id, slot.start_at),
      court_id: court.court_id,
      court_name: court.court_name,
      court_code: court.court_code,
      start_at: slot.start_at,
      end_at: slot.end_at,
      start_time: slot.start_time,
      end_time: slot.end_time,
      price: slot.price || 0,
    })
  }
}

function clearSelectedSlots(): void {
  selectedItems.value = []
  quote.value = null
  bookingError.value = null
  isModalOpen.value = false
}

function closeBookingModal(): void {
  if (holdSubmitting.value) return
  isModalOpen.value = false
  bookingError.value = null
}

function handleKeydown(e: KeyboardEvent): void {
  if (e.key === 'Escape' && isModalOpen.value) {
    closeBookingModal()
  }
}

async function openCheckoutModal(): Promise<void> {
  if (!venue.value || selectedItems.value.length === 0) return
  isModalOpen.value = true
  quote.value = null
  quoteLoading.value = true
  bookingError.value = null
  try {
    const res = await apiRequest<PriceQuote>('/price-quotes', {
      method: 'POST',
      body: JSON.stringify({
        venue_id: venue.value.id,
        items: selectedItems.value.map((i) => ({
          court_id: i.court_id,
          start_at: i.start_at,
          end_at: i.end_at,
        })),
        promotion_code: customerForm.value.promotion_code.trim() || null,
      }),
    })
    quote.value = res.data
  } catch (err) {
    bookingError.value =
      err instanceof ApiError
        ? err.message
        : 'Salah satu slot tidak lagi tersedia. Silakan periksa kembali pilihan jadwal Anda.'
    await loadAvailability()
  } finally {
    quoteLoading.value = false
  }
}

async function loadPrimaryVenue(): Promise<void> {
  loading.value = true
  errorMessage.value = null
  try {
    const detailRes = await apiRequest<Venue>('/venues/padel-makassar')
    venue.value = detailRes.data
    await loadAvailability()
  } catch {
    try {
      const listRes = await apiRequest<Venue[]>('/venues')
      const first = listRes.data[0]
      if (!first) {
        venue.value = null
        return
      }
      const detailRes = await apiRequest<Venue>(`/venues/${encodeURIComponent(first.slug)}`)
      venue.value = detailRes.data
      await loadAvailability()
    } catch (err) {
      errorMessage.value =
        err instanceof ApiError ? err.message : 'Gagal memuat informasi Padel Makassar.'
    }
  } finally {
    loading.value = false
  }
}

async function loadAvailability(): Promise<void> {
  if (!venue.value) return
  availabilityLoading.value = true
  selectedItems.value = []
  quote.value = null
  isModalOpen.value = false
  bookingError.value = null
  try {
    const res = await apiRequest<DailyAvailability>(
      `/availability?venue_id=${venue.value.id}&date=${selectedDate.value}&duration_minutes=${selectedDuration.value}`,
    )
    availability.value = res.data
  } catch (err) {
    bookingError.value =
      err instanceof ApiError ? err.message : 'Gagal memuat jadwal ketersediaan lapangan.'
  } finally {
    availabilityLoading.value = false
  }
}

async function submitBookingHold(): Promise<void> {
  if (!venue.value || selectedItems.value.length === 0) return
  holdSubmitting.value = true
  bookingError.value = null
  try {
    const res = await apiRequest<{ booking: Booking; public_token: string }>('/booking-holds', {
      method: 'POST',
      body: JSON.stringify({
        venue_id: venue.value.id,
        items: selectedItems.value.map((i) => ({
          court_id: i.court_id,
          start_at: i.start_at,
          end_at: i.end_at,
        })),
        customer_name: customerForm.value.customer_name,
        customer_phone: customerForm.value.customer_phone,
        customer_email: customerForm.value.customer_email || null,
        notes: customerForm.value.notes || null,
        promotion_code: customerForm.value.promotion_code.trim() || null,
      }),
    })
    await router.push({
      name: 'booking-status',
      params: { token: res.data.public_token },
    })
  } catch (err) {
    bookingError.value =
      err instanceof ApiError
        ? err.message
        : 'Gagal membuat reservasi. Salah satu slot mungkin baru saja diambil.'
    await loadAvailability()
  } finally {
    holdSubmitting.value = false
  }
}

onMounted(() => {
  window.addEventListener('keydown', handleKeydown)
  loadPrimaryVenue()
})

onBeforeUnmount(() => {
  window.removeEventListener('keydown', handleKeydown)
})
</script>

<template>
  <div class="space-y-10 pb-24">
    <div
      v-if="loading"
      class="rounded-2xl border border-stone-200 bg-white p-12 text-center text-sm text-stone-500"
    >
      Memuat PADEL MAKASSAR...
    </div>

    <div
      v-else-if="errorMessage"
      class="rounded-2xl border border-red-200 bg-red-50 p-6 text-sm text-red-800 space-y-3"
    >
      <p class="font-semibold">Gagal memuat data venue</p>
      <p>{{ errorMessage }}</p>
      <button
        type="button"
        class="rounded-lg bg-red-700 px-3.5 py-2 text-xs font-semibold text-white hover:bg-red-800"
        @click="loadPrimaryVenue"
      >
        Coba Lagi
      </button>
    </div>

    <template v-else-if="venue">
      <!-- 1. HERO LANDING SECTION -->
      <section
        class="relative overflow-hidden rounded-3xl border border-stone-200 bg-white p-6 sm:p-10 shadow-2xs"
      >
        <div class="grid gap-8 lg:grid-cols-12 lg:items-center">
          <div class="lg:col-span-7 space-y-5">
            <div class="inline-flex flex-wrap items-center gap-2">
              <span
                class="rounded-full bg-terracotta-50 border border-terracotta-200 px-3 py-1 text-xs font-bold text-terracotta-700"
              >
                {{ venue.city }} • Semi-Indoor Court
              </span>
              <span
                class="rounded-full bg-stone-100 px-3 py-1 text-xs font-semibold text-stone-700"
              >
                07:00 – 23:00 WITA
              </span>
            </div>

            <h1 class="text-3xl sm:text-4xl font-extrabold tracking-tight text-stone-900">
              {{ venue.name }}
            </h1>

            <p class="text-sm sm:text-base text-stone-600 leading-relaxed">
              {{ venue.address }}
            </p>

            <div class="flex flex-wrap items-center gap-3 pt-1">
              <button
                type="button"
                class="rounded-xl bg-terracotta-600 px-6 py-3 text-sm font-bold text-white shadow-xs hover:bg-terracotta-700 transition-colors"
                @click="scrollToSection('booking')"
              >
                Pilih Jadwal & Booking Sekarang
              </button>
              <RouterLink
                :to="{ name: 'booking-lookup' }"
                class="rounded-xl border border-stone-300 bg-white px-5 py-3 text-sm font-semibold text-stone-800 hover:bg-stone-50 transition-colors"
              >
                Cek Pesanan / E-Voucher
              </RouterLink>
            </div>
          </div>

          <!-- Highlight Ringkas Kanan -->
          <div class="lg:col-span-5 grid grid-cols-2 gap-3">
            <div class="rounded-2xl border border-stone-200/80 bg-[#FAF8F6] p-4 space-y-1">
              <span class="text-xs font-medium text-stone-500">Mulai Dari</span>
              <p class="text-xl font-extrabold text-terracotta-700">
                {{ formatIdr(startingPrice) }}
              </p>
              <span class="text-[11px] text-stone-500">per jam (07:00 – 16:00)</span>
            </div>

            <div class="rounded-2xl border border-stone-200/80 bg-[#FAF8F6] p-4 space-y-1">
              <span class="text-xs font-medium text-stone-500">Kapasitas</span>
              <p class="text-xl font-extrabold text-stone-900">
                {{ venue.courts?.length || 2 }} Lapangan
              </p>
              <span class="text-[11px] text-stone-500">Lapangan 1 (001) & 2 (002)</span>
            </div>

            <div
              class="col-span-2 rounded-2xl border border-terracotta-200 bg-terracotta-50/70 p-4 flex items-center justify-between gap-3"
            >
              <div>
                <span class="text-[11px] font-bold uppercase tracking-wider text-terracotta-700">
                  Promo Aktif
                </span>
                <p class="text-sm font-bold text-stone-900">
                  Gunakan Kode <code class="font-mono text-terracotta-700">OPENING10</code> (Diskon
                  10%)
                </p>
              </div>
              <button
                type="button"
                class="shrink-0 rounded-lg bg-terracotta-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-terracotta-700"
                @click="
                  () => {
                    customerForm.promotion_code = 'OPENING10'
                    scrollToSection('booking')
                  }
                "
              >
                Pakai Kode
              </button>
            </div>
          </div>
        </div>
      </section>

      <!-- 2. SPESIFIKASI 2 LAPANGAN & FASILITAS VENUE (#courts) -->
      <section id="courts" class="grid gap-6 lg:grid-cols-12">
        <!-- 2 Kartu Lapangan Real -->
        <div class="lg:col-span-7 rounded-2xl border border-stone-200 bg-white p-6 space-y-4">
          <div class="flex items-center justify-between">
            <div class="flex items-center gap-2.5">
              <span
                class="flex h-8 w-8 items-center justify-center rounded-lg bg-terracotta-50 text-terracotta-600"
              >
                <AppIcon name="court" class="w-4 h-4" />
              </span>
              <h2 class="text-lg font-bold text-stone-900">Spesifikasi Lapangan</h2>
            </div>
            <span class="text-xs font-semibold text-stone-500">
              {{ venue.courts?.length || 2 }} Court Aktif
            </span>
          </div>

          <div class="grid gap-4 sm:grid-cols-2">
            <article
              v-for="court in venue.courts"
              :key="court.id"
              class="rounded-2xl border border-stone-200 bg-[#FAF8F6] p-5 flex flex-col justify-between gap-4"
            >
              <div class="space-y-2.5">
                <div class="flex items-center justify-between">
                  <span
                    class="rounded-md bg-terracotta-600 px-2.5 py-0.5 font-mono text-xs font-bold text-white"
                  >
                    Kode {{ court.code }}
                  </span>
                  <span
                    class="rounded-full px-2.5 py-0.5 text-xs font-semibold"
                    :class="
                      court.status === 'active'
                        ? 'bg-emerald-50 text-emerald-700 border border-emerald-200'
                        : 'bg-amber-50 text-amber-700 border border-amber-200'
                    "
                  >
                    {{ court.status === 'active' ? 'Tersedia' : 'Maintenance' }}
                  </span>
                </div>

                <h3 class="text-lg font-bold text-stone-900">{{ court.name }}</h3>

                <ul class="space-y-1 text-xs text-stone-600">
                  <li>• Konstruksi: <strong>{{ formatCourtType(court.court_type) }}</strong></li>
                  <li>• Lantai: <strong>Panoramic Synthetic Turf</strong></li>
                  <li>• Dinding: <strong>Tempered Glass 12mm</strong></li>
                  <li>• Pencahayaan: <strong>LED Tournament Grade</strong></li>
                </ul>
              </div>

              <button
                type="button"
                class="w-full rounded-xl border border-terracotta-600 bg-white py-2 text-xs font-bold text-terracotta-700 hover:bg-terracotta-50 transition-colors"
                @click="scrollToSection('booking')"
              >
                Cek Jam {{ court.name }} →
              </button>
            </article>
          </div>
        </div>

        <!-- Fasilitas Venue -->
        <div class="lg:col-span-5 rounded-2xl border border-stone-200 bg-white p-6 flex flex-col justify-between gap-4">
          <div class="space-y-4">
            <div class="flex items-center gap-2.5">
              <span
                class="flex h-8 w-8 items-center justify-center rounded-lg bg-terracotta-50 text-terracotta-600"
              >
                <AppIcon name="check" class="w-4 h-4" />
              </span>
              <h2 class="text-lg font-bold text-stone-900">Fasilitas Venue</h2>
            </div>

            <ul class="space-y-2.5 text-sm text-stone-700">
              <li
                v-for="item in facilities"
                :key="item"
                class="flex items-start gap-2.5 rounded-xl bg-[#FAF8F6] px-3.5 py-2.5 text-xs font-medium text-stone-800"
              >
                <span class="text-terracotta-600 font-bold">✓</span>
                <span>{{ item }}</span>
              </li>
            </ul>
          </div>

          <div class="rounded-xl border border-stone-200 bg-[#FAF8F6] p-3.5 flex items-center justify-between text-xs">
            <span class="text-stone-600">Sewa grup / komunitas / turnamen?</span>
            <a
              :href="`https://wa.me/62${venue.phone.replace(/^0/, '')}`"
              target="_blank"
              rel="noopener noreferrer"
              class="font-bold text-terracotta-700 hover:underline"
            >
              WhatsApp Admin ({{ venue.phone }}) ↗
            </a>
          </div>
        </div>
      </section>

      <!-- 3. MESIN KETERSEDIAAN & MULTI-SLOT BOOKING (#booking) -->
      <section
        id="booking"
        class="rounded-2xl border border-stone-200 bg-white p-6 shadow-2xs space-y-5 scroll-mt-20"
      >
        <div class="flex flex-wrap items-end justify-between gap-4 border-b border-stone-100 pb-4">
          <div class="flex items-center gap-2.5">
            <span
              class="flex h-9 w-9 items-center justify-center rounded-xl bg-terracotta-600 text-white"
            >
              <AppIcon name="calendar" class="w-4 h-4" />
            </span>
            <div>
              <h2 class="text-xl font-bold text-stone-900">Jadwal & Booking Online</h2>
              <p class="text-xs text-stone-500">
                Bisa pilih 1 jam, beberapa jam sekaligus, atau 2 lapangan bersamaan
              </p>
            </div>
          </div>

          <div class="flex flex-wrap items-end gap-3">
            <div>
              <label class="block text-xs font-medium text-stone-700">Tanggal Bermain</label>
              <input
                v-model="selectedDate"
                type="date"
                class="mt-1 rounded-xl border border-stone-300 px-3 py-2 text-xs font-semibold"
                @change="loadAvailability"
              />
            </div>

            <div>
              <label class="block text-xs font-medium text-stone-700">Ukuran Blok Slot</label>
              <select
                v-model.number="selectedDuration"
                class="mt-1 rounded-xl border border-stone-300 px-3 py-2 text-xs font-semibold"
                @change="loadAvailability"
              >
                <option :value="60">Per 1 Jam (60 Menit)</option>
                <option :value="120">Per 2 Jam (120 Menit)</option>
                <option :value="180">Per 3 Jam (180 Menit)</option>
              </select>
            </div>

            <button
              type="button"
              class="inline-flex items-center gap-1.5 rounded-xl border border-stone-300 bg-white px-3.5 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-100"
              :disabled="availabilityLoading"
              @click="loadAvailability"
            >
              <AppIcon name="refresh" class="w-3.5 h-3.5" />
              <span>{{ availabilityLoading ? 'Memuat...' : 'Segarkan' }}</span>
            </button>
          </div>
        </div>

        <div
          v-if="bookingError && !isModalOpen"
          class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800"
        >
          {{ bookingError }}
        </div>

        <div v-if="availabilityLoading" class="py-10 text-center text-sm text-stone-500">
          Menghitung slot jadwal yang tersedia...
        </div>

        <div
          v-else-if="availability?.is_closed"
          class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"
        >
          {{ availability.closed_reason || 'Venue tutup pada tanggal yang dipilih.' }}
        </div>

        <div v-else-if="availability" class="space-y-6">
          <div
            v-for="court in availability.courts"
            :key="court.court_id"
            class="rounded-2xl border border-stone-200 p-4 sm:p-5 space-y-3.5"
          >
            <div class="flex flex-wrap items-center justify-between gap-2">
              <div class="flex items-center gap-2.5">
                <span
                  class="rounded-md bg-terracotta-600 px-2.5 py-0.5 font-mono text-xs font-bold text-white"
                >
                  {{ court.court_code }}
                </span>
                <h3 class="font-bold text-stone-900">{{ court.court_name }}</h3>
                <span class="text-xs text-stone-500">
                  ({{ formatCourtType(court.court_type) }})
                </span>
              </div>
              <button
                type="button"
                class="rounded-lg border border-terracotta-600 px-3 py-1 text-xs font-semibold text-terracotta-700 hover:bg-terracotta-50"
                @click="selectAllAvailableForCourt(court)"
              >
                + Pilih Semua Jam Tersedia ({{ court.court_name }})
              </button>
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-4 md:grid-cols-6 gap-2">
              <button
                v-for="slot in court.slots"
                :key="slot.start_at"
                type="button"
                :disabled="!slot.available"
                class="rounded-xl border p-2.5 text-left text-xs transition"
                :class="{
                  'border-terracotta-600 bg-terracotta-600 text-white font-semibold ring-2 ring-terracotta-600/30':
                    isSlotSelected(court.court_id, slot.start_at),
                  'border-stone-200 bg-white hover:border-terracotta-600 text-stone-900':
                    slot.available && !isSlotSelected(court.court_id, slot.start_at),
                  'border-amber-200 bg-amber-50 text-amber-800 cursor-not-allowed':
                    slot.status === 'held',
                  'border-stone-200 bg-stone-100 text-stone-400 cursor-not-allowed':
                    !slot.available && slot.status !== 'held',
                }"
                @click="toggleSlot(court, slot)"
              >
                <div class="flex items-center justify-between">
                  <span class="font-bold">{{ slot.start_time }} – {{ slot.end_time }}</span>
                  <span v-if="isSlotSelected(court.court_id, slot.start_at)" class="text-[11px]">
                    ✓
                  </span>
                </div>
                <div class="mt-0.5 text-[11px]">
                  <template v-if="slot.available">{{ formatIdr(slot.price) }}</template>
                  <template v-else>{{ slotStatusText(slot.status) }}</template>
                </div>
              </button>
            </div>
          </div>
        </div>
      </section>

      <!-- 4. DAFTAR TARIF, JAM OPERASIONAL & LOKASI (#info) -->
      <section id="info" class="grid gap-6 lg:grid-cols-3">
        <!-- Tarif Sewa -->
        <div class="rounded-2xl border border-stone-200 bg-white p-6 space-y-4">
          <div class="flex items-center gap-2.5">
            <span
              class="flex h-8 w-8 items-center justify-center rounded-lg bg-terracotta-50 text-terracotta-600"
            >
              <AppIcon name="tag" class="w-4 h-4" />
            </span>
            <h2 class="text-base font-bold text-stone-900">Tarif Sewa Lapangan</h2>
          </div>

          <ul class="divide-y divide-stone-100 text-sm">
            <li
              v-for="rule in venue.pricing_rules"
              :key="rule.id"
              class="py-3 flex items-center justify-between gap-3"
            >
              <div>
                <p class="font-semibold text-stone-900 text-xs sm:text-sm">{{ rule.name }}</p>
                <p class="text-xs text-stone-500">
                  {{ rule.start_time.slice(0, 5) }} – {{ rule.end_time.slice(0, 5) }} WITA
                  <span v-if="rule.day_of_week !== null"> • {{ dayNames[rule.day_of_week] }}</span>
                </p>
              </div>
              <span class="shrink-0 font-bold text-terracotta-700 text-xs sm:text-sm">
                {{ formatIdr(rule.price_per_hour) }} / jam
              </span>
            </li>
          </ul>
        </div>

        <!-- Jam Operasional -->
        <div class="rounded-2xl border border-stone-200 bg-white p-6 space-y-4">
          <div class="flex items-center gap-2.5">
            <span
              class="flex h-8 w-8 items-center justify-center rounded-lg bg-terracotta-50 text-terracotta-600"
            >
              <AppIcon name="clock" class="w-4 h-4" />
            </span>
            <h2 class="text-base font-bold text-stone-900">Jam Operasional</h2>
          </div>

          <ul class="divide-y divide-stone-100 text-sm">
            <li
              v-for="item in venue.operating_hours"
              :key="item.day_of_week"
              class="py-2 flex items-center justify-between text-xs sm:text-sm"
            >
              <span class="font-medium text-stone-700">{{ dayNames[item.day_of_week] }}</span>
              <span v-if="item.is_closed" class="text-red-700 font-semibold">Tutup</span>
              <span v-else class="font-semibold text-stone-900">
                {{ item.open_time.slice(0, 5) }} – {{ item.close_time.slice(0, 5) }} WITA
              </span>
            </li>
          </ul>
        </div>

        <!-- Lokasi & Ketentuan -->
        <div class="rounded-2xl border border-stone-200 bg-white p-6 flex flex-col justify-between gap-4">
          <div class="space-y-4">
            <div class="flex items-center gap-2.5">
              <span
                class="flex h-8 w-8 items-center justify-center rounded-lg bg-terracotta-50 text-terracotta-600"
              >
                <AppIcon name="shield" class="w-4 h-4" />
              </span>
              <h2 class="text-base font-bold text-stone-900">Lokasi & Ketentuan</h2>
            </div>

            <div class="rounded-xl bg-[#FAF8F6] p-3.5 text-xs text-stone-700 space-y-1.5">
              <p class="font-bold text-stone-900">Alamat Venue:</p>
              <p>{{ venue.address }}</p>
            </div>

            <ul class="space-y-2 text-xs text-stone-600">
              <li>
                • Batas waktu pembayaran (hold slot):
                <strong>{{ venue.hold_duration_minutes }} menit</strong>
              </li>
              <li>
                • Batas pengajuan reschedule / batal:
                <strong>{{ venue.cancellation_cutoff_hours }} jam sebelum jadwal</strong>
              </li>
              <li>• Wajib menggunakan sepatu olahraga non-marking court shoes</li>
            </ul>
          </div>

          <div class="grid grid-cols-2 gap-2 pt-2">
            <a
              :href="`https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(venue.name + ' ' + venue.address)}`"
              target="_blank"
              rel="noopener noreferrer"
              class="rounded-xl border border-stone-300 bg-white px-3 py-2 text-center text-xs font-semibold text-stone-700 hover:bg-stone-50"
            >
              Google Maps ↗
            </a>
            <a
              :href="`https://wa.me/62${venue.phone.replace(/^0/, '')}`"
              target="_blank"
              rel="noopener noreferrer"
              class="rounded-xl bg-terracotta-600 px-3 py-2 text-center text-xs font-semibold text-white hover:bg-terracotta-700"
            >
              Chat WhatsApp ↗
            </a>
          </div>
        </div>
      </section>

      <!-- Floating Bottom Bar saat ada slot yang dipilih -->
      <div
        v-if="selectedItems.length > 0"
        class="fixed bottom-4 left-4 right-4 z-40 mx-auto max-w-4xl rounded-2xl border border-terracotta-600 bg-stone-900 px-5 py-3.5 text-white shadow-2xl flex flex-wrap items-center justify-between gap-4"
      >
        <div>
          <p class="text-xs text-terracotta-300 font-semibold uppercase">
            {{ selectedItems.length }} Slot Dipilih ({{ totalSelectedHours }} Jam)
          </p>
          <p class="text-base font-bold">Estimasi Total: {{ formatIdr(estimatedTotal) }}</p>
        </div>

        <div class="flex items-center gap-3">
          <button
            type="button"
            class="rounded-xl border border-stone-600 px-3.5 py-2 text-xs font-medium text-stone-200 hover:bg-stone-800"
            @click="clearSelectedSlots"
          >
            Reset
          </button>
          <button
            type="button"
            class="rounded-xl bg-terracotta-600 px-5 py-2 text-sm font-semibold text-white hover:bg-terracotta-500"
            @click="openCheckoutModal"
          >
            Lanjut Booking ({{ selectedItems.length }} Slot) →
          </button>
        </div>
      </div>

      <!-- Modal Popup Konfirmasi Multi-Slot & Form Pemesan -->
      <Teleport to="body">
        <div
          v-if="isModalOpen"
          class="fixed inset-0 z-50 flex items-center justify-center bg-stone-900/60 p-4 backdrop-blur-xs"
          role="dialog"
          aria-modal="true"
          @click.self="closeBookingModal"
        >
          <div
            class="w-full max-w-xl max-h-[90vh] overflow-y-auto rounded-2xl border border-stone-200 bg-white p-6 shadow-xl space-y-5"
          >
            <div class="flex items-start justify-between gap-4 border-b border-stone-100 pb-4">
              <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-terracotta-800">
                  Ringkasan Jadwal Dipilih ({{ selectedDate }})
                </p>
                <h3 class="mt-0.5 text-lg font-bold text-stone-900">
                  {{ quote ? `${quote.duration_minutes / 60} Jam Total Sewa` : 'Menghitung...' }}
                </h3>
              </div>

              <div class="flex items-start gap-3">
                <div class="text-right">
                  <p class="text-xs text-stone-500">Total Tagihan</p>
                  <p class="text-xl font-bold text-terracotta-700">
                    {{
                      quoteLoading
                        ? 'Menghitung...'
                        : quote
                          ? formatIdr(quote.total_amount)
                          : '-'
                    }}
                  </p>
                </div>
                <button
                  type="button"
                  aria-label="Tutup modal"
                  class="rounded-md p-1 text-stone-400 hover:bg-stone-100 hover:text-stone-700"
                  @click="closeBookingModal"
                >
                  ✕
                </button>
              </div>
            </div>

            <div
              v-if="bookingError"
              class="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-800"
            >
              {{ bookingError }}
            </div>

            <div v-if="quoteLoading" class="py-6 text-center text-sm text-stone-600">
              Memvalidasi ketersediaan seluruh slot & menghitung harga...
            </div>

            <template v-else-if="quote">
              <div class="rounded-xl bg-[#FAF8F6] p-3.5 space-y-2 text-xs">
                <p class="font-semibold text-stone-700">Rincian Sesi & Lapangan:</p>
                <div
                  v-for="(line, idx) in quote.items"
                  :key="idx"
                  class="flex items-center justify-between border-b border-stone-200/70 pb-1.5 last:border-b-0 last:pb-0"
                >
                  <div>
                    <span class="font-bold text-stone-900">
                      {{ line.court_name }} ({{ line.court_code }})
                    </span>
                    <span class="ml-2 text-stone-600">
                      {{ line.start_time }} – {{ line.end_time }} WITA ({{
                        line.duration_minutes
                      }}
                      menit)
                    </span>
                  </div>
                  <span class="font-semibold text-terracotta-800">
                    {{ formatIdr(line.total_price) }}
                  </span>
                </div>
              </div>

              <form class="space-y-4" @submit.prevent="submitBookingHold">
                <div>
                  <label class="block text-xs font-medium text-stone-700">
                    Nama Lengkap Pemesan / Penyelenggara *
                  </label>
                  <input
                    v-model="customerForm.customer_name"
                    type="text"
                    required
                    autofocus
                    placeholder="Masukkan nama pemesan atau nama komunitas"
                    class="mt-1 block w-full rounded-xl border border-stone-300 bg-white px-3 py-2 text-sm focus:border-terracotta-600 focus:outline-none"
                  />
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                  <div>
                    <label class="block text-xs font-medium text-stone-700">
                      No. WhatsApp / Telepon *
                    </label>
                    <input
                      v-model="customerForm.customer_phone"
                      type="text"
                      required
                      placeholder="08..."
                      class="mt-1 block w-full rounded-xl border border-stone-300 bg-white px-3 py-2 text-sm focus:border-terracotta-600 focus:outline-none"
                    />
                  </div>

                  <div>
                    <label class="block text-xs font-medium text-stone-700">Email (Opsional)</label>
                    <input
                      v-model="customerForm.customer_email"
                      type="email"
                      placeholder="email@contoh.com"
                      class="mt-1 block w-full rounded-xl border border-stone-300 bg-white px-3 py-2 text-sm focus:border-terracotta-600 focus:outline-none"
                    />
                  </div>
                </div>

                <div>
                  <label class="block text-xs font-medium text-stone-700">
                    Kode Promo / Voucher (Mis. OPENING10)
                  </label>
                  <div class="mt-1 flex items-center gap-2">
                    <input
                      v-model="customerForm.promotion_code"
                      type="text"
                      placeholder="Masukkan kode voucher"
                      class="block flex-1 rounded-xl border border-stone-300 bg-white px-3 py-2 font-mono text-sm uppercase focus:border-terracotta-600 focus:outline-none"
                    />
                    <button
                      type="button"
                      class="rounded-xl border border-terracotta-600 px-3.5 py-2 text-xs font-semibold text-terracotta-700 hover:bg-terracotta-50"
                      @click="openCheckoutModal"
                    >
                      Terapkan Voucher
                    </button>
                  </div>
                  <p
                    v-if="Number(quote.discount_amount) > 0"
                    class="mt-1 text-xs font-semibold text-emerald-700"
                  >
                    Diskon berhasil diterapkan: -{{ formatIdr(quote.discount_amount) }}
                  </p>
                </div>

                <div>
                  <label class="block text-xs font-medium text-stone-700">Catatan Tambahan</label>
                  <input
                    v-model="customerForm.notes"
                    type="text"
                    placeholder="Catatan opsional"
                    class="mt-1 block w-full rounded-xl border border-stone-300 bg-white px-3 py-2 text-sm focus:border-terracotta-600 focus:outline-none"
                  />
                </div>

                <div class="flex items-center justify-end gap-3 border-t border-stone-100 pt-4">
                  <button
                    type="button"
                    :disabled="holdSubmitting"
                    class="rounded-xl border border-stone-300 bg-white px-4 py-2 text-sm font-medium text-stone-700 hover:bg-stone-100"
                    @click="closeBookingModal"
                  >
                    Ubah Pilihan Jam
                  </button>
                  <button
                    type="submit"
                    :disabled="holdSubmitting"
                    class="rounded-xl bg-terracotta-600 px-5 py-2 text-sm font-semibold text-white hover:bg-terracotta-700 disabled:opacity-50"
                  >
                    {{
                      holdSubmitting
                        ? 'Mengunci Semua Slot...'
                        : `Booking & Kunci Slot (${venue.hold_duration_minutes}m)`
                    }}
                  </button>
                </div>
              </form>
            </template>
          </div>
        </div>
      </Teleport>
    </template>
  </div>
</template>
