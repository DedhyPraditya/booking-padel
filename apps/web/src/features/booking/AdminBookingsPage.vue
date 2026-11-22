<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import type { Booking, Court, Venue } from '@/types/api'
import { ApiError, apiRequest } from '@/services/apiClient'
import { useAuthStore } from '@/stores/auth'
import AppIcon from '@/components/ui/AppIcon.vue'

const auth = useAuthStore()
const bookings = ref<Booking[]>([])
const venues = ref<Venue[]>([])
const courts = ref<Court[]>([])

const loading = ref(true)
const errorMessage = ref<string | null>(null)
const successMessage = ref<string | null>(null)

// Filters
const searchQuery = ref('')
const statusFilter = ref('')
const courtFilter = ref('')
const dateFilter = ref('')

const showManualModal = ref(false)
const creatingManual = ref(false)
const rescheduleTarget = ref<Booking | null>(null)
const rescheduling = ref(false)
const rescheduleForm = ref({
  court_id: '',
  date: new Date().toISOString().slice(0, 10),
  start_time: '09:00',
  duration_minutes: 60,
  reason: '',
})

const canCreateBooking = computed(() => auth.hasPermission('booking.create'))
const canUpdateBooking = computed(() => auth.hasPermission('booking.update'))
const canCancelBooking = computed(() => auth.hasPermission('booking.cancel'))

const hasActiveFilters = computed(
  () =>
    searchQuery.value.trim() !== '' ||
    statusFilter.value !== '' ||
    courtFilter.value !== '' ||
    dateFilter.value !== '',
)

// Instant client-side refinement on top of server query for zero-latency typing
const filteredBookings = computed(() => {
  const raw = searchQuery.value.trim().toLowerCase()
  const codeOrTerm = raw.includes('|') ? (raw.split('|')[1] ?? raw) : raw

  return bookings.value.filter((b) => {
    if (statusFilter.value && b.status !== statusFilter.value) return false
    if (courtFilter.value && !b.items?.some((i) => i.court_id === courtFilter.value)) return false
    if (!codeOrTerm) return true

    return (
      b.booking_code.toLowerCase().includes(codeOrTerm) ||
      b.customer_name.toLowerCase().includes(codeOrTerm) ||
      (b.customer_phone && b.customer_phone.toLowerCase().includes(codeOrTerm))
    )
  })
})

const manualForm = ref({
  venue_id: '',
  court_id: '',
  date: new Date().toISOString().slice(0, 10),
  start_time: '09:00',
  duration_minutes: 60,
  customer_name: '',
  customer_phone: '',
  customer_email: '',
  confirm_immediately: true,
})

function formatIdr(amount: string | number): string {
  return new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
  }).format(Number(amount))
}

function formatWita(iso: string): string {
  return new Intl.DateTimeFormat('id-ID', {
    timeZone: 'Asia/Makassar',
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(new Date(iso))
}

function statusBadgeClass(status: string): string {
  switch (status) {
    case 'confirmed':
    case 'checked_in':
    case 'completed':
      return 'bg-emerald-50 text-emerald-700 border-emerald-200'
    case 'pending_payment':
      return 'bg-amber-50 text-amber-700 border-amber-200'
    default:
      return 'bg-stone-100 text-stone-600 border-stone-200'
  }
}

function setTodayFilter(): void {
  dateFilter.value = new Date().toISOString().slice(0, 10)
  loadData()
}

function resetFilters(): void {
  searchQuery.value = ''
  statusFilter.value = ''
  courtFilter.value = ''
  dateFilter.value = ''
  loadData()
}

async function loadData(): Promise<void> {
  loading.value = true
  errorMessage.value = null
  try {
    const params = new URLSearchParams()
    if (statusFilter.value) params.set('status', statusFilter.value)
    if (courtFilter.value) params.set('court_id', courtFilter.value)
    if (dateFilter.value) params.set('date', dateFilter.value)
    if (searchQuery.value.trim()) params.set('search', searchQuery.value.trim())

    const qs = params.toString() ? `?${params.toString()}` : ''
    const [bookingsRes, venuesRes, courtsRes] = await Promise.all([
      apiRequest<Booking[]>(`/admin/bookings${qs}`),
      apiRequest<Venue[]>('/admin/venues'),
      apiRequest<Court[]>('/admin/courts'),
    ])
    bookings.value = bookingsRes.data
    venues.value = venuesRes.data
    courts.value = courtsRes.data

    if (!manualForm.value.venue_id && venues.value[0]) {
      manualForm.value.venue_id = venues.value[0].id
    }
    if (!manualForm.value.court_id && courts.value[0]) {
      manualForm.value.court_id = courts.value[0].id
    }
  } catch (err) {
    errorMessage.value =
      err instanceof ApiError ? err.message : 'Gagal memuat daftar reservasi.'
  } finally {
    loading.value = false
  }
}

async function createManualBooking(): Promise<void> {
  errorMessage.value = null
  successMessage.value = null
  creatingManual.value = true
  try {
    const startIso = `${manualForm.value.date}T${manualForm.value.start_time}:00+08:00`
    const startDate = new Date(startIso)
    const endDate = new Date(startDate.getTime() + manualForm.value.duration_minutes * 60000)

    const res = await apiRequest<Booking>('/admin/bookings', {
      method: 'POST',
      body: JSON.stringify({
        venue_id: manualForm.value.venue_id,
        court_id: manualForm.value.court_id,
        start_at: startDate.toISOString(),
        end_at: endDate.toISOString(),
        customer_name: manualForm.value.customer_name,
        customer_phone: manualForm.value.customer_phone,
        customer_email: manualForm.value.customer_email || null,
        confirm_immediately: manualForm.value.confirm_immediately,
      }),
    })
    bookings.value.unshift(res.data)
    manualForm.value.customer_name = ''
    manualForm.value.customer_phone = ''
    manualForm.value.customer_email = ''
    showManualModal.value = false
    successMessage.value = `Booking manual ${res.data.booking_code} berhasil dibuat.`
  } catch (err) {
    errorMessage.value =
      err instanceof ApiError ? err.message : 'Gagal membuat booking manual.'
  } finally {
    creatingManual.value = false
  }
}

async function changeStatus(booking: Booking, status: Booking['status']): Promise<void> {
  errorMessage.value = null
  successMessage.value = null
  try {
    const res = await apiRequest<Booking>(`/admin/bookings/${booking.id}/status`, {
      method: 'PATCH',
      body: JSON.stringify({
        status,
        reason: `Status diubah ke ${status} oleh petugas.`,
      }),
    })
    const idx = bookings.value.findIndex((b) => b.id === booking.id)
    if (idx !== -1) bookings.value[idx] = res.data
    successMessage.value = `Status booking ${booking.booking_code} diperbarui menjadi ${status}.`
  } catch (err) {
    errorMessage.value =
      err instanceof ApiError ? err.message : 'Gagal memperbarui status booking.'
  }
}

async function cancelBooking(booking: Booking): Promise<void> {
  errorMessage.value = null
  successMessage.value = null
  try {
    const res = await apiRequest<Booking>(`/admin/bookings/${booking.id}/cancel`, {
      method: 'POST',
      body: JSON.stringify({
        reason: 'Dibatalkan melalui dashboard admin.',
      }),
    })
    const idx = bookings.value.findIndex((b) => b.id === booking.id)
    if (idx !== -1) bookings.value[idx] = res.data
    successMessage.value = `Booking ${booking.booking_code} telah dibatalkan.`
  } catch (err) {
    errorMessage.value = err instanceof ApiError ? err.message : 'Gagal membatalkan booking.'
  }
}

function openRescheduleModal(booking: Booking): void {
  rescheduleTarget.value = booking
  const firstItem = booking.items?.[0]
  rescheduleForm.value = {
    court_id: firstItem?.court_id || courts.value[0]?.id || '',
    date: new Date(booking.start_at).toISOString().slice(0, 10),
    start_time: '09:00',
    duration_minutes: firstItem?.duration_minutes || 60,
    reason: 'Perubahan jadwal atas permintaan pelanggan.',
  }
}

async function submitReschedule(): Promise<void> {
  if (!rescheduleTarget.value) return
  rescheduling.value = true
  errorMessage.value = null
  successMessage.value = null
  try {
    const startIso = `${rescheduleForm.value.date}T${rescheduleForm.value.start_time}:00+08:00`
    const startDate = new Date(startIso)
    const endDate = new Date(startDate.getTime() + rescheduleForm.value.duration_minutes * 60000)

    const res = await apiRequest<Booking>(
      `/admin/bookings/${rescheduleTarget.value.id}/reschedule`,
      {
        method: 'POST',
        body: JSON.stringify({
          court_id: rescheduleForm.value.court_id,
          start_at: startDate.toISOString(),
          end_at: endDate.toISOString(),
          reason: rescheduleForm.value.reason,
        }),
      },
    )
    const idx = bookings.value.findIndex((b) => b.id === rescheduleTarget.value?.id)
    if (idx !== -1) bookings.value[idx] = res.data
    successMessage.value = `Jadwal booking ${res.data.booking_code} berhasil diubah.`
    rescheduleTarget.value = null
  } catch (err) {
    errorMessage.value =
      err instanceof ApiError ? err.message : 'Gagal mengubah jadwal booking.'
  } finally {
    rescheduling.value = false
  }
}

onMounted(loadData)
</script>

<template>
  <section class="space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-4">
      <div>
        <h1 class="text-2xl font-bold tracking-tight text-stone-900">Booking & Check-In</h1>
      </div>
      <div class="flex items-center gap-2.5">
        <button
          type="button"
          class="inline-flex items-center gap-1.5 rounded-xl border border-stone-200 bg-white px-3.5 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50"
          @click="loadData"
        >
          <AppIcon name="refresh" class="w-3.5 h-3.5" />
          <span>Segarkan</span>
        </button>
        <button
          v-if="canCreateBooking"
          type="button"
          class="inline-flex items-center gap-1.5 rounded-xl bg-terracotta-600 px-4 py-2 text-xs font-semibold text-white shadow-xs hover:bg-terracotta-700"
          @click="showManualModal = true"
        >
          <AppIcon name="plus" class="w-3.5 h-3.5" />
          <span>Booking Manual</span>
        </button>
      </div>
    </div>

    <!-- Filter Bar Pencarian Kode, Pelanggan, Lapangan, Tanggal, & Status -->
    <div class="rounded-2xl border border-stone-200 bg-white p-4 shadow-2xs space-y-3">
      <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-12">
        <!-- Input Cari Kode / Nama / WA -->
        <div class="lg:col-span-5 relative">
          <input
            v-model="searchQuery"
            type="text"
            placeholder="Cari Kode Booking (PM-...), Nama, atau No. WA..."
            class="w-full rounded-xl border border-stone-200 bg-[#FAF8F6] pl-9 pr-3 py-2 text-xs font-medium text-stone-900 placeholder:text-stone-400 focus:border-terracotta-600 focus:bg-white focus:outline-none"
            @keyup.enter="loadData"
          />
          <AppIcon name="search" class="w-4 h-4 text-stone-400 absolute left-3 top-2.5" />
        </div>

        <!-- Filter Lapangan -->
        <div class="lg:col-span-2">
          <select
            v-model="courtFilter"
            class="w-full rounded-xl border border-stone-200 bg-white px-3 py-2 text-xs font-medium text-stone-700 focus:border-terracotta-600 focus:outline-none"
            @change="loadData"
          >
            <option value="">Semua Lapangan</option>
            <option v-for="c in courts" :key="c.id" :value="c.id">
              {{ c.name }} ({{ c.code }})
            </option>
          </select>
        </div>

        <!-- Filter Status -->
        <div class="lg:col-span-2">
          <select
            v-model="statusFilter"
            class="w-full rounded-xl border border-stone-200 bg-white px-3 py-2 text-xs font-medium text-stone-700 focus:border-terracotta-600 focus:outline-none"
            @change="loadData"
          >
            <option value="">Semua Status</option>
            <option value="confirmed">Confirmed (Siap Check-In)</option>
            <option value="checked_in">Checked In (Sedang Main)</option>
            <option value="pending_payment">Pending Payment</option>
            <option value="completed">Completed</option>
            <option value="cancelled">Cancelled</option>
            <option value="expired">Expired</option>
          </select>
        </div>

        <!-- Filter Tanggal -->
        <div class="lg:col-span-3 flex items-center gap-2">
          <input
            v-model="dateFilter"
            type="date"
            class="flex-1 rounded-xl border border-stone-200 bg-white px-3 py-1.5 text-xs font-medium text-stone-700 focus:border-terracotta-600 focus:outline-none"
            @change="loadData"
          />
          <button
            type="button"
            class="rounded-xl border border-terracotta-200 bg-terracotta-50 px-2.5 py-2 text-xs font-semibold text-terracotta-700 hover:bg-terracotta-100"
            @click="setTodayFilter"
          >
            Hari Ini
          </button>
        </div>
      </div>

      <div class="flex flex-wrap items-center justify-between gap-2 pt-1 text-xs text-stone-500">
        <span>
          Menampilkan <strong>{{ filteredBookings.length }}</strong> reservasi
        </span>
        <div class="flex items-center gap-2">
          <button
            v-if="hasActiveFilters"
            type="button"
            class="font-semibold text-terracotta-700 hover:underline"
            @click="resetFilters"
          >
            Reset Semua Filter
          </button>
          <button
            type="button"
            class="rounded-lg bg-stone-900 px-3 py-1 text-xs font-semibold text-white hover:bg-stone-800"
            @click="loadData"
          >
            Cari di Database
          </button>
        </div>
      </div>
    </div>

    <div
      v-if="successMessage"
      class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800"
    >
      {{ successMessage }}
    </div>

    <div
      v-if="errorMessage && !showManualModal && !rescheduleTarget"
      class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800"
    >
      {{ errorMessage }}
    </div>

    <!-- Tabel Daftar Booking -->
    <div
      v-if="loading"
      class="rounded-xl border border-stone-200 bg-white p-10 text-center text-sm text-stone-500"
    >
      Memuat daftar booking...
    </div>

    <div
      v-else-if="filteredBookings.length === 0"
      class="rounded-xl border border-stone-200 bg-white p-10 text-center text-sm text-stone-500 space-y-2"
    >
      <p>Tidak ditemukan data booking yang cocok dengan filter pencarian.</p>
      <button
        v-if="hasActiveFilters"
        type="button"
        class="rounded-lg border border-stone-200 px-3 py-1.5 text-xs font-semibold text-terracotta-700 hover:bg-stone-50"
        @click="resetFilters"
      >
        Tampilkan Semua Booking
      </button>
    </div>

    <div v-else class="overflow-x-auto rounded-2xl border border-stone-200 bg-white shadow-2xs">
      <table class="min-w-full divide-y divide-stone-100 text-sm">
        <thead class="bg-[#FAF8F6] text-left text-xs font-semibold text-stone-500">
          <tr>
            <th class="px-5 py-3.5">Kode</th>
            <th class="px-5 py-3.5">Pelanggan</th>
            <th class="px-5 py-3.5">Lapangan</th>
            <th class="px-5 py-3.5">Jadwal (WITA)</th>
            <th class="px-5 py-3.5">Total</th>
            <th class="px-5 py-3.5">Status</th>
            <th class="px-5 py-3.5 text-right">Tindakan</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-stone-100">
          <tr v-for="b in filteredBookings" :key="b.id" class="hover:bg-stone-50/70">
            <td class="px-5 py-3.5 font-mono text-xs font-bold text-stone-900">
              {{ b.booking_code }}
            </td>
            <td class="px-5 py-3.5">
              <p class="font-medium text-stone-900">{{ b.customer_name }}</p>
              <p class="text-xs text-stone-500">{{ b.customer_phone }}</p>
            </td>
            <td class="px-5 py-3.5 text-xs">
              <span v-for="item in b.items" :key="item.id" class="block text-stone-800">
                {{ item.court_name_snapshot }} ({{ item.duration_minutes }}m)
              </span>
            </td>
            <td class="px-5 py-3.5 text-xs text-stone-600">
              {{ formatWita(b.start_at) }} – {{ formatWita(b.end_at) }}
            </td>
            <td class="px-5 py-3.5 font-semibold text-stone-900">
              {{ formatIdr(b.total_amount) }}
            </td>
            <td class="px-5 py-3.5">
              <span
                class="inline-flex rounded-full border px-2.5 py-0.5 text-xs font-medium"
                :class="statusBadgeClass(b.status)"
              >
                {{ b.status }}
              </span>
            </td>
            <td class="px-5 py-3.5 text-right space-x-2">
              <button
                v-if="canUpdateBooking && b.status === 'pending_payment'"
                type="button"
                class="rounded-md bg-terracotta-600 px-2.5 py-1 text-xs font-semibold text-white hover:bg-terracotta-700"
                @click="changeStatus(b, 'confirmed')"
              >
                Konfirmasi
              </button>
              <button
                v-if="canUpdateBooking && b.status === 'confirmed'"
                type="button"
                class="rounded-md bg-stone-900 px-2.5 py-1 text-xs font-semibold text-white hover:bg-stone-800"
                @click="changeStatus(b, 'checked_in')"
              >
                Check-In
              </button>
              <button
                v-if="canUpdateBooking && b.status === 'checked_in'"
                type="button"
                class="rounded-md bg-emerald-700 px-2.5 py-1 text-xs font-semibold text-white hover:bg-emerald-800"
                @click="changeStatus(b, 'completed')"
              >
                Selesai
              </button>
              <button
                v-if="
                  canUpdateBooking &&
                  (b.status === 'pending_payment' || b.status === 'confirmed')
                "
                type="button"
                class="rounded-md border border-terracotta-200 bg-terracotta-50 px-2.5 py-1 text-xs font-semibold text-terracotta-700 hover:bg-terracotta-100"
                @click="openRescheduleModal(b)"
              >
                Reschedule
              </button>
              <button
                v-if="
                  canCancelBooking &&
                  (b.status === 'pending_payment' || b.status === 'confirmed')
                "
                type="button"
                class="rounded-md border border-stone-200 px-2.5 py-1 text-xs font-medium text-stone-600 hover:bg-red-50 hover:text-red-700 hover:border-red-200"
                @click="cancelBooking(b)"
              >
                Batalkan
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Modal Buat Booking Manual -->
    <Teleport to="body">
      <div
        v-if="showManualModal"
        class="fixed inset-0 z-50 flex items-center justify-center bg-stone-900/50 p-4 backdrop-blur-xs"
        @click.self="showManualModal = false"
      >
        <form
          class="w-full max-w-lg rounded-xl border border-stone-200 bg-white p-6 shadow-xl space-y-4"
          @submit.prevent="createManualBooking"
        >
          <div class="flex items-center justify-between border-b border-stone-100 pb-3">
            <h2 class="text-base font-bold text-stone-900">
              Buat Booking Manual (Walk-In / WhatsApp)
            </h2>
            <button
              type="button"
              class="text-stone-400 hover:text-stone-700"
              @click="showManualModal = false"
            >
              ✕
            </button>
          </div>

          <div
            v-if="errorMessage"
            class="rounded-lg border border-red-200 bg-red-50 p-3 text-xs text-red-800"
          >
            {{ errorMessage }}
          </div>

          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="block text-xs font-medium text-stone-700">Lapangan</label>
              <select
                v-model="manualForm.court_id"
                required
                class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm"
              >
                <option v-for="c in courts" :key="c.id" :value="c.id">
                  {{ c.name }} ({{ c.code }})
                </option>
              </select>
            </div>

            <div>
              <label class="block text-xs font-medium text-stone-700">Tanggal (WITA)</label>
              <input
                v-model="manualForm.date"
                type="date"
                required
                class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm"
              />
            </div>

            <div>
              <label class="block text-xs font-medium text-stone-700">Jam Mulai (WITA)</label>
              <input
                v-model="manualForm.start_time"
                type="time"
                step="1800"
                required
                class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm"
              />
            </div>

            <div>
              <label class="block text-xs font-medium text-stone-700">Durasi</label>
              <select
                v-model.number="manualForm.duration_minutes"
                class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm"
              >
                <option :value="60">1 Jam (60 Menit)</option>
                <option :value="120">2 Jam (120 Menit)</option>
                <option :value="180">3 Jam (180 Menit)</option>
                <option :value="240">4 Jam (240 Menit)</option>
              </select>
            </div>

            <div>
              <label class="block text-xs font-medium text-stone-700">Nama Pelanggan *</label>
              <input
                v-model="manualForm.customer_name"
                type="text"
                required
                placeholder="Nama pemesan"
                class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm"
              />
            </div>

            <div>
              <label class="block text-xs font-medium text-stone-700">No. WhatsApp / HP *</label>
              <input
                v-model="manualForm.customer_phone"
                type="text"
                required
                placeholder="08..."
                class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm"
              />
            </div>
          </div>

          <label class="flex items-center gap-2 text-xs font-medium text-stone-700 pt-1">
            <input v-model="manualForm.confirm_immediately" type="checkbox" />
            Langsung tandai Terkonfirmasi & Lunas
          </label>

          <div class="flex items-center justify-end gap-3 border-t border-stone-100 pt-4">
            <button
              type="button"
              class="rounded-lg border border-stone-200 px-4 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50"
              @click="showManualModal = false"
            >
              Batal
            </button>
            <button
              type="submit"
              :disabled="creatingManual"
              class="rounded-lg bg-terracotta-600 px-5 py-2 text-xs font-semibold text-white hover:bg-terracotta-700 disabled:opacity-50"
            >
              {{ creatingManual ? 'Menyimpan...' : 'Simpan Booking' }}
            </button>
          </div>
        </form>
      </div>
    </Teleport>

    <!-- Modal Reschedule Booking -->
    <Teleport to="body">
      <div
        v-if="rescheduleTarget"
        class="fixed inset-0 z-50 flex items-center justify-center bg-stone-900/50 p-4 backdrop-blur-xs"
        @click.self="rescheduleTarget = null"
      >
        <form
          class="w-full max-w-md rounded-2xl border border-stone-200 bg-white p-6 shadow-xl space-y-4"
          @submit.prevent="submitReschedule"
        >
          <div class="flex items-center justify-between border-b border-stone-100 pb-3">
            <div>
              <h2 class="text-base font-bold text-stone-900">
                Ubah Jadwal (Reschedule) — {{ rescheduleTarget.booking_code }}
              </h2>
              <p class="text-xs text-stone-500">Pelanggan: {{ rescheduleTarget.customer_name }}</p>
            </div>
            <button
              type="button"
              class="text-stone-400 hover:text-stone-700"
              @click="rescheduleTarget = null"
            >
              ✕
            </button>
          </div>

          <div
            v-if="errorMessage"
            class="rounded-lg border border-red-200 bg-red-50 p-3 text-xs text-red-800"
          >
            {{ errorMessage }}
          </div>

          <div class="grid gap-3 sm:grid-cols-2">
            <div>
              <label class="block text-xs font-medium text-stone-700">Lapangan Baru</label>
              <select
                v-model="rescheduleForm.court_id"
                required
                class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm"
              >
                <option v-for="c in courts" :key="c.id" :value="c.id">
                  {{ c.name }} ({{ c.code }})
                </option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-medium text-stone-700">Tanggal Baru (WITA)</label>
              <input
                v-model="rescheduleForm.date"
                type="date"
                required
                class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm"
              />
            </div>
            <div>
              <label class="block text-xs font-medium text-stone-700">Jam Mulai Baru (WITA)</label>
              <input
                v-model="rescheduleForm.start_time"
                type="time"
                step="1800"
                required
                class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm"
              />
            </div>
            <div>
              <label class="block text-xs font-medium text-stone-700">Durasi</label>
              <select
                v-model.number="rescheduleForm.duration_minutes"
                class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm"
              >
                <option :value="60">1 Jam (60 Menit)</option>
                <option :value="120">2 Jam (120 Menit)</option>
                <option :value="180">3 Jam (180 Menit)</option>
              </select>
            </div>
          </div>

          <div>
            <label class="block text-xs font-medium text-stone-700">Alasan Perubahan Jadwal</label>
            <input
              v-model="rescheduleForm.reason"
              type="text"
              required
              class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm"
            />
          </div>

          <div class="flex items-center justify-end gap-3 border-t border-stone-100 pt-4">
            <button
              type="button"
              class="rounded-lg border border-stone-200 px-4 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50"
              @click="rescheduleTarget = null"
            >
              Batal
            </button>
            <button
              type="submit"
              :disabled="rescheduling"
              class="rounded-lg bg-terracotta-600 px-5 py-2 text-xs font-semibold text-white hover:bg-terracotta-700 disabled:opacity-50"
            >
              {{ rescheduling ? 'Memindahkan Jadwal...' : 'Simpan Jadwal Baru' }}
            </button>
          </div>
        </form>
      </div>
    </Teleport>
  </section>
</template>
