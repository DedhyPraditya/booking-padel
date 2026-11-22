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

const selectedDate = ref(new Date().toISOString().slice(0, 10))
const selectedDuration = ref(60)
const availability = ref<DailyAvailability | null>(null)
const availabilityLoading = ref(false)

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
    held: 'Ditahan',
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

function pushSlot(court: CourtAvailability, slot: AvailabilitySlot): void {
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
    bookingError.value = 'Slot beririsan dengan jam lain yang sudah dipilih di lapangan yang sama.'
    return
  }

  pushSlot(court, slot)
}

function selectAllAvailableForCourt(court: CourtAvailability): void {
  bookingError.value = null
  for (const slot of court.slots) {
    if (!slot.available) continue
    if (isSlotSelected(court.court_id, slot.start_at)) continue
    if (overlapsWithExistingOnSameCourt(court.court_id, slot.start_at, slot.end_at)) continue
    pushSlot(court, slot)
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
  if (e.key === 'Escape' && isModalOpen.value) closeBookingModal()
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
      err instanceof ApiError ? err.message : 'Salah satu slot tidak lagi tersedia.'
    await loadAvailability()
  } finally {
    quoteLoading.value = false
  }
}

async function loadVenue(): Promise<void> {
  loading.value = true
  errorMessage.value = null
  try {
    const res = await apiRequest<Venue>('/venues/padel-makassar')
    venue.value = res.data
    await loadAvailability()
  } catch {
    try {
      const list = await apiRequest<Venue[]>('/venues')
      const first = list.data[0]
      if (first) {
        const res = await apiRequest<Venue>(`/venues/${encodeURIComponent(first.slug)}`)
        venue.value = res.data
        await loadAvailability()
      }
    } catch (err) {
      errorMessage.value = err instanceof ApiError ? err.message : 'Gagal memuat data venue.'
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
    bookingError.value = err instanceof ApiError ? err.message : 'Gagal memuat jadwal.'
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
    await router.push({ name: 'booking-status', params: { token: res.data.public_token } })
  } catch (err) {
    bookingError.value =
      err instanceof ApiError ? err.message : 'Gagal membuat reservasi. Slot mungkin baru diambil.'
    await loadAvailability()
  } finally {
    holdSubmitting.value = false
  }
}

onMounted(() => {
  window.addEventListener('keydown', handleKeydown)
  loadVenue()
})

onBeforeUnmount(() => {
  window.removeEventListener('keydown', handleKeydown)
})
</script>

<template>
  <section class="space-y-6 pb-24">
    <div class="flex flex-wrap items-end justify-between gap-4">
      <div>
        <p class="text-xs font-bold uppercase tracking-wider text-terracotta-600">
          {{ venue?.name || 'PADEL MAKASSAR' }}
        </p>
        <h1 class="mt-1 text-3xl font-extrabold tracking-tight text-stone-900">Booking Lapangan</h1>
      </div>

      <div v-if="venue" class="flex flex-wrap items-end gap-3">
        <div>
          <label class="block text-xs font-medium text-stone-700">Tanggal</label>
          <input
            v-model="selectedDate"
            type="date"
            class="mt-1 rounded-xl border border-stone-300 bg-white px-3 py-2 text-xs font-semibold"
            @change="loadAvailability"
          />
        </div>
        <div>
          <label class="block text-xs font-medium text-stone-700">Blok Slot</label>
          <select
            v-model.number="selectedDuration"
            class="mt-1 rounded-xl border border-stone-300 bg-white px-3 py-2 text-xs font-semibold"
            @change="loadAvailability"
          >
            <option :value="60">Per 1 Jam</option>
            <option :value="120">Per 2 Jam</option>
            <option :value="180">Per 3 Jam</option>
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
      v-if="loading"
      class="rounded-2xl border border-stone-200 bg-white p-12 text-center text-sm text-stone-500"
    >
      Memuat jadwal...
    </div>

    <div
      v-else-if="errorMessage"
      class="rounded-2xl border border-red-200 bg-red-50 p-6 text-sm text-red-800"
    >
      {{ errorMessage }}
    </div>

    <template v-else-if="venue">
      <div
        v-if="bookingError && !isModalOpen"
        class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800"
      >
        {{ bookingError }}
      </div>

      <div v-if="availabilityLoading" class="py-10 text-center text-sm text-stone-500">
        Menghitung slot tersedia...
      </div>

      <div
        v-else-if="availability?.is_closed"
        class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"
      >
        {{ availability.closed_reason || 'Venue tutup pada tanggal ini.' }}
      </div>

      <div v-else-if="availability" class="space-y-5">
        <div
          v-for="court in availability.courts"
          :key="court.court_id"
          class="rounded-2xl border border-stone-200 bg-white p-4 sm:p-5 space-y-3.5"
        >
          <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex items-center gap-2.5">
              <span
                class="rounded-md bg-terracotta-600 px-2.5 py-0.5 font-mono text-xs font-bold text-white"
              >
                {{ court.court_code }}
              </span>
              <h2 class="font-bold text-stone-900">{{ court.court_name }}</h2>
              <span class="text-xs text-stone-500">({{ formatCourtType(court.court_type) }})</span>
            </div>
            <button
              type="button"
              class="rounded-lg border border-terracotta-600 px-3 py-1 text-xs font-semibold text-terracotta-700 hover:bg-terracotta-50"
              @click="selectAllAvailableForCourt(court)"
            >
              + Pilih Semua Jam Tersedia
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
                <span v-if="isSlotSelected(court.court_id, slot.start_at)">✓</span>
              </div>
              <div class="mt-0.5 text-[11px]">
                <template v-if="slot.available">{{ formatIdr(slot.price) }}</template>
                <template v-else>{{ slotStatusText(slot.status) }}</template>
              </div>
            </button>
          </div>
        </div>
      </div>

      <!-- Floating bar -->
      <div
        v-if="selectedItems.length > 0"
        class="fixed bottom-4 left-4 right-4 z-40 mx-auto max-w-4xl rounded-2xl border border-terracotta-600 bg-stone-900 px-5 py-3.5 text-white shadow-2xl flex flex-wrap items-center justify-between gap-4"
      >
        <div>
          <p class="text-xs text-terracotta-300 font-semibold uppercase">
            {{ selectedItems.length }} Slot ({{ totalSelectedHours }} Jam)
          </p>
          <p class="text-base font-bold">Estimasi: {{ formatIdr(estimatedTotal) }}</p>
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
            Lanjut Booking →
          </button>
        </div>
      </div>

      <!-- Modal checkout -->
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
                  {{ selectedDate }}
                </p>
                <h3 class="mt-0.5 text-lg font-bold text-stone-900">
                  {{ quote ? `${quote.duration_minutes / 60} Jam Total` : 'Menghitung...' }}
                </h3>
              </div>
              <div class="flex items-start gap-3">
                <div class="text-right">
                  <p class="text-xs text-stone-500">Total Tagihan</p>
                  <p class="text-xl font-bold text-terracotta-700">
                    {{ quoteLoading ? '...' : quote ? formatIdr(quote.total_amount) : '-' }}
                  </p>
                </div>
                <button
                  type="button"
                  aria-label="Tutup"
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
              Memvalidasi slot & menghitung harga...
            </div>

            <template v-else-if="quote">
              <div class="rounded-xl bg-[#FAF8F6] p-3.5 space-y-2 text-xs">
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
                      {{ line.start_time }} – {{ line.end_time }} WITA
                    </span>
                  </div>
                  <span class="font-semibold text-terracotta-800">
                    {{ formatIdr(line.total_price) }}
                  </span>
                </div>
              </div>

              <form class="space-y-4" @submit.prevent="submitBookingHold">
                <div>
                  <label class="block text-xs font-medium text-stone-700">Nama Pemesan *</label>
                  <input
                    v-model="customerForm.customer_name"
                    type="text"
                    required
                    class="mt-1 block w-full rounded-xl border border-stone-300 bg-white px-3 py-2 text-sm focus:border-terracotta-600 focus:outline-none"
                  />
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                  <div>
                    <label class="block text-xs font-medium text-stone-700">No. WhatsApp *</label>
                    <input
                      v-model="customerForm.customer_phone"
                      type="text"
                      required
                      placeholder="08..."
                      class="mt-1 block w-full rounded-xl border border-stone-300 bg-white px-3 py-2 text-sm focus:border-terracotta-600 focus:outline-none"
                    />
                  </div>
                  <div>
                    <label class="block text-xs font-medium text-stone-700">Email</label>
                    <input
                      v-model="customerForm.customer_email"
                      type="email"
                      class="mt-1 block w-full rounded-xl border border-stone-300 bg-white px-3 py-2 text-sm focus:border-terracotta-600 focus:outline-none"
                    />
                  </div>
                </div>

                <div>
                  <label class="block text-xs font-medium text-stone-700">Kode Voucher</label>
                  <div class="mt-1 flex items-center gap-2">
                    <input
                      v-model="customerForm.promotion_code"
                      type="text"
                      class="block flex-1 rounded-xl border border-stone-300 bg-white px-3 py-2 font-mono text-sm uppercase focus:border-terracotta-600 focus:outline-none"
                    />
                    <button
                      type="button"
                      class="rounded-xl border border-terracotta-600 px-3.5 py-2 text-xs font-semibold text-terracotta-700 hover:bg-terracotta-50"
                      @click="openCheckoutModal"
                    >
                      Terapkan
                    </button>
                  </div>
                  <p
                    v-if="Number(quote.discount_amount) > 0"
                    class="mt-1 text-xs font-semibold text-emerald-700"
                  >
                    Diskon: -{{ formatIdr(quote.discount_amount) }}
                  </p>
                </div>

                <div>
                  <label class="block text-xs font-medium text-stone-700">Catatan</label>
                  <input
                    v-model="customerForm.notes"
                    type="text"
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
                    Ubah Jam
                  </button>
                  <button
                    type="submit"
                    :disabled="holdSubmitting"
                    class="rounded-xl bg-terracotta-600 px-5 py-2 text-sm font-semibold text-white hover:bg-terracotta-700 disabled:opacity-50"
                  >
                    {{
                      holdSubmitting
                        ? 'Mengunci Slot...'
                        : `Booking & Kunci Slot (${venue.hold_duration_minutes} mnt)`
                    }}
                  </button>
                </div>
              </form>
            </template>
          </div>
        </div>
      </Teleport>
    </template>
  </section>
</template>
