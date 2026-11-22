<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ApiError, apiRequest } from '@/services/apiClient'

interface RevenueReport {
  currency: string
  paid_revenue: string
  pending_verification_amount: string
  unpaid_hold_amount: string
  refunded_amount: string
  total_bookings: number
  bookings_by_status: Record<string, number>
}

interface CourtUtilization {
  court_id: string
  court_code: string
  court_name: string
  court_type: string
  confirmed_sessions_count: number
  booked_minutes: number
  booked_hours: number
  revenue: string
}

interface AuditLogEntry {
  id: string
  action: string
  entity_type: string
  entity_id: string | null
  ip_address: string | null
  created_at: string
  actor?: {
    id: string
    name: string
    email: string
  } | null
}

const revenue = ref<RevenueReport | null>(null)
const utilization = ref<CourtUtilization[]>([])
const auditLogs = ref<AuditLogEntry[]>([])
const loading = ref(true)
const errorMessage = ref<string | null>(null)

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

async function loadReports(): Promise<void> {
  loading.value = true
  errorMessage.value = null
  try {
    const [revRes, utilRes, auditRes] = await Promise.all([
      apiRequest<RevenueReport>('/admin/reports/revenue'),
      apiRequest<CourtUtilization[]>('/admin/reports/utilization'),
      apiRequest<AuditLogEntry[]>('/admin/audit-logs'),
    ])
    revenue.value = revRes.data
    utilization.value = utilRes.data
    auditLogs.value = auditRes.data
  } catch (err) {
    errorMessage.value =
      err instanceof ApiError ? err.message : 'Gagal memuat laporan operasional.'
  } finally {
    loading.value = false
  }
}

onMounted(loadReports)
</script>

<template>
  <section class="space-y-6">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-stone-900">Laporan Pendapatan, Utilisasi & Audit Log</h1>
        <p class="text-sm text-stone-600">
          Rekonsiliasi transaksi nyata dari database PostgreSQL PADEL MAKASSAR.
        </p>
      </div>
      <button
        type="button"
        class="rounded-md border border-stone-300 bg-white px-3 py-1.5 text-sm font-medium text-stone-700 hover:bg-stone-100"
        @click="loadReports"
      >
        Segarkan Laporan
      </button>
    </div>

    <div
      v-if="errorMessage"
      class="rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-800"
    >
      {{ errorMessage }}
    </div>

    <div
      v-if="loading"
      class="rounded-lg border border-stone-200 bg-white p-8 text-center text-sm text-stone-600"
    >
      Memuat laporan transaksi...
    </div>

    <template v-else-if="revenue">
      <!-- Ringkasan Pendapatan -->
      <div class="grid gap-4 sm:grid-cols-4">
        <div class="rounded-lg border border-stone-200 bg-white p-5">
          <p class="text-xs text-stone-500">Pendapatan Terverifikasi (Lunas)</p>
          <p class="mt-1 text-xl font-bold text-terracotta-800">{{ formatIdr(revenue.paid_revenue) }}</p>
        </div>
        <div class="rounded-lg border border-stone-200 bg-white p-5">
          <p class="text-xs text-stone-500">Menunggu Verifikasi Bukti Transfer</p>
          <p class="mt-1 text-xl font-bold text-amber-700">
            {{ formatIdr(revenue.pending_verification_amount) }}
          </p>
        </div>
        <div class="rounded-lg border border-stone-200 bg-white p-5">
          <p class="text-xs text-stone-500">Hold Belum Bayar</p>
          <p class="mt-1 text-xl font-bold text-stone-700">
            {{ formatIdr(revenue.unpaid_hold_amount) }}
          </p>
        </div>
        <div class="rounded-lg border border-stone-200 bg-white p-5">
          <p class="text-xs text-stone-500">Total Reservasi</p>
          <p class="mt-1 text-xl font-bold text-stone-900">{{ revenue.total_bookings }} Booking</p>
        </div>
      </div>

      <!-- Utilisasi per Lapangan -->
      <div class="rounded-lg border border-stone-200 bg-white p-6 space-y-4">
        <h2 class="text-lg font-bold text-stone-900">Utilisasi Pemakaian Lapangan</h2>
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-stone-200 text-sm">
            <thead class="bg-stone-50 text-left text-xs font-semibold uppercase text-stone-600">
              <tr>
                <th class="px-4 py-2.5">Kode</th>
                <th class="px-4 py-2.5">Nama Lapangan</th>
                <th class="px-4 py-2.5">Sesi Terkonfirmasi</th>
                <th class="px-4 py-2.5">Total Jam Terpakai</th>
                <th class="px-4 py-2.5 text-right">Kontribusi Pendapatan</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
              <tr v-for="u in utilization" :key="u.court_id">
                <td class="px-4 py-3 font-mono font-semibold">{{ u.court_code }}</td>
                <td class="px-4 py-3 font-medium text-stone-900">{{ u.court_name }}</td>
                <td class="px-4 py-3">{{ u.confirmed_sessions_count }} sesi</td>
                <td class="px-4 py-3">{{ u.booked_hours }} jam ({{ u.booked_minutes }} menit)</td>
                <td class="px-4 py-3 text-right font-semibold text-terracotta-800">
                  {{ formatIdr(u.revenue) }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Audit Log -->
      <div class="rounded-lg border border-stone-200 bg-white p-6 space-y-4">
        <h2 class="text-lg font-bold text-stone-900">Audit Log Aktivitas Admin & Finance</h2>
        <div v-if="auditLogs.length === 0" class="text-sm text-stone-500">
          Belum ada catatan aktivitas di audit log.
        </div>
        <ul v-else class="divide-y divide-stone-100 text-xs">
          <li
            v-for="log in auditLogs"
            :key="log.id"
            class="py-2 flex flex-wrap items-center justify-between gap-2"
          >
            <div>
              <span class="font-mono font-semibold text-terracotta-800">{{ log.action }}</span>
              <span class="ml-2 text-stone-600">
                oleh <strong>{{ log.actor?.name || 'System' }}</strong> ({{ log.entity_type }})
              </span>
            </div>
            <span class="text-stone-400">{{ formatWita(log.created_at) }} WITA</span>
          </li>
        </ul>
      </div>
    </template>
  </section>
</template>
