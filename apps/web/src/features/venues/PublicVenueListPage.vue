<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import type {
  AvailabilitySlot,
  Booking,
  CourtAvailability,
  DailyAvailability,
  PriceQuote,
  Venue,
} from '@/types/api'
import { ApiError, apiRequest } from '@/services/apiClient'

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

const estimatedTotal = computed(() =>
  selectedItems.value.reduce((sum, item) => sum + (item.price || 0), 0),
)

const totalSelectedHours = computed(() => {
  const totalMinutes = selectedItems.value.length * selectedDuration.value
  return (totalMinutes / 60).toFixed(totalMinutes % 60 === 0 ? 0 : 1)
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
    held: 'Ditahan (Checkout)',
    booked: 'Terpesan',
    blocked: 'Diblokir',
    maintenance: 'Maintenance',
    unpriced: 'Tutup',
  }
  return map[status] || status
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

  // Prevent selecting overlapping intervals on the same court when slot interval < duration
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
  <section class="space-y-6 pb-24">
    <div
      v-if="loading"
      class="rounded-lg border border-stone-200 bg-white p-8 text-center text-sm text-stone-600"
    >
      Memuat informasi PADEL MAKASSAR...
    </div>

    <div
      v-else-if="errorMessage"
      class="rounded-lg border border-red-200 bg-red-50 p-6 text-sm text-red-800 space-y-3"
    >
      <p class="font-semibold">Gagal memuat data venue</p>
      <p>{{ errorMessage }}</p>
      <button
        type="button"
        class="rounded-md bg-red-700 px-3 py-1.5 text-white hover:bg-red-800"
        @click="loadPrimaryVenue"
      >
        Coba Lagi
      </button>
    </div>

    <div
      v-else-if="!venue"
      class="rounded-lg border border-stone-200 bg-white p-8 text-center text-sm text-stone-600"
    >
      Data venue belum tersedia.
    </div>

    <template v-else>
      <!-- Hero Venue Info -->
      <div class="rounded-lg border border-stone-200 bg-white p-6 space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-4 border-b border-stone-100 pb-4">
          <div>
            <span class="rounded-full bg-terracotta-50 px-2.5 py-0.5 text-xs font-semibold text-terracotta-800">
              {{ venue.city }} • Zona Waktu {{ venue.timezone }} (WITA)
            </span>
            <h1 class="mt-2 text-2xl font-bold text-stone-900">{{ venue.name }}</h1>
            <p class="mt-1 text-sm text-stone-600">{{ venue.address }}</p>
          </div>
          <div class="text-sm text-right">
            <p class="text-xs text-stone-500">WhatsApp / Kontak</p>
            <p class="font-semibold text-stone-900">{{ venue.phone }}</p>
          </div>
        </div>

        <dl class="grid gap-4 sm:grid-cols-3 rounded-lg bg-stone-50 p-4 text-sm">
          <div>
            <dt class="text-xs text-stone-500">Mode Pemilihan</dt>
            <dd class="font-semibold text-stone-900">
              Bisa pilih 1 jam, beberapa jam, atau 2 lapangan sekaligus (Turnamen)
            </dd>
          </div>
          <div>
            <dt class="text-xs text-stone-500">Jam Operasional</dt>
            <dd class="font-semibold text-stone-900">07:00 – 23:00 WITA</dd>
          </div>
          <div>
            <dt class="text-xs text-stone-500">Batas Waktu Pembayaran (Hold)</dt>
            <dd class="font-semibold text-stone-900">{{ venue.hold_duration_minutes }} menit</dd>
          </div>
        </dl>
      </div>

      <!-- Mesin Ketersediaan & Multi-Slot Booking -->
      <div class="rounded-lg border border-stone-200 bg-white p-6 space-y-5">
        <div class="flex flex-wrap items-end justify-between gap-4 border-b border-stone-100 pb-4">
          <div>
            <h2 class="text-xl font-bold text-stone-900">Pilih Jadwal & Lapangan</h2>
            <p class="text-sm text-stone-600">
              Klik satu atau beberapa slot jam (bisa lintas Lapangan 1 & Lapangan 2 untuk grup/turnamen).
            </p>
          </div>

          <div class="flex flex-wrap items-end gap-3">
            <div>
              <label class="block text-xs font-medium text-stone-700">Tanggal Bermain</label>
              <input
                v-model="selectedDate"
                type="date"
                class="mt-1 rounded-md border border-stone-300 px-3 py-1.5 text-sm"
                @change="loadAvailability"
              />
            </div>

            <div>
              <label class="block text-xs font-medium text-stone-700">Ukuran Blok Slot</label>
              <select
                v-model.number="selectedDuration"
                class="mt-1 rounded-md border border-stone-300 px-3 py-1.5 text-sm"
                @change="loadAvailability"
              >
                <option :value="60">Per 1 Jam (60 Menit)</option>
                <option :value="120">Per 2 Jam (120 Menit)</option>
                <option :value="180">Per 3 Jam (180 Menit)</option>
              </select>
            </div>

            <button
              type="button"
              class="rounded-md border border-stone-300 bg-white px-3 py-1.5 text-sm font-medium text-stone-700 hover:bg-stone-100"
              :disabled="availabilityLoading"
              @click="loadAvailability"
            >
              {{ availabilityLoading ? 'Memuat...' : 'Segarkan Jadwal' }}
            </button>
          </div>
        </div>

        <div
          v-if="bookingError && !isModalOpen"
          class="rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-800"
        >
          {{ bookingError }}
        </div>

        <div v-if="availabilityLoading" class="py-8 text-center text-sm text-stone-600">
          Menghitung slot jadwal yang tersedia...
        </div>

        <div
          v-else-if="availability?.is_closed"
          class="rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"
        >
          {{ availability.closed_reason || 'Venue tutup pada tanggal yang dipilih.' }}
        </div>

        <div v-else-if="availability" class="space-y-6">
          <div
            v-for="court in availability.courts"
            :key="court.court_id"
            class="rounded-lg border border-stone-200 p-4 space-y-3"
          >
            <div class="flex flex-wrap items-center justify-between gap-2">
              <div>
                <h3 class="font-bold text-stone-900">
                  {{ court.court_name }} (Kode: {{ court.court_code }})
                </h3>
                <p class="text-xs text-stone-500">Tipe: {{ formatCourtType(court.court_type) }}</p>
              </div>
              <button
                type="button"
                class="rounded-md border border-terracotta-700 px-2.5 py-1 text-xs font-semibold text-terracotta-800 hover:bg-terracotta-50"
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
                class="rounded-md border p-2 text-left text-xs transition"
                :class="{
                  'border-terracotta-700 bg-terracotta-700 text-white font-semibold ring-2 ring-terracotta-700/30':
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
                  <span class="font-semibold">{{ slot.start_time }} – {{ slot.end_time }}</span>
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
      </div>

      <!-- Floating Bottom Bar saat ada slot yang dipilih (tanpa perlu scroll) -->
      <div
        v-if="selectedItems.length > 0"
        class="fixed bottom-4 left-4 right-4 z-40 mx-auto max-w-4xl rounded-xl border border-terracotta-700 bg-stone-900 px-5 py-3.5 text-white shadow-2xl flex flex-wrap items-center justify-between gap-4"
      >
        <div>
          <p class="text-xs text-terracotta-300 font-semibold uppercase">
            {{ selectedItems.length }} Slot Dipilih ({{ totalSelectedHours }} Jam)
          </p>
          <p class="text-base font-bold">
            Estimasi Total: {{ formatIdr(estimatedTotal) }}
          </p>
        </div>

        <div class="flex items-center gap-3">
          <button
            type="button"
            class="rounded-md border border-stone-600 px-3 py-2 text-xs font-medium text-stone-200 hover:bg-stone-800"
            @click="clearSelectedSlots"
          >
            Reset Pilihan
          </button>
          <button
            type="button"
            class="rounded-md bg-terracotta-600 px-5 py-2 text-sm font-semibold text-white hover:bg-terracotta-500"
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
            class="w-full max-w-xl max-h-[90vh] overflow-y-auto rounded-xl border border-stone-200 bg-white p-6 shadow-xl space-y-5"
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
                  <p class="text-xs text-stone-500">Total Tagihan Server</p>
                  <p class="text-xl font-bold text-terracotta-800">
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
              class="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-800"
            >
              {{ bookingError }}
            </div>

            <div v-if="quoteLoading" class="py-6 text-center text-sm text-stone-600">
              Memvalidasi ketersediaan seluruh slot & menghitung harga...
            </div>

            <template v-else-if="quote">
              <!-- Daftar Sesi/Lapangan yang telah digabung otomatis -->
              <div class="rounded-lg bg-stone-50 p-3.5 space-y-2 text-xs">
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
                  <span class="font-semibold text-terracotta-800">{{ formatIdr(line.total_price) }}</span>
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
                    placeholder="Masukkan nama pemesan atau nama komunitas/turnamen"
                    class="mt-1 block w-full rounded-md border border-stone-300 bg-white px-3 py-2 text-sm focus:border-terracotta-600 focus:outline-none"
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
                      class="mt-1 block w-full rounded-md border border-stone-300 bg-white px-3 py-2 text-sm focus:border-terracotta-600 focus:outline-none"
                    />
                  </div>

                  <div>
                    <label class="block text-xs font-medium text-stone-700">Email (Opsional)</label>
                    <input
                      v-model="customerForm.customer_email"
                      type="email"
                      placeholder="email@contoh.com"
                      class="mt-1 block w-full rounded-md border border-stone-300 bg-white px-3 py-2 text-sm focus:border-terracotta-600 focus:outline-none"
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
                      class="block flex-1 rounded-md border border-stone-300 bg-white px-3 py-2 font-mono text-sm uppercase focus:border-terracotta-600 focus:outline-none"
                    />
                    <button
                      type="button"
                      class="rounded-md border border-terracotta-600 px-3 py-2 text-xs font-semibold text-terracotta-700 hover:bg-terracotta-50"
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
                  <label class="block text-xs font-medium text-stone-700">
                    Catatan (Misal: Sparring Komunitas / Turnamen)
                  </label>
                  <input
                    v-model="customerForm.notes"
                    type="text"
                    placeholder="Catatan opsional"
                    class="mt-1 block w-full rounded-md border border-stone-300 bg-white px-3 py-2 text-sm focus:border-terracotta-600 focus:outline-none"
                  />
                </div>

                <div class="flex items-center justify-end gap-3 border-t border-stone-100 pt-4">
                  <button
                    type="button"
                    :disabled="holdSubmitting"
                    class="rounded-md border border-stone-300 bg-white px-4 py-2 text-sm font-medium text-stone-700 hover:bg-stone-100"
                    @click="closeBookingModal"
                  >
                    Ubah Pilihan Jam
                  </button>
                  <button
                    type="submit"
                    :disabled="holdSubmitting"
                    class="rounded-md bg-terracotta-700 px-5 py-2 text-sm font-semibold text-white hover:bg-terracotta-800 disabled:opacity-50"
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

      <!-- Jam Operasional & Harga -->
      <div class="grid gap-6 sm:grid-cols-2">
        <div class="rounded-lg border border-stone-200 bg-white p-6 space-y-4">
          <h2 class="text-lg font-bold text-stone-900">Jam Operasional</h2>
          <ul class="divide-y divide-stone-100 text-sm">
            <li
              v-for="item in venue.operating_hours"
              :key="item.day_of_week"
              class="py-2 flex items-center justify-between"
            >
              <span class="font-medium text-stone-700">{{ dayNames[item.day_of_week] }}</span>
              <span v-if="item.is_closed" class="text-red-700 font-medium">Tutup</span>
              <span v-else class="text-stone-900">
                {{ item.open_time.slice(0, 5) }} – {{ item.close_time.slice(0, 5) }} WITA
              </span>
            </li>
          </ul>
        </div>

        <div class="rounded-lg border border-stone-200 bg-white p-6 space-y-4">
          <h2 class="text-lg font-bold text-stone-900">Tarif Sewa Lapangan</h2>
          <ul class="divide-y divide-stone-100 text-sm">
            <li
              v-for="rule in venue.pricing_rules"
              :key="rule.id"
              class="py-2.5 flex items-center justify-between gap-4"
            >
              <div>
                <p class="font-medium text-stone-900">{{ rule.name }}</p>
                <p class="text-xs text-stone-500">
                  {{ rule.start_time.slice(0, 5) }} – {{ rule.end_time.slice(0, 5) }} WITA
                  <span v-if="rule.day_of_week !== null"> • {{ dayNames[rule.day_of_week] }}</span>
                </p>
              </div>
              <span class="font-semibold text-terracotta-800">
                {{ formatIdr(rule.price_per_hour) }} / jam
              </span>
            </li>
          </ul>
        </div>
      </div>
    </template>
  </section>
</template>
