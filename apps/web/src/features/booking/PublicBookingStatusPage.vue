<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import type { Booking, Payment } from '@/types/api'
import { ApiError, apiRequest } from '@/services/apiClient'

const route = useRoute()
const publicToken = String(route.params.token)

const booking = ref<Booking | null>(null)
const loading = ref(true)
const errorMessage = ref<string | null>(null)
const uploadSuccess = ref<string | null>(null)
const uploading = ref(false)

const senderBankName = ref('')
const senderAccountHolder = ref('')
const selectedFile = ref<File | null>(null)

const latestPayment = computed<Payment | null>(() => {
  return booking.value?.payments?.[0] ?? null
})

const bankInfo = computed(() => {
  const instr = latestPayment.value?.payment_instructions_json
  const venue = booking.value?.venue
  return {
    bankName: instr?.bank_name || venue?.bank_name || 'Bank Mandiri',
    accountNumber: instr?.bank_account_number || venue?.bank_account_number || '1520037845860',
    accountHolder: instr?.bank_account_holder || venue?.bank_account_holder || 'NURHASDI NANDAR',
    whatsapp: instr?.whatsapp_admin || venue?.phone || '082347838252',
  }
})

const whatsappConfirmUrl = computed(() => {
  if (!booking.value) return '#'
  const rawPhone = bankInfo.value.whatsapp.replace(/[^0-9]/g, '')
  const normalizedPhone = rawPhone.startsWith('0') ? `62${rawPhone.slice(1)}` : rawPhone
  const courtText = booking.value.items?.map((i) => i.court_name_snapshot).join(', ') || 'Lapangan'
  const message = `Halo Admin PADEL MAKASSAR, saya ingin konfirmasi pembayaran booking:\n\nKode Booking: *${booking.value.booking_code}*\nNama: ${booking.value.customer_name}\nLapangan: ${courtText}\nTotal Transfer: ${formatIdr(booking.value.total_amount)}\nTujuan: ${bankInfo.value.bankName} ${bankInfo.value.accountNumber} a.n. ${bankInfo.value.accountHolder}`
  return `https://wa.me/${normalizedPhone}?text=${encodeURIComponent(message)}`
})

function formatIdr(amount: string | number): string {
  return new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
  }).format(Number(amount))
}

function formatDateTimeWita(iso: string): string {
  return new Intl.DateTimeFormat('id-ID', {
    timeZone: 'Asia/Makassar',
    dateStyle: 'full',
    timeStyle: 'short',
  }).format(new Date(iso))
}

function statusLabel(status: Booking['status']): string {
  const labels: Record<Booking['status'], string> = {
    pending_payment: 'Menunggu Pembayaran / Verifikasi Admin',
    confirmed: 'Terkonfirmasi (Lunas)',
    checked_in: 'Sudah Check-In',
    completed: 'Selesai',
    cancelled: 'Dibatalkan',
    expired: 'Kedaluwarsa (Batas Waktu Habis)',
    no_show: 'Tidak Hadir',
    refund_pending: 'Proses Refund',
    refunded: 'Telah Direfund',
  }
  return labels[status] || status
}

function onFileChange(event: Event): void {
  const input = event.target as HTMLInputElement
  selectedFile.value = input.files?.[0] ?? null
}

async function fetchStatus(): Promise<void> {
  loading.value = true
  errorMessage.value = null
  try {
    const res = await apiRequest<Booking>(`/booking-status/${encodeURIComponent(publicToken)}`)
    booking.value = res.data
  } catch (err) {
    errorMessage.value =
      err instanceof ApiError ? err.message : 'Data reservasi tidak ditemukan.'
  } finally {
    loading.value = false
  }
}

async function submitTransferProof(): Promise<void> {
  if (!selectedFile.value) return
  uploading.value = true
  errorMessage.value = null
  uploadSuccess.value = null
  try {
    const formData = new FormData()
    formData.append('public_token', publicToken)
    formData.append('proof_file', selectedFile.value)
    if (senderBankName.value) formData.append('sender_bank_name', senderBankName.value)
    if (senderAccountHolder.value) {
      formData.append('sender_account_holder', senderAccountHolder.value)
    }

    await apiRequest<Payment>('/payment-proofs', {
      method: 'POST',
      body: formData,
    })

    uploadSuccess.value =
      'Bukti transfer berhasil diunggah! Admin kami akan segera memverifikasi pembayaran Anda.'
    selectedFile.value = null
    await fetchStatus()
  } catch (err) {
    errorMessage.value =
      err instanceof ApiError ? err.message : 'Gagal mengunggah bukti transfer.'
  } finally {
    uploading.value = false
  }
}

onMounted(fetchStatus)
</script>

<template>
  <section class="mx-auto max-w-2xl space-y-6">
    <div class="flex items-center justify-between">
      <RouterLink :to="{ name: 'home' }" class="text-sm font-medium text-terracotta-700 hover:underline">
        ← Kembali ke Halaman Booking
      </RouterLink>
      <button
        type="button"
        class="rounded-md border border-stone-300 bg-white px-3 py-1.5 text-xs font-medium text-stone-700 hover:bg-stone-100"
        @click="fetchStatus"
      >
        Perbarui Status
      </button>
    </div>

    <div
      v-if="loading"
      class="rounded-lg border border-stone-200 bg-white p-8 text-center text-sm text-stone-600"
    >
      Memuat detail reservasi...
    </div>

    <div
      v-else-if="errorMessage && !booking"
      class="rounded-lg border border-red-200 bg-red-50 p-6 text-sm text-red-800"
    >
      {{ errorMessage }}
    </div>

    <template v-else-if="booking">
      <div
        v-if="uploadSuccess"
        class="rounded-md border border-green-200 bg-green-50 p-4 text-sm text-green-800"
      >
        {{ uploadSuccess }}
      </div>

      <div
        v-if="errorMessage"
        class="rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-800"
      >
        {{ errorMessage }}
      </div>

      <!-- Detail Booking -->
      <div class="rounded-lg border border-stone-200 bg-white p-6 space-y-5">
        <div class="flex flex-wrap items-start justify-between gap-4 border-b border-stone-100 pb-4">
          <div>
            <p class="text-xs text-stone-500">Kode Booking</p>
            <h1 class="text-2xl font-mono font-bold text-stone-900">{{ booking.booking_code }}</h1>
          </div>
          <span
            class="rounded-full px-3 py-1 text-xs font-semibold"
            :class="{
              'bg-amber-100 text-amber-800': booking.status === 'pending_payment',
              'bg-green-100 text-green-800':
                booking.status === 'confirmed' ||
                booking.status === 'checked_in' ||
                booking.status === 'completed',
              'bg-red-100 text-red-800':
                booking.status === 'cancelled' || booking.status === 'expired',
            }"
          >
            {{ statusLabel(booking.status) }}
          </span>
        </div>

        <div
          v-if="booking.status === 'pending_payment' && booking.hold_expires_at"
          class="rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 space-y-1"
        >
          <p class="font-semibold">Batas Waktu Pembayaran (Hold Slot)</p>
          <p v-if="latestPayment?.has_proof && latestPayment?.status === 'pending'">
            Bukti transfer Anda sudah diterima dan sedang menunggu verifikasi Admin. Slot lapangan
            tetap diamankan untuk Anda.
          </p>
          <p v-else>
            Silakan lakukan transfer dan unggah bukti pembayaran sebelum
            <strong>{{ formatDateTimeWita(booking.hold_expires_at) }} WITA</strong>.
          </p>
        </div>

        <dl class="grid gap-4 sm:grid-cols-2 text-sm">
          <div>
            <dt class="text-xs text-stone-500">Nama Pemesan</dt>
            <dd class="font-semibold text-stone-900">{{ booking.customer_name }}</dd>
          </div>
          <div>
            <dt class="text-xs text-stone-500">Venue</dt>
            <dd class="font-semibold text-stone-900">{{ booking.venue?.name }}</dd>
          </div>
          <div>
            <dt class="text-xs text-stone-500">Waktu Mulai</dt>
            <dd class="font-semibold text-stone-900">
              {{ formatDateTimeWita(booking.start_at) }} WITA
            </dd>
          </div>
          <div>
            <dt class="text-xs text-stone-500">Waktu Selesai</dt>
            <dd class="font-semibold text-stone-900">
              {{ formatDateTimeWita(booking.end_at) }} WITA
            </dd>
          </div>
        </dl>

        <div class="border-t border-stone-100 pt-4 space-y-2">
          <h2 class="text-sm font-bold text-stone-900">Rincian Lapangan</h2>
          <div
            v-for="item in booking.items"
            :key="item.id"
            class="flex items-center justify-between rounded-md bg-stone-50 p-3 text-sm"
          >
            <div>
              <p class="font-semibold text-stone-900">
                {{ item.court_name_snapshot }} (Kode: {{ item.court_code_snapshot }})
              </p>
              <p class="text-xs text-stone-500">Durasi {{ item.duration_minutes }} menit</p>
            </div>
            <span class="font-semibold text-stone-900">{{ formatIdr(item.total_price) }}</span>
          </div>
        </div>

        <div class="border-t border-stone-200 pt-4 flex items-center justify-between text-base">
          <span class="font-bold text-stone-900">Total Transfer</span>
          <span class="text-xl font-bold text-terracotta-800">{{ formatIdr(booking.total_amount) }}</span>
        </div>
      </div>

      <!-- Instruksi Transfer Manual & Upload Bukti -->
      <div
        v-if="booking.status === 'pending_payment'"
        class="rounded-lg border border-stone-200 bg-white p-6 space-y-5"
      >
        <div>
          <h2 class="text-lg font-bold text-stone-900">Pembayaran Transfer Bank</h2>
        </div>

        <div class="rounded-lg border border-terracotta-200 bg-terracotta-50/60 p-4 space-y-2 text-sm">
          <div class="flex items-center justify-between">
            <span class="text-stone-600">Bank Tujuan</span>
            <span class="font-bold text-stone-900">{{ bankInfo.bankName }}</span>
          </div>
          <div class="flex items-center justify-between">
            <span class="text-stone-600">Nomor Rekening</span>
            <span class="font-mono text-base font-bold text-terracotta-900">
              {{ bankInfo.accountNumber }}
            </span>
          </div>
          <div class="flex items-center justify-between">
            <span class="text-stone-600">Atas Nama</span>
            <span class="font-bold text-stone-900">{{ bankInfo.accountHolder }}</span>
          </div>
          <div class="flex items-center justify-between border-t border-terracotta-200 pt-2">
            <span class="text-stone-600">Nominal Transfer</span>
            <span class="font-bold text-terracotta-800">{{ formatIdr(booking.total_amount) }}</span>
          </div>
        </div>

        <div
          v-if="latestPayment?.status === 'failed' && latestPayment.verification_notes"
          class="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-800"
        >
          <strong>Bukti transfer sebelumnya ditolak Admin:</strong>
          {{ latestPayment.verification_notes }}. Silakan unggah ulang bukti transfer yang sesuai.
        </div>

        <div
          v-if="latestPayment?.has_proof && latestPayment.status === 'pending'"
          class="rounded-md border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900 space-y-1"
        >
          <p class="font-semibold">Status Pembayaran: Menunggu Pemeriksaan Admin</p>
          <p class="text-xs">
            File terunggah: <strong>{{ latestPayment.proof_original_name }}</strong>
          </p>
        </div>

        <form class="space-y-4 border-t border-stone-100 pt-4" @submit.prevent="submitTransferProof">
          <h3 class="text-sm font-bold text-stone-900">
            {{
              latestPayment?.has_proof
                ? 'Unggah Ulang / Perbarui Bukti Transfer'
                : 'Unggah Bukti Transfer'
            }}
          </h3>

          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="block text-xs font-medium text-stone-700">
                Bank Pengirim (Opsional)
              </label>
              <input
                v-model="senderBankName"
                type="text"
                placeholder="Mis. Mandiri / BCA / BRI"
                class="mt-1 block w-full rounded-md border border-stone-300 px-3 py-1.5 text-sm"
              />
            </div>

            <div>
              <label class="block text-xs font-medium text-stone-700">
                Nama Pengirim di Rekening (Opsional)
              </label>
              <input
                v-model="senderAccountHolder"
                type="text"
                placeholder="Nama pemilik rekening pengirim"
                class="mt-1 block w-full rounded-md border border-stone-300 px-3 py-1.5 text-sm"
              />
            </div>
          </div>

          <div>
            <label class="block text-xs font-medium text-stone-700">
              File Bukti Transfer (JPG, PNG, WEBP, atau PDF — Maks 5MB) *
            </label>
            <input
              type="file"
              accept=".jpg,.jpeg,.png,.webp,.pdf"
              required
              class="mt-1 block w-full text-sm text-stone-700 file:mr-4 file:rounded-md file:border-0 file:bg-terracotta-50 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-terracotta-800 hover:file:bg-terracotta-100"
              @change="onFileChange"
            />
          </div>

          <div class="flex flex-wrap items-center justify-between gap-3 pt-2">
            <a
              :href="whatsappConfirmUrl"
              target="_blank"
              rel="noopener noreferrer"
              class="rounded-md border border-terracotta-700 px-4 py-2 text-xs font-semibold text-terracotta-800 hover:bg-terracotta-50"
            >
              Konfirmasi via WhatsApp Admin ({{ bankInfo.whatsapp }})
            </a>

            <button
              type="submit"
              :disabled="uploading || !selectedFile"
              class="rounded-md bg-terracotta-700 px-5 py-2 text-sm font-semibold text-white hover:bg-terracotta-800 disabled:opacity-50"
            >
              {{ uploading ? 'Mengunggah...' : 'Kirim Bukti Transfer' }}
            </button>
          </div>
        </form>
      </div>
    </template>
  </section>
</template>
