<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import type { Venue } from '@/types/api'
import { ApiError, apiRequest } from '@/services/apiClient'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const venues = ref<Venue[]>([])
const loading = ref(true)
const savingId = ref<string | null>(null)
const errorMessage = ref<string | null>(null)
const successMessage = ref<string | null>(null)

const canManageVenue = computed(() => auth.hasPermission('venue.manage'))

async function fetchAdminVenues(): Promise<void> {
  loading.value = true
  errorMessage.value = null
  try {
    const response = await apiRequest<Venue[]>('/admin/venues')
    venues.value = response.data
  } catch (err) {
    errorMessage.value =
      err instanceof ApiError ? err.message : 'Gagal memuat daftar venue yang dapat diakses.'
  } finally {
    loading.value = false
  }
}

async function saveVenuePolicy(venue: Venue): Promise<void> {
  if (!canManageVenue.value) return
  savingId.value = venue.id
  errorMessage.value = null
  successMessage.value = null
  try {
    const response = await apiRequest<Venue>(`/admin/venues/${venue.id}`, {
      method: 'PATCH',
      body: JSON.stringify({
        name: venue.name,
        phone: venue.phone,
        bank_name: venue.bank_name,
        bank_account_number: venue.bank_account_number,
        bank_account_holder: venue.bank_account_holder,
        slot_interval_minutes: venue.slot_interval_minutes,
        min_booking_duration_minutes: venue.min_booking_duration_minutes,
        max_booking_duration_minutes: venue.max_booking_duration_minutes,
        buffer_minutes: venue.buffer_minutes ?? 0,
        hold_duration_minutes: venue.hold_duration_minutes ?? 10,
        cancellation_cutoff_hours: venue.cancellation_cutoff_hours ?? 24,
        reschedule_cutoff_hours: venue.reschedule_cutoff_hours ?? 24,
        status: venue.status,
      }),
    })
    const idx = venues.value.findIndex((v) => v.id === venue.id)
    if (idx !== -1) {
      venues.value[idx] = response.data
    }
    successMessage.value = `Konfigurasi "${response.data.name}" berhasil disimpan dan dicatat ke audit log.`
  } catch (err) {
    errorMessage.value =
      err instanceof ApiError ? err.message : 'Gagal memperbarui konfigurasi venue.'
  } finally {
    savingId.value = null
  }
}

onMounted(fetchAdminVenues)
</script>

<template>
  <section class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
      <div>
        <h1 class="text-2xl font-bold text-stone-900">Manajemen Venue & Kebijakan Operasional</h1>
        <p class="mt-1 text-sm text-stone-600">
          Menampilkan venue sesuai cakupan otorisasi akun Anda ({{ auth.user?.email }}).
        </p>
      </div>
      <button
        type="button"
        class="self-start rounded-md border border-stone-300 bg-white px-3 py-1.5 text-sm font-medium text-stone-700 hover:bg-stone-100"
        :disabled="loading"
        @click="fetchAdminVenues"
      >
        {{ loading ? 'Memuat...' : 'Segarkan Data' }}
      </button>
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

    <div
      v-if="loading"
      class="rounded-lg border border-stone-200 bg-white p-8 text-center text-sm text-stone-600"
    >
      Memuat data venue...
    </div>

    <div
      v-else-if="venues.length === 0"
      class="rounded-lg border border-stone-200 bg-white p-8 text-center text-sm text-stone-600"
    >
      Tidak ada venue dalam cakupan akses Anda.
    </div>

    <div v-else class="space-y-6">
      <form
        v-for="venue in venues"
        :key="venue.id"
        class="rounded-lg border border-stone-200 bg-white p-6 space-y-4"
        @submit.prevent="saveVenuePolicy(venue)"
      >
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-100 pb-3">
          <div>
            <h2 class="text-lg font-semibold text-stone-900">{{ venue.name }}</h2>
            <p class="text-xs text-stone-500">Slug: {{ venue.slug }} • Zona waktu: {{ venue.timezone }}</p>
          </div>
          <div class="flex items-center gap-3">
            <span
              class="rounded-full px-2.5 py-0.5 text-xs font-medium"
              :class="
                venue.status === 'active'
                  ? 'bg-green-100 text-green-800'
                  : 'bg-amber-100 text-amber-800'
              "
            >
              {{ venue.status === 'active' ? 'Aktif' : 'Nonaktif' }}
            </span>
            <RouterLink
              :to="{ name: 'admin-venue-operations', params: { id: venue.id } }"
              class="rounded-md border border-terracotta-700 px-3 py-1.5 text-xs font-semibold text-terracotta-800 hover:bg-terracotta-50"
            >
              Kelola Lapangan, Jam & Tarif →
            </RouterLink>
          </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
          <div>
            <label class="block text-xs font-medium text-stone-700">Nama Venue</label>
            <input
              v-model="venue.name"
              type="text"
              :disabled="!canManageVenue"
              required
              class="mt-1 block w-full rounded-md border border-stone-300 px-3 py-1.5 text-sm disabled:bg-stone-100"
            />
          </div>

          <div>
            <label class="block text-xs font-medium text-stone-700">Telepon</label>
            <input
              v-model="venue.phone"
              type="text"
              :disabled="!canManageVenue"
              required
              class="mt-1 block w-full rounded-md border border-stone-300 px-3 py-1.5 text-sm disabled:bg-stone-100"
            />
          </div>

          <div>
            <label class="block text-xs font-medium text-stone-700">Status</label>
            <select
              v-model="venue.status"
              :disabled="!canManageVenue"
              class="mt-1 block w-full rounded-md border border-stone-300 px-3 py-1.5 text-sm disabled:bg-stone-100"
            >
              <option value="active">Aktif</option>
              <option value="inactive">Nonaktif</option>
            </select>
          </div>

          <div>
            <label class="block text-xs font-medium text-stone-700">Nama Bank Transfer Manual</label>
            <input
              v-model="venue.bank_name"
              type="text"
              :disabled="!canManageVenue"
              required
              class="mt-1 block w-full rounded-md border border-stone-300 px-3 py-1.5 text-sm disabled:bg-stone-100"
            />
          </div>

          <div>
            <label class="block text-xs font-medium text-stone-700">Nomor Rekening</label>
            <input
              v-model="venue.bank_account_number"
              type="text"
              :disabled="!canManageVenue"
              required
              class="mt-1 block w-full rounded-md border border-stone-300 px-3 py-1.5 text-sm font-mono disabled:bg-stone-100"
            />
          </div>

          <div>
            <label class="block text-xs font-medium text-stone-700">Atas Nama Rekening</label>
            <input
              v-model="venue.bank_account_holder"
              type="text"
              :disabled="!canManageVenue"
              required
              class="mt-1 block w-full rounded-md border border-stone-300 px-3 py-1.5 text-sm disabled:bg-stone-100"
            />
          </div>

          <div>
            <label class="block text-xs font-medium text-stone-700">Interval Slot (menit)</label>
            <select
              v-model.number="venue.slot_interval_minutes"
              :disabled="!canManageVenue"
              class="mt-1 block w-full rounded-md border border-stone-300 px-3 py-1.5 text-sm disabled:bg-stone-100"
            >
              <option :value="15">15 menit</option>
              <option :value="30">30 menit</option>
              <option :value="60">60 menit</option>
            </select>
          </div>

          <div>
            <label class="block text-xs font-medium text-stone-700">Durasi Minimum (menit)</label>
            <input
              v-model.number="venue.min_booking_duration_minutes"
              type="number"
              min="30"
              max="360"
              step="15"
              :disabled="!canManageVenue"
              class="mt-1 block w-full rounded-md border border-stone-300 px-3 py-1.5 text-sm disabled:bg-stone-100"
            />
          </div>

          <div>
            <label class="block text-xs font-medium text-stone-700">Durasi Maksimum (menit)</label>
            <input
              v-model.number="venue.max_booking_duration_minutes"
              type="number"
              min="60"
              max="720"
              step="15"
              :disabled="!canManageVenue"
              class="mt-1 block w-full rounded-md border border-stone-300 px-3 py-1.5 text-sm disabled:bg-stone-100"
            />
          </div>

          <div>
            <label class="block text-xs font-medium text-stone-700">Durasi Hold Checkout (menit)</label>
            <input
              v-model.number="venue.hold_duration_minutes"
              type="number"
              min="5"
              max="60"
              :disabled="!canManageVenue"
              class="mt-1 block w-full rounded-md border border-stone-300 px-3 py-1.5 text-sm disabled:bg-stone-100"
            />
          </div>

          <div>
            <label class="block text-xs font-medium text-stone-700">Batas Cutoff Batal (jam)</label>
            <input
              v-model.number="venue.cancellation_cutoff_hours"
              type="number"
              min="0"
              max="168"
              :disabled="!canManageVenue"
              class="mt-1 block w-full rounded-md border border-stone-300 px-3 py-1.5 text-sm disabled:bg-stone-100"
            />
          </div>

          <div>
            <label class="block text-xs font-medium text-stone-700">Batas Cutoff Reschedule (jam)</label>
            <input
              v-model.number="venue.reschedule_cutoff_hours"
              type="number"
              min="0"
              max="168"
              :disabled="!canManageVenue"
              class="mt-1 block w-full rounded-md border border-stone-300 px-3 py-1.5 text-sm disabled:bg-stone-100"
            />
          </div>
        </div>

        <div v-if="canManageVenue" class="pt-2 flex justify-end">
          <button
            type="submit"
            :disabled="savingId === venue.id"
            class="rounded-md bg-terracotta-700 px-4 py-2 text-sm font-medium text-white hover:bg-terracotta-800 disabled:opacity-50"
          >
            {{ savingId === venue.id ? 'Menyimpan...' : 'Simpan Konfigurasi Venue' }}
          </button>
        </div>
        <p v-else class="text-xs text-stone-500">
          Akun Anda hanya memiliki izin baca (`venue.view`) pada venue ini.
        </p>
      </form>
    </div>
  </section>
</template>
