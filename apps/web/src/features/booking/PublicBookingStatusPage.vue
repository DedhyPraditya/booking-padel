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

const isConfirmedOrPaid = computed(() => {
  if (!booking.value) return false
  return ['confirmed', 'checked_in', 'completed'].includes(booking.value.status)
})

const qrCodeUrl = computed(() => {
  if (!booking.value) return ''
  const verifyPayload = `PADELMAKASSAR|${booking.value.booking_code}|${booking.value.customer_name}|${booking.value.status.toUpperCase()}`
  return `https://api.qrserver.com/v1/create-qr-code/?size=160x160&margin=6&data=${encodeURIComponent(verifyPayload)}`
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

function formatDateTimeWita(iso: string | null | undefined): string {
  if (!iso) return '-'
  return new Intl.DateTimeFormat('id-ID', {
    timeZone: 'Asia/Makassar',
    dateStyle: 'full',
    timeStyle: 'short',
  }).format(new Date(iso))
}

function statusLabel(status: Booking['status']): string {
  const labels: Record<Booking['status'], string> = {
    pending_payment: 'Menunggu Pembayaran / Verifikasi Admin',
    confirmed: 'TERKONFIRMASI (LUNAS)',
    checked_in: 'SUDAH CHECK-IN',
    completed: 'SELESAI',
    cancelled: 'Dibatalkan',
    expired: 'Kedaluwarsa (Batas Waktu Habis)',
    no_show: 'Tidak Hadir',
    refund_pending: 'Proses Refund',
    refunded: 'Telah Direfund',
  }
  return labels[status] || status
}

function printVoucher(): void {
  window.print()
}

function onFileChange(event: Event): void {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0] ?? null
  errorMessage.value = null

  if (!file) {
    selectedFile.value = null
    return
  }

  const allowedTypes = ['image/jpeg', 'image/png', 'image/webp']
  if (!allowedTypes.includes(file.type)) {
    errorMessage.value =
      'Format file ditolak. Hanya gambar JPG, PNG, atau WEBP yang diizinkan (PDF/dokumen tidak diterima demi keamanan).'
    input.value = ''
    selectedFile.value = null
    return
  }

  if (file.size > 5 * 1024 * 1024) {
    errorMessage.value = 'Ukuran gambar terlalu besar. Maksimal 5 MB.'
    input.value = ''
    selectedFile.value = null
    return
  }

  selectedFile.value = file
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
    <!-- Action Bar (Disembunyikan saat Cetak PDF) -->
    <div class="flex flex-wrap items-center justify-between gap-3 print:hidden">
      <RouterLink
        :to="{ name: 'booking' }"
        class="text-sm font-medium text-terracotta-700 hover:underline"
      >
        ← Kembali ke Halaman Booking
      </RouterLink>
      <div class="flex items-center gap-2.5">
        <button
          type="button"
          class="rounded-lg border border-stone-300 bg-white px-3 py-1.5 text-xs font-semibold text-stone-700 hover:bg-stone-100"
          @click="fetchStatus"
        >
          Perbarui Status
        </button>
        <button
          v-if="isConfirmedOrPaid"
          type="button"
          class="rounded-lg bg-terracotta-600 px-4 py-1.5 text-xs font-semibold text-white shadow-xs hover:bg-terracotta-700"
          @click="printVoucher"
        >
          Unduh / Cetak E-Voucher (PDF)
        </button>
      </div>
    </div>

    <div
      v-if="loading"
      class="rounded-xl border border-stone-200 bg-white p-8 text-center text-sm text-stone-600"
    >
      Memuat detail reservasi...
    </div>

    <div
      v-else-if="errorMessage && !booking"
      class="rounded-xl border border-red-200 bg-red-50 p-6 text-sm text-red-800"
    >
      {{ errorMessage }}
    </div>

    <template v-else-if="booking">
      <div
        v-if="uploadSuccess"
        class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800 print:hidden"
      >
        {{ uploadSuccess }}
      </div>

      <div
        v-if="errorMessage"
        class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 print:hidden"
      >
        {{ errorMessage }}
      </div>

      <!-- E-VOUCHER / TIKET RESMI (Desain Tiket Lapangan + QR Check-In) -->
      <div
        class="overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-sm print:break-inside-avoid print:border-stone-300 print:shadow-none"
      >
        <!-- Header Tiket Terracotta -->
        <div
          class="bg-terracotta-600 px-6 py-5 text-white flex flex-wrap items-center justify-between gap-4"
        >
          <div class="flex items-center gap-3">
            <span
              class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-white/15 font-bold text-white border border-white/25"
            >
              PM
            </span>
            <div>
              <p class="text-[11px] font-semibold uppercase tracking-wider text-terracotta-100">
                {{ isConfirmedOrPaid ? 'E-VOUCHER & COURT PASS RESMI' : 'INVOICE RESERVASI' }}
              </p>
              <h1 class="text-lg font-bold tracking-tight">PADEL MAKASSAR</h1>
            </div>
          </div>

          <div class="text-right">
            <span
              class="inline-block rounded-full px-3 py-1 text-xs font-bold"
              :class="{
                'bg-amber-100 text-amber-900': booking.status === 'pending_payment',
                'bg-white text-emerald-800': isConfirmedOrPaid,
                'bg-red-100 text-red-900':
                  booking.status === 'cancelled' || booking.status === 'expired',
              }"
            >
              {{ statusLabel(booking.status) }}
            </span>
          </div>
        </div>

        <div class="p-6 space-y-6 print:p-5 print:space-y-4">
          <!-- Kode Booking + QR Code Verifikasi Check-In -->
          <div
            class="flex flex-col sm:flex-row print:flex-row sm:items-center print:items-center justify-between gap-6 border-b border-dashed border-stone-200 pb-6 print:pb-4"
          >
            <div class="space-y-2">
              <p class="text-xs font-medium text-stone-500">Kode Booking Resmi</p>
              <p class="text-3xl font-mono font-bold tracking-tight text-stone-900">
                {{ booking.booking_code }}
              </p>
              <div v-if="isConfirmedOrPaid" class="space-y-0.5 text-xs text-emerald-700 font-medium">
                <p>
                  ✓ Pembayaran Terverifikasi ({{
                    latestPayment?.provider_reference || 'Transfer Bank Mandiri'
                  }})
                </p>
                <p v-if="latestPayment?.paid_at" class="text-stone-500 font-normal">
                  Waktu Lunas: {{ formatDateTimeWita(latestPayment.paid_at) }} WITA
                </p>
              </div>
              <p v-else class="text-xs text-stone-500">
                Simpan kode ini untuk cek status pesanan atau konfirmasi di lokasi.
              </p>
            </div>

            <!-- QR Code & Stamp Lunas saat sudah Terkonfirmasi -->
            <div
              v-if="isConfirmedOrPaid"
              class="flex flex-col items-center rounded-xl border border-stone-200 bg-[#FAF8F6] p-3 shrink-0"
            >
              <img
                :src="qrCodeUrl"
                :alt="`QR ${booking.booking_code}`"
                class="h-28 w-28 rounded bg-white p-1"
              />
              <span class="mt-1.5 font-mono text-[10px] font-bold text-stone-600">
                TUNJUKKAN SAAT CHECK-IN
              </span>
            </div>
          </div>

          <!-- Peringatan Hold jika masih Pending Payment -->
          <div
            v-if="booking.status === 'pending_payment' && booking.hold_expires_at"
            class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 space-y-1"
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

          <!-- Informasi Pemesan & Jadwal -->
          <dl class="grid gap-4 sm:grid-cols-2 print:grid-cols-2 print:gap-3 text-sm">
            <div class="rounded-xl bg-[#FAF8F6] p-3.5">
              <dt class="text-xs text-stone-500">Nama Pemesan</dt>
              <dd class="mt-0.5 font-bold text-stone-900">{{ booking.customer_name }}</dd>
            </div>
            <div class="rounded-xl bg-[#FAF8F6] p-3.5">
              <dt class="text-xs text-stone-500">Lokasi Venue</dt>
              <dd class="mt-0.5 font-bold text-stone-900">{{ booking.venue?.name }}</dd>
              <dd class="text-xs text-stone-500">Jl. Letjen Hertasning No.2, Makassar</dd>
            </div>
            <div class="rounded-xl bg-[#FAF8F6] p-3.5">
              <dt class="text-xs text-stone-500">Waktu Mulai</dt>
              <dd class="mt-0.5 font-semibold text-stone-900">
                {{ formatDateTimeWita(booking.start_at) }} WITA
              </dd>
            </div>
            <div class="rounded-xl bg-[#FAF8F6] p-3.5">
              <dt class="text-xs text-stone-500">Waktu Selesai</dt>
              <dd class="mt-0.5 font-semibold text-stone-900">
                {{ formatDateTimeWita(booking.end_at) }} WITA
              </dd>
            </div>
          </dl>

          <!-- Rincian Lapangan & Perhitungan Diskon/Subtotal -->
          <div class="border-t border-stone-100 pt-4 space-y-2.5">
            <h2 class="text-xs font-bold uppercase tracking-wider text-stone-500">
              Rincian Sewa Lapangan
            </h2>
            <div
              v-for="item in booking.items"
              :key="item.id"
              class="flex items-center justify-between rounded-xl border border-stone-200/80 bg-[#FAF8F6] px-4 py-3 text-sm"
            >
              <div>
                <p class="font-bold text-stone-900">
                  {{ item.court_name_snapshot }} (Kode: {{ item.court_code_snapshot }})
                </p>
                <p class="text-xs text-stone-500">Durasi {{ item.duration_minutes }} menit</p>
              </div>
              <span class="font-bold text-stone-900">{{ formatIdr(item.total_price) }}</span>
            </div>
          </div>

          <!-- Ringkasan Pembayaran (Subtotal, Diskon, Total) -->
          <div class="border-t border-stone-200 pt-4 space-y-1.5 text-sm">
            <div
              v-if="Number(booking.discount_amount) > 0"
              class="flex items-center justify-between text-stone-600"
            >
              <span>Subtotal</span>
              <span>{{ formatIdr(booking.subtotal) }}</span>
            </div>
            <div
              v-if="Number(booking.discount_amount) > 0"
              class="flex items-center justify-between text-emerald-700 font-medium"
            >
              <span>Potongan Diskon Voucher</span>
              <span>-{{ formatIdr(booking.discount_amount) }}</span>
            </div>
            <div class="flex items-center justify-between pt-1 text-base">
              <span class="font-bold text-stone-900">
                {{ isConfirmedOrPaid ? 'Total Dibayar (LUNAS)' : 'Total Tagihan Transfer' }}
              </span>
              <span class="text-xl font-bold text-terracotta-700">
                {{ formatIdr(booking.total_amount) }}
              </span>
            </div>
          </div>

          <!-- Footer E-Voucher Resmi -->
          <div
            v-if="isConfirmedOrPaid"
            class="rounded-xl border border-emerald-200 bg-emerald-50/70 p-4 text-xs text-emerald-900 flex flex-wrap items-center justify-between gap-3"
          >
            <div>
              <p class="font-bold">E-Voucher Resmi PADEL MAKASSAR</p>
              <p>
                Tunjukkan E-Voucher / QR Code ini kepada petugas di lokasi 10 menit sebelum sesi
                dimulai.
              </p>
            </div>
            <button
              type="button"
              class="rounded-lg bg-emerald-700 px-3.5 py-2 text-xs font-semibold text-white hover:bg-emerald-800 print:hidden"
              @click="printVoucher"
            >
              Simpan PDF / Cetak Tiket
            </button>
          </div>
        </div>
      </div>

      <!-- Instruksi Transfer Manual & Upload Bukti (Hanya tampil saat belum lunas) -->
      <div
        v-if="booking.status === 'pending_payment'"
        class="rounded-2xl border border-stone-200 bg-white p-6 space-y-5 print:hidden"
      >
        <div>
          <h2 class="text-lg font-bold text-stone-900">Pembayaran Transfer Bank</h2>
        </div>

        <div class="rounded-xl border border-terracotta-200 bg-terracotta-50/60 p-4 space-y-2 text-sm">
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
          class="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-800"
        >
          <strong>Bukti transfer sebelumnya ditolak Admin:</strong>
          {{ latestPayment.verification_notes }}. Silakan unggah ulang bukti transfer yang sesuai.
        </div>

        <div
          v-if="latestPayment?.has_proof && latestPayment.status === 'pending'"
          class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900 space-y-1"
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
                class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-1.5 text-sm"
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
                class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-1.5 text-sm"
              />
            </div>
          </div>

          <div>
            <label class="block text-xs font-medium text-stone-700">
              Screenshot / Foto Bukti Transfer (JPG, PNG, atau WEBP — Maks 5MB) *
            </label>
            <input
              type="file"
              accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp"
              required
              class="mt-1 block w-full text-sm text-stone-700 file:mr-4 file:rounded-lg file:border-0 file:bg-terracotta-50 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-terracotta-800 hover:file:bg-terracotta-100"
              @change="onFileChange"
            />
          </div>

          <div class="flex flex-wrap items-center justify-between gap-3 pt-2">
            <a
              :href="whatsappConfirmUrl"
              target="_blank"
              rel="noopener noreferrer"
              class="rounded-lg border border-terracotta-700 px-4 py-2 text-xs font-semibold text-terracotta-800 hover:bg-terracotta-50"
            >
              Konfirmasi via WhatsApp Admin ({{ bankInfo.whatsapp }})
            </a>

            <button
              type="submit"
              :disabled="uploading || !selectedFile"
              class="rounded-lg bg-terracotta-600 px-5 py-2 text-sm font-semibold text-white hover:bg-terracotta-700 disabled:opacity-50"
            >
              {{ uploading ? 'Mengunggah...' : 'Kirim Bukti Transfer' }}
            </button>
          </div>
        </form>
      </div>
    </template>
  </section>
</template>
