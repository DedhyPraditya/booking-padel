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
  <section
    v-reveal
    class="mx-auto max-w-md rounded-3xl border border-stone-200 bg-white p-6 sm:p-8 shadow-xs space-y-5"
  >
    <div>
      <span class="text-xs font-bold uppercase tracking-wider text-terracotta-600">
        PADEL MAKASSAR
      </span>
      <h1 class="mt-1 text-2xl font-extrabold tracking-tight text-stone-900">Cek Pesanan</h1>
    </div>

    <Transition name="fade-slide">
      <div
        v-if="errorMessage"
        class="rounded-xl border border-red-200 bg-red-50 p-3.5 text-sm text-red-800"
      >
        {{ errorMessage }}
      </div>
    </Transition>

    <form class="space-y-4" @submit.prevent="handleLookup">
      <div>
        <label class="block text-xs font-semibold text-stone-700">Kode Booking</label>
        <input
          v-model="bookingCode"
          type="text"
          required
          placeholder="PM-261122-XXXXX"
          class="mt-1.5 block w-full rounded-xl border border-stone-300 px-3.5 py-2.5 font-mono text-sm uppercase transition-colors focus:border-terracotta-600 focus:outline-none"
        />
      </div>

      <div>
        <label class="block text-xs font-semibold text-stone-700">Nomor WhatsApp / Telepon</label>
        <input
          v-model="customerPhone"
          type="text"
          required
          placeholder="08..."
          class="mt-1.5 block w-full rounded-xl border border-stone-300 px-3.5 py-2.5 text-sm transition-colors focus:border-terracotta-600 focus:outline-none"
        />
      </div>

      <button
        type="submit"
        :disabled="loading"
        class="btn-tactile w-full rounded-xl bg-terracotta-600 px-4 py-3 text-sm font-bold text-white hover:bg-terracotta-700 disabled:opacity-50 shadow-xs"
      >
        {{ loading ? 'Mencari Pesanan...' : 'Lihat Status & Unggah Bukti Bayar' }}
      </button>
    </form>
  </section>
</template>
