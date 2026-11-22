<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import type { Booking } from '@/types/api'
import { ApiError, apiRequest } from '@/services/apiClient'

const router = useRouter()
const bookingCode = ref('')
const customerPhone = ref('')
const loading = ref(false)
const errorMessage = ref<string | null>(null)

async function handleLookup(): Promise<void> {
  loading.value = true
  errorMessage.value = null
  try {
    const res = await apiRequest<{ booking: Booking; public_token: string }>('/booking-lookup', {
      method: 'POST',
      body: JSON.stringify({
        booking_code: bookingCode.value.trim(),
        customer_phone: customerPhone.value.trim(),
      }),
    })
    await router.push({
      name: 'booking-status',
      params: { token: res.data.public_token },
    })
  } catch (err) {
    errorMessage.value =
      err instanceof ApiError
        ? err.message
        : 'Data booking tidak ditemukan. Pastikan kode booking dan nomor telepon sesuai.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <section class="mx-auto max-w-md rounded-lg border border-stone-200 bg-white p-6 shadow-xs space-y-5">
    <div>
      <h1 class="text-xl font-bold text-stone-900">Cek Status Booking & Upload Bukti Transfer</h1>
      <p class="mt-1 text-sm text-stone-600">
        Masukkan Kode Booking (mis. <code class="font-mono">PM-...</code>) dan Nomor WhatsApp/HP
        yang Anda gunakan saat memesan.
      </p>
    </div>

    <div
      v-if="errorMessage"
      class="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-800"
    >
      {{ errorMessage }}
    </div>

    <form class="space-y-4" @submit.prevent="handleLookup">
      <div>
        <label class="block text-sm font-medium text-stone-700">Kode Booking</label>
        <input
          v-model="bookingCode"
          type="text"
          required
          placeholder="PM-261122-XXXXX"
          class="mt-1 block w-full rounded-md border border-stone-300 px-3 py-2 font-mono text-sm uppercase focus:border-terracotta-600 focus:outline-none"
        />
      </div>

      <div>
        <label class="block text-sm font-medium text-stone-700">Nomor WhatsApp / Telepon</label>
        <input
          v-model="customerPhone"
          type="text"
          required
          placeholder="08..."
          class="mt-1 block w-full rounded-md border border-stone-300 px-3 py-2 text-sm focus:border-terracotta-600 focus:outline-none"
        />
      </div>

      <button
        type="submit"
        :disabled="loading"
        class="w-full rounded-md bg-terracotta-700 px-4 py-2 text-sm font-semibold text-white hover:bg-terracotta-800 disabled:opacity-50"
      >
        {{ loading ? 'Mencari...' : 'Lihat Status & Unggah Bukti Bayar' }}
      </button>
    </form>
  </section>
</template>
