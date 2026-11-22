<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import type { Payment } from '@/types/api'
import { ApiError, apiRequest, getStoredToken } from '@/services/apiClient'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const payments = ref<Payment[]>([])
const loading = ref(true)
const errorMessage = ref<string | null>(null)
const successMessage = ref<string | null>(null)
const statusFilter = ref('')
const processingId = ref<string | null>(null)
const rejectNotes = ref<Record<string, string>>({})
const previewBlobUrl = ref<string | null>(null)
const previewPaymentCode = ref<string | null>(null)

const canVerifyPayment = computed(() => auth.hasPermission('payment.verify'))

function formatIdr(amount: string | number): string {
  return new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
  }).format(Number(amount))
}

function formatWita(iso: string | null): string {
  if (!iso) return '-'
  return new Intl.DateTimeFormat('id-ID', {
    timeZone: 'Asia/Makassar',
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(new Date(iso))
}

async function loadPayments(): Promise<void> {
  loading.value = true
  errorMessage.value = null
  try {
    const query = statusFilter.value ? `?status=${encodeURIComponent(statusFilter.value)}` : ''
    const res = await apiRequest<Payment[]>(`/admin/payments${query}`)
    payments.value = res.data
  } catch (err) {
    errorMessage.value =
      err instanceof ApiError ? err.message : 'Gagal memuat daftar pembayaran.'
  } finally {
    loading.value = false
  }
}

async function viewProof(payment: Payment): Promise<void> {
  errorMessage.value = null
  try {
    const token = getStoredToken()
    const response = await fetch(`/api/v1/admin/payments/${payment.id}/proof`, {
      headers: token ? { Authorization: `Bearer ${token}` } : {},
    })
    if (!response.ok) {
      throw new Error('Gagal membuka file bukti transfer.')
    }
    const blob = await response.blob()
    if (previewBlobUrl.value) {
      URL.revokeObjectURL(previewBlobUrl.value)
    }
    previewBlobUrl.value = URL.createObjectURL(blob)
    previewPaymentCode.value = payment.booking?.booking_code || payment.provider_reference
  } catch (err) {
    errorMessage.value =
      err instanceof Error ? err.message : 'Gagal memuat bukti transfer.'
  }
}

function closePreview(): void {
  if (previewBlobUrl.value) {
    URL.revokeObjectURL(previewBlobUrl.value)
  }
  previewBlobUrl.value = null
  previewPaymentCode.value = null
}

function buildCustomerWhatsappVoucherUrl(payment: Payment): string {
  const b = payment.booking
  if (!b || !b.customer_phone) return '#'
  const raw = b.customer_phone.replace(/[^0-9]/g, '')
  const phone = raw.startsWith('0') ? `62${raw.slice(1)}` : raw
  const courts = b.items?.map((i) => `${i.court_name_snapshot} (${i.duration_minutes}m)`).join(', ') || 'Lapangan'
  const lookupUrl = `${window.location.origin}/booking-lookup`
  const msg = `Halo *${b.customer_name}*,\n\nPembayaran Anda di *PADEL MAKASSAR* telah *TERVERIFIKASI (LUNAS)*.\n\n*E-VOUCHER RESMI:*\n• Kode Booking: *${b.booking_code}*\n• Lapangan: ${courts}\n• Jadwal: ${formatWita(b.start_at)} WITA\n• Total Lunas: ${formatIdr(payment.amount)}\n\nAnda dapat melihat & mengunduh E-Voucher PDF (QR Check-In) kapan saja di:\n${lookupUrl}\n(Masukkan Kode Booking *${b.booking_code}* dan No. WA Anda).\n\nSampai jumpa di lapangan!`
  return `https://wa.me/${phone}?text=${encodeURIComponent(msg)}`
}

async function verifyPayment(payment: Payment, decision: 'approve' | 'reject'): Promise<void> {
  errorMessage.value = null
  successMessage.value = null
  const notes = rejectNotes.value[payment.id]?.trim() || ''

  if (decision === 'reject' && !notes) {
    errorMessage.value = 'Isi alasan penolakan terlebih dahulu pada kolom catatan sebelum menolak.'
    return
  }

  processingId.value = payment.id
  try {
    const res = await apiRequest<Payment>(`/admin/payments/${payment.id}/verify`, {
      method: 'POST',
      body: JSON.stringify({
        decision,
        notes:
          notes ||
          (decision === 'approve'
            ? 'Mutasi rekening Mandiri terverifikasi sesuai.'
            : 'Bukti transfer ditolak.'),
      }),
    })
    const idx = payments.value.findIndex((p) => p.id === payment.id)
    if (idx !== -1) payments.value[idx] = res.data

    successMessage.value =
      decision === 'approve'
        ? `Pembayaran ${payment.booking?.booking_code || ''} diterima (LUNAS) dan booking otomatis TERKONFIRMASI.`
        : `Bukti transfer ${payment.booking?.booking_code || ''} ditolak.`
  } catch (err) {
    errorMessage.value =
      err instanceof ApiError ? err.message : 'Gagal memproses verifikasi pembayaran.'
  } finally {
    processingId.value = null
  }
}

onMounted(loadPayments)
</script>

<template>
  <section class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
      <div>
        <h1 class="text-2xl font-bold text-stone-900">Verifikasi Pembayaran</h1>
      </div>
      <div class="flex items-center gap-3">
        <select
          v-model="statusFilter"
          class="rounded-md border border-stone-300 bg-white px-3 py-1.5 text-sm"
          @change="loadPayments"
        >
          <option value="">Semua Status</option>
          <option value="pending">Menunggu Verifikasi (Ada Bukti)</option>
          <option value="unpaid">Belum Unggah Bukti</option>
          <option value="paid">Lunas (Paid)</option>
          <option value="failed">Ditolak (Failed)</option>
          <option value="refunded">Refunded</option>
        </select>
        <button
          type="button"
          class="rounded-md border border-stone-300 bg-white px-3 py-1.5 text-sm font-medium text-stone-700 hover:bg-stone-100"
          @click="loadPayments"
        >
          Segarkan
        </button>
      </div>
    </div>

    <div
      v-if="successMessage"
      class="rounded-md border border-green-200 bg-green-50 p-4 text-sm text-green-800"
    >
      {{ successMessage }}
    </div>

    <div
      v-if="errorMessage"
      class="rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-800"
    >
      {{ errorMessage }}
    </div>

    <!-- Modal Preview Bukti Transfer -->
    <div
      v-if="previewBlobUrl"
      class="rounded-lg border-2 border-terracotta-700 bg-white p-4 space-y-3 shadow-md"
    >
      <div class="flex items-center justify-between border-b border-stone-100 pb-2">
        <h2 class="text-sm font-bold text-stone-900">
          Preview Bukti Transfer — Booking {{ previewPaymentCode }}
        </h2>
        <button
          type="button"
          class="rounded bg-stone-200 px-2.5 py-1 text-xs font-semibold text-stone-800 hover:bg-stone-300"
          @click="closePreview"
        >
          Tutup Preview
        </button>
      </div>
      <div class="max-h-96 overflow-auto flex justify-center bg-stone-50 p-3 rounded">
        <img :src="previewBlobUrl" alt="Bukti Transfer" class="max-h-80 object-contain" />
      </div>
    </div>

    <div
      v-if="loading"
      class="rounded-lg border border-stone-200 bg-white p-8 text-center text-sm text-stone-600"
    >
      Memuat daftar transaksi pembayaran...
    </div>

    <div
      v-else-if="payments.length === 0"
      class="rounded-lg border border-stone-200 bg-white p-8 text-center text-sm text-stone-600"
    >
      Belum ada data pembayaran untuk filter ini.
    </div>

    <div v-else class="space-y-4">
      <article
        v-for="p in payments"
        :key="p.id"
        class="rounded-lg border border-stone-200 bg-white p-5 space-y-3"
      >
        <div class="flex flex-wrap items-start justify-between gap-4 border-b border-stone-100 pb-3">
          <div>
            <div class="flex items-center gap-2">
              <span class="font-mono text-base font-bold text-stone-900">
                {{ p.booking?.booking_code || p.provider_reference }}
              </span>
              <span
                class="rounded-full px-2.5 py-0.5 text-xs font-semibold"
                :class="{
                  'bg-amber-100 text-amber-800': p.status === 'pending',
                  'bg-stone-100 text-stone-700': p.status === 'unpaid',
                  'bg-green-100 text-green-800': p.status === 'paid',
                  'bg-red-100 text-red-800': p.status === 'failed' || p.status === 'expired',
                }"
              >
                {{ p.status.toUpperCase() }}
              </span>
            </div>
            <p class="mt-0.5 text-sm text-stone-700">
              Pemesan: <strong>{{ p.booking?.customer_name }}</strong> ({{
                p.booking?.customer_phone
              }})
            </p>
          </div>

          <div class="text-right">
            <p class="text-xs text-stone-500">Nominal Tagihan</p>
            <p class="text-lg font-bold text-terracotta-800">{{ formatIdr(p.amount) }}</p>
          </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-3 text-xs text-stone-600">
          <div>
            <span class="text-stone-400 block">Info Pengirim</span>
            <span v-if="p.sender_bank_name || p.sender_account_holder" class="font-medium text-stone-800">
              {{ p.sender_bank_name || '-' }} • a.n. {{ p.sender_account_holder || '-' }}
            </span>
            <span v-else>Belum diisi</span>
          </div>
          <div>
            <span class="text-stone-400 block">Waktu Unggah Bukti</span>
            <span class="font-medium text-stone-800">{{ formatWita(p.proof_uploaded_at) }}</span>
          </div>
          <div>
            <span class="text-stone-400 block">Catatan Verifikasi</span>
            <span class="font-medium text-stone-800">{{ p.verification_notes || '-' }}</span>
          </div>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 pt-2 border-t border-stone-100">
          <div>
            <button
              v-if="p.has_proof"
              type="button"
              class="rounded-md border border-terracotta-700 px-3 py-1.5 text-xs font-semibold text-terracotta-800 hover:bg-terracotta-50"
              @click="viewProof(p)"
            >
              Lihat Bukti Transfer ({{ p.proof_original_name }})
            </button>
            <span v-else class="text-xs text-stone-400">
              Pelanggan belum mengunggah file bukti transfer.
            </span>
          </div>

          <div
            v-if="canVerifyPayment && p.status !== 'paid' && p.status !== 'refunded'"
            class="flex flex-wrap items-center gap-2"
          >
            <input
              v-model="rejectNotes[p.id]"
              type="text"
              placeholder="Catatan / alasan tolak..."
              class="rounded-md border border-stone-300 px-2.5 py-1 text-xs"
            />
            <button
              type="button"
              :disabled="processingId === p.id"
              class="rounded-md bg-green-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-green-800 disabled:opacity-50"
              @click="verifyPayment(p, 'approve')"
            >
              Terima & Konfirmasi Booking
            </button>
            <button
              type="button"
              :disabled="processingId === p.id"
              class="rounded-md border border-red-300 px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-50 disabled:opacity-50"
              @click="verifyPayment(p, 'reject')"
            >
              Tolak Bukti
            </button>
          </div>

          <div v-else-if="p.status === 'paid' && p.booking?.customer_phone">
            <a
              :href="buildCustomerWhatsappVoucherUrl(p)"
              target="_blank"
              rel="noopener noreferrer"
              class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-700 px-3.5 py-1.5 text-xs font-semibold text-white hover:bg-emerald-800"
            >
              Kirim E-Voucher ke WA Pelanggan ↗
            </a>
          </div>
        </div>
      </article>
    </div>
  </section>
</template>
