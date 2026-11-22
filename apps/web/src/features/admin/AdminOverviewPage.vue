<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import type { Booking, Payment } from '@/types/api'
import { ApiError, apiRequest } from '@/services/apiClient'
import AppIcon from '@/components/ui/AppIcon.vue'

interface RevenueSummary {
  paid_revenue: string
  pending_verification_amount: string
  unpaid_hold_amount: string
  total_bookings: number
  bookings_by_status: Record<string, number>
}

interface UtilizationSummary {
  court_id: string
  court_code: string
  court_name: string
  confirmed_sessions_count: number
  booked_hours: number
  revenue: string
}

const revenue = ref<RevenueSummary | null>(null)
const utilization = ref<UtilizationSummary[]>([])
const recentBookings = ref<Booking[]>([])
const pendingPayments = ref<Payment[]>([])
const loading = ref(true)
const errorMessage = ref<string | null>(null)

const confirmedCount = computed(() => {
  const map = revenue.value?.bookings_by_status || {}
  return (map.confirmed || 0) + (map.checked_in || 0) + (map.completed || 0)
})

const maxCourtHours = computed(() => {
  const max = Math.max(...utilization.value.map((c) => c.booked_hours), 1)
  return max
})

function courtProgressPercent(hours: number): number {
  if (hours <= 0) return 6
  return Math.min(100, Math.max(12, Math.round((hours / maxCourtHours.value) * 100)))
}

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
    case 'paid':
      return 'bg-emerald-50 text-emerald-700 border-emerald-200'
    case 'pending_payment':
    case 'pending':
      return 'bg-amber-50 text-amber-700 border-amber-200'
    default:
      return 'bg-stone-100 text-stone-600 border-stone-200'
  }
}

async function loadOverview(): Promise<void> {
  loading.value = true
  errorMessage.value = null
  try {
    const res = await apiRequest<{
      revenue: RevenueSummary
      utilization: UtilizationSummary[]
      recent_bookings: Booking[]
      pending_payments: Payment[]
    }>('/admin/dashboard-overview')
    revenue.value = res.data.revenue
    utilization.value = res.data.utilization
    recentBookings.value = res.data.recent_bookings
    pendingPayments.value = res.data.pending_payments
  } catch (err) {
    errorMessage.value =
      err instanceof ApiError ? err.message : 'Gagal memuat ringkasan dashboard.'
  } finally {
    loading.value = false
  }
}

onMounted(loadOverview)
</script>

<template>
  <section class="space-y-8">
    <!-- Top Header Banner -->
    <div
      class="rounded-2xl border border-stone-200/90 bg-white p-6 shadow-2xs flex flex-wrap items-center justify-between gap-4"
    >
      <div class="space-y-1">
        <div class="inline-flex items-center gap-2 rounded-full bg-terracotta-50 px-3 py-1 text-xs font-semibold text-terracotta-700">
          <span class="h-2 w-2 rounded-full bg-terracotta-600"></span>
          <span>PADEL MAKASSAR</span>
        </div>
        <h1 class="text-2xl font-bold tracking-tight text-stone-900">
          Ringkasan Dashboard
        </h1>
      </div>

      <div class="flex items-center gap-3">
        <button
          type="button"
          class="inline-flex items-center gap-1.5 rounded-xl border border-stone-200 bg-white px-3.5 py-2.5 text-xs font-semibold text-stone-700 hover:bg-stone-50"
          @click="loadOverview"
        >
          <AppIcon name="refresh" class="w-3.5 h-3.5" />
          <span>Segarkan</span>
        </button>
        <RouterLink
          :to="{ name: 'admin-bookings' }"
          class="inline-flex items-center gap-1.5 rounded-xl bg-terracotta-600 px-4 py-2.5 text-xs font-semibold text-white shadow-xs hover:bg-terracotta-700"
        >
          <AppIcon name="plus" class="w-3.5 h-3.5" />
          <span>Booking Manual / Walk-In</span>
        </RouterLink>
      </div>
    </div>

    <div
      v-if="errorMessage"
      class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800"
    >
      {{ errorMessage }}
    </div>

    <div
      v-if="loading"
      class="rounded-xl border border-stone-200 bg-white p-10 text-center text-sm text-stone-500"
    >
      Memuat metrik operasional...
    </div>

    <template v-else-if="revenue">
      <!-- 4 Kartu KPI Modern dengan Ikon Terracotta -->
      <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-2xl border border-stone-200/90 bg-white p-5 shadow-2xs space-y-3">
          <div class="flex items-center justify-between">
            <span class="text-xs font-semibold text-stone-500">Pendapatan Lunas</span>
            <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-terracotta-50 text-terracotta-600">
              <AppIcon name="credit-card" class="w-4 h-4" />
            </span>
          </div>
          <p class="text-2xl font-bold text-stone-900">{{ formatIdr(revenue.paid_revenue) }}</p>
          <div class="flex items-center gap-1.5 text-xs font-medium text-emerald-700">
            <AppIcon name="check" class="w-3.5 h-3.5" />
            <span>{{ confirmedCount }} sesi terkonfirmasi</span>
          </div>
        </div>

        <div class="rounded-2xl border border-stone-200/90 bg-white p-5 shadow-2xs space-y-3">
          <div class="flex items-center justify-between">
            <span class="text-xs font-semibold text-stone-500">Menunggu Verifikasi</span>
            <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-amber-50 text-amber-700">
              <AppIcon name="clock" class="w-4 h-4" />
            </span>
          </div>
          <p class="text-2xl font-bold text-terracotta-600">
            {{ formatIdr(revenue.pending_verification_amount) }}
          </p>
          <RouterLink
            :to="{ name: 'admin-payments' }"
            class="inline-block text-xs font-semibold text-terracotta-700 hover:underline"
          >
            {{ pendingPayments.length }} bukti transfer masuk →
          </RouterLink>
        </div>

        <div class="rounded-2xl border border-stone-200/90 bg-white p-5 shadow-2xs space-y-3">
          <div class="flex items-center justify-between">
            <span class="text-xs font-semibold text-stone-500">Slot Ditahan (Hold)</span>
            <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-stone-100 text-stone-600">
              <AppIcon name="calendar" class="w-4 h-4" />
            </span>
          </div>
          <p class="text-2xl font-bold text-stone-900">
            {{ formatIdr(revenue.unpaid_hold_amount) }}
          </p>
          <p class="text-xs text-stone-400">Hold checkout aktif (10 menit)</p>
        </div>

        <div class="rounded-2xl border border-stone-200/90 bg-white p-5 shadow-2xs space-y-3">
          <div class="flex items-center justify-between">
            <span class="text-xs font-semibold text-stone-500">Total Reservasi</span>
            <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-terracotta-50 text-terracotta-600">
              <AppIcon name="chart" class="w-4 h-4" />
            </span>
          </div>
          <p class="text-2xl font-bold text-stone-900">{{ revenue.total_bookings }}</p>
          <RouterLink
            :to="{ name: 'admin-promotions' }"
            class="inline-block text-xs font-semibold text-terracotta-700 hover:underline"
          >
            Kelola Promo & Voucher →
          </RouterLink>
        </div>
      </div>

      <!-- Visualisasi Utilisasi 2 Lapangan & Antrean Bukti Transfer -->
      <div class="grid gap-6 lg:grid-cols-12">
        <!-- Utilisasi Lapangan 1 & Lapangan 2 dengan Visual Bar -->
        <div class="lg:col-span-7 rounded-2xl border border-stone-200 bg-white p-6 shadow-2xs space-y-5">
          <div class="flex items-center justify-between">
            <div class="flex items-center gap-2.5">
              <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-terracotta-50 text-terracotta-600">
                <AppIcon name="court" class="w-4 h-4" />
              </span>
              <h2 class="text-base font-bold text-stone-900">Utilisasi Lapangan</h2>
            </div>
            <RouterLink
              :to="{ name: 'admin-reports' }"
              class="text-xs font-semibold text-terracotta-600 hover:underline"
            >
              Lihat Laporan →
            </RouterLink>
          </div>

          <div class="space-y-4">
            <div
              v-for="court in utilization"
              :key="court.court_id"
              class="rounded-xl border border-stone-200/80 bg-[#FAF8F6] p-4 space-y-3"
            >
              <div class="flex items-center justify-between">
                <div class="flex items-center gap-2.5">
                  <span
                    class="rounded-md bg-terracotta-600 px-2.5 py-0.5 font-mono text-xs font-bold text-white"
                  >
                    {{ court.court_code }}
                  </span>
                  <span class="font-bold text-stone-900">{{ court.court_name }}</span>
                  <span class="rounded-full bg-white border border-stone-200 px-2 py-0.5 text-[11px] text-stone-600">
                    Semi Indoor
                  </span>
                </div>
                <span class="text-sm font-bold text-terracotta-700">
                  {{ formatIdr(court.revenue) }}
                </span>
              </div>

              <!-- Progress Bar Visual -->
              <div class="w-full h-2.5 rounded-full bg-stone-200/80 overflow-hidden">
                <div
                  class="h-full rounded-full bg-terracotta-600 transition-all duration-500"
                  :style="{ width: `${courtProgressPercent(court.booked_hours)}%` }"
                ></div>
              </div>

              <div class="flex items-center justify-between text-xs text-stone-500">
                <span>{{ court.confirmed_sessions_count }} Sesi Terkonfirmasi</span>
                <span class="font-semibold text-stone-700">{{ court.booked_hours }} Jam Terpakai</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Antrean Verifikasi Transfer -->
        <div class="lg:col-span-5 rounded-2xl border border-stone-200 bg-white p-6 shadow-2xs space-y-5 flex flex-col justify-between">
          <div class="space-y-4">
            <div class="flex items-center justify-between">
              <div class="flex items-center gap-2.5">
                <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-terracotta-50 text-terracotta-600">
                  <AppIcon name="shield" class="w-4 h-4" />
                </span>
                <div>
                  <h2 class="text-base font-bold text-stone-900">Verifikasi Transfer</h2>
                  <p class="text-xs text-stone-500">Mandiri 1520037845860</p>
                </div>
              </div>
              <RouterLink
                :to="{ name: 'admin-payments' }"
                class="text-xs font-semibold text-terracotta-600 hover:underline"
              >
                Semua →
              </RouterLink>
            </div>

            <div
              v-if="pendingPayments.length === 0"
              class="rounded-xl border border-dashed border-stone-200 bg-[#FAF8F6] p-8 text-center space-y-1.5"
            >
              <p class="text-xs font-semibold text-stone-700">Antrean Bersih</p>
              <p class="text-xs text-stone-500">
                Belum ada bukti transfer baru yang menunggu verifikasi.
              </p>
            </div>

            <ul v-else class="divide-y divide-stone-100 text-sm">
              <li
                v-for="item in pendingPayments"
                :key="item.id"
                class="py-3 flex items-center justify-between gap-3"
              >
                <div>
                  <p class="font-mono text-xs font-bold text-stone-900">
                    {{ item.booking?.booking_code }}
                  </p>
                  <p class="text-xs text-stone-500">
                    {{ item.booking?.customer_name }} •
                    {{ item.sender_bank_name || 'Transfer Bank' }}
                  </p>
                </div>
                <div class="flex items-center gap-2.5">
                  <span class="text-xs font-bold text-stone-900">{{ formatIdr(item.amount) }}</span>
                  <RouterLink
                    :to="{ name: 'admin-payments' }"
                    class="rounded-lg bg-terracotta-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-terracotta-700"
                  >
                    Verifikasi
                  </RouterLink>
                </div>
              </li>
            </ul>
          </div>

          <div class="rounded-xl bg-terracotta-50/70 border border-terracotta-100 p-3.5 text-xs text-terracotta-900 flex items-center justify-between">
            <span>Rekening Tujuan Saat Ini: <strong>Mandiri a.n. NURHASDI NANDAR</strong></span>
            <RouterLink :to="{ name: 'admin-venues' }" class="font-bold underline">
              Ubah
            </RouterLink>
          </div>
        </div>
      </div>

      <!-- Tabel Reservasi Terbaru -->
      <div class="rounded-2xl border border-stone-200 bg-white shadow-2xs overflow-hidden">
        <div class="px-6 py-4 border-b border-stone-100 flex items-center justify-between">
          <div class="flex items-center gap-2.5">
            <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-terracotta-50 text-terracotta-600">
              <AppIcon name="calendar" class="w-4 h-4" />
            </span>
            <h2 class="text-base font-bold text-stone-900">Jadwal & Reservasi Terbaru</h2>
          </div>
          <RouterLink
            :to="{ name: 'admin-bookings' }"
            class="text-xs font-semibold text-terracotta-600 hover:underline"
          >
            Kelola Semua Booking →
          </RouterLink>
        </div>

        <div
          v-if="recentBookings.length === 0"
          class="p-10 text-center text-sm text-stone-500"
        >
          Belum ada reservasi tercatat.
        </div>

        <div v-else class="overflow-x-auto">
          <table class="min-w-full divide-y divide-stone-100 text-sm">
            <thead class="bg-[#FAF8F6] text-left text-xs font-semibold text-stone-500">
              <tr>
                <th class="px-6 py-3.5">Kode Booking</th>
                <th class="px-6 py-3.5">Pelanggan</th>
                <th class="px-6 py-3.5">Lapangan</th>
                <th class="px-6 py-3.5">Jadwal (WITA)</th>
                <th class="px-6 py-3.5">Total</th>
                <th class="px-6 py-3.5">Status</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
              <tr v-for="b in recentBookings" :key="b.id" class="hover:bg-stone-50/70">
                <td class="px-6 py-3.5 font-mono text-xs font-bold text-stone-900">
                  {{ b.booking_code }}
                </td>
                <td class="px-6 py-3.5">
                  <p class="font-medium text-stone-900">{{ b.customer_name }}</p>
                  <p class="text-xs text-stone-500">{{ b.customer_phone }}</p>
                </td>
                <td class="px-6 py-3.5 text-xs text-stone-700">
                  <span v-for="item in b.items" :key="item.id" class="block">
                    {{ item.court_name_snapshot }} ({{ item.duration_minutes }}m)
                  </span>
                </td>
                <td class="px-6 py-3.5 text-xs text-stone-600">
                  {{ formatWita(b.start_at) }}
                </td>
                <td class="px-6 py-3.5 font-semibold text-stone-900">
                  {{ formatIdr(b.total_amount) }}
                </td>
                <td class="px-6 py-3.5">
                  <span
                    class="inline-flex rounded-full border px-2.5 py-0.5 text-xs font-medium"
                    :class="statusBadgeClass(b.status)"
                  >
                    {{ b.status }}
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>
  </section>
</template>
