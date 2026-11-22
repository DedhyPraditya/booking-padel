<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import type { Venue } from '@/types/api'
import { ApiError, apiRequest } from '@/services/apiClient'

const route = useRoute()
const venue = ref<Venue | null>(null)
const loading = ref(true)
const errorMessage = ref<string | null>(null)

const dayNames = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu']

function formatIdr(amount: string | number): string {
  return new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
  }).format(Number(amount))
}

async function loadVenueDetail(): Promise<void> {
  loading.value = true
  errorMessage.value = null
  try {
    const slug = String(route.params.slug)
    const response = await apiRequest<Venue>(`/venues/${encodeURIComponent(slug)}`)
    venue.value = response.data
  } catch (err) {
    errorMessage.value =
      err instanceof ApiError ? err.message : 'Venue tidak ditemukan atau gagal dimuat.'
  } finally {
    loading.value = false
  }
}

onMounted(loadVenueDetail)
</script>

<template>
  <section class="space-y-6">
    <div>
      <RouterLink :to="{ name: 'home' }" class="text-sm font-medium text-terracotta-700 hover:underline">
        ← Kembali ke Daftar Venue
      </RouterLink>
    </div>

    <div
      v-if="loading"
      class="rounded-lg border border-stone-200 bg-white p-8 text-center text-sm text-stone-600"
    >
      Memuat detail venue...
    </div>

    <div
      v-else-if="errorMessage || !venue"
      class="rounded-lg border border-red-200 bg-red-50 p-6 text-sm text-red-800"
    >
      {{ errorMessage || 'Venue tidak ditemukan.' }}
    </div>

    <div v-else class="space-y-6">
      <div class="rounded-lg border border-stone-200 bg-white p-6 space-y-6">
        <div class="border-b border-stone-100 pb-4">
          <span class="rounded-full bg-terracotta-50 px-2.5 py-0.5 text-xs font-medium text-terracotta-800">
            {{ venue.city }} • Zona Waktu {{ venue.timezone }}
          </span>
          <h1 class="mt-2 text-2xl font-bold text-stone-900">{{ venue.name }}</h1>
          <p class="mt-1 text-sm text-stone-600">{{ venue.address }}</p>
        </div>

        <p v-if="venue.description" class="text-sm text-stone-700">
          {{ venue.description }}
        </p>

        <dl class="grid gap-4 sm:grid-cols-3 rounded-lg bg-stone-50 p-4 text-sm">
          <div>
            <dt class="text-xs text-stone-500">Interval Slot</dt>
            <dd class="font-semibold text-stone-900">{{ venue.slot_interval_minutes }} menit</dd>
          </div>
          <div>
            <dt class="text-xs text-stone-500">Durasi Booking</dt>
            <dd class="font-semibold text-stone-900">
              {{ venue.min_booking_duration_minutes }} – {{ venue.max_booking_duration_minutes }} menit
            </dd>
          </div>
          <div>
            <dt class="text-xs text-stone-500">Batas Waktu Pembayaran (Hold)</dt>
            <dd class="font-semibold text-stone-900">{{ venue.hold_duration_minutes }} menit</dd>
          </div>
          <div>
            <dt class="text-xs text-stone-500">Batas Pengajuan Pembatalan</dt>
            <dd class="font-semibold text-stone-900">
              {{ venue.cancellation_cutoff_hours }} jam sebelum sesi
            </dd>
          </div>
          <div>
            <dt class="text-xs text-stone-500">Batas Pengajuan Reschedule</dt>
            <dd class="font-semibold text-stone-900">
              {{ venue.reschedule_cutoff_hours }} jam sebelum sesi
            </dd>
          </div>
          <div>
            <dt class="text-xs text-stone-500">Telepon Operasional</dt>
            <dd class="font-semibold text-stone-900">{{ venue.phone }}</dd>
          </div>
        </dl>
      </div>

      <!-- Daftar Lapangan -->
      <div class="rounded-lg border border-stone-200 bg-white p-6 space-y-4">
        <h2 class="text-lg font-bold text-stone-900">Daftar Lapangan</h2>
        <div v-if="!venue.courts || venue.courts.length === 0" class="text-sm text-stone-500">
          Belum ada lapangan yang terdaftar pada venue ini.
        </div>
        <div v-else class="grid gap-4 sm:grid-cols-3">
          <article
            v-for="court in venue.courts"
            :key="court.id"
            class="rounded-md border border-stone-200 p-4 space-y-2"
          >
            <div class="flex items-center justify-between gap-2">
              <span class="text-xs font-mono font-semibold text-stone-600">{{ court.code }}</span>
              <span
                class="rounded-full px-2 py-0.5 text-xs font-medium"
                :class="
                  court.status === 'active'
                    ? 'bg-green-100 text-green-800'
                    : 'bg-amber-100 text-amber-800'
                "
              >
                {{ court.status === 'active' ? 'Tersedia' : 'Perawatan' }}
              </span>
            </div>
            <h3 class="font-semibold text-stone-900">{{ court.name }}</h3>
            <p class="text-xs text-stone-500 capitalize">
              Tipe: {{ court.court_type }} • Permukaan: {{ court.surface_type.replace('_', ' ') }}
            </p>
            <p v-if="court.description" class="text-xs text-stone-600">{{ court.description }}</p>
          </article>
        </div>
      </div>

      <!-- Jam Operasional & Tarif -->
      <div class="grid gap-6 sm:grid-cols-2">
        <div class="rounded-lg border border-stone-200 bg-white p-6 space-y-4">
          <h2 class="text-lg font-bold text-stone-900">Jam Operasional</h2>
          <div
            v-if="!venue.operating_hours || venue.operating_hours.length === 0"
            class="text-sm text-stone-500"
          >
            Jam operasional belum dikonfigurasi.
          </div>
          <ul v-else class="divide-y divide-stone-100 text-sm">
            <li
              v-for="item in venue.operating_hours"
              :key="item.day_of_week"
              class="py-2 flex items-center justify-between"
            >
              <span class="font-medium text-stone-700">{{ dayNames[item.day_of_week] }}</span>
              <span v-if="item.is_closed" class="text-red-700 font-medium">Tutup</span>
              <span v-else class="text-stone-900">
                {{ item.open_time.slice(0, 5) }} – {{ item.close_time.slice(0, 5) }}
              </span>
            </li>
          </ul>
        </div>

        <div class="rounded-lg border border-stone-200 bg-white p-6 space-y-4">
          <h2 class="text-lg font-bold text-stone-900">Daftar Tarif Lapangan</h2>
          <div
            v-if="!venue.pricing_rules || venue.pricing_rules.length === 0"
            class="text-sm text-stone-500"
          >
            Aturan tarif belum dikonfigurasi.
          </div>
          <ul v-else class="divide-y divide-stone-100 text-sm">
            <li
              v-for="rule in venue.pricing_rules"
              :key="rule.id"
              class="py-2.5 flex items-center justify-between gap-4"
            >
              <div>
                <p class="font-medium text-stone-900">{{ rule.name }}</p>
                <p class="text-xs text-stone-500">
                  {{ rule.start_time.slice(0, 5) }} – {{ rule.end_time.slice(0, 5) }}
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
    </div>
  </section>
</template>
