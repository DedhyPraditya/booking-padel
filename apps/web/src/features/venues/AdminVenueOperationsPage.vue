<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import type { Court, CourtBlock, OperatingHour, PricingRule, Venue } from '@/types/api'
import { ApiError, apiRequest } from '@/services/apiClient'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const auth = useAuthStore()
const venueId = String(route.params.id)

const activeTab = ref<'courts' | 'hours' | 'pricing' | 'blocks'>('courts')

const venue = ref<Venue | null>(null)
const courts = ref<Court[]>([])
const operatingHours = ref<OperatingHour[]>([])
const pricingRules = ref<PricingRule[]>([])
const courtBlocks = ref<CourtBlock[]>([])

const loading = ref(true)
const errorMessage = ref<string | null>(null)
const successMessage = ref<string | null>(null)

const dayNames = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu']

const canManageCourts = computed(() => auth.hasPermission('court.manage'))
const canManageSchedule = computed(() => auth.hasPermission('schedule.manage'))
const canManagePricing = computed(() => auth.hasPermission('pricing.manage'))

const newCourt = ref({
  name: '',
  code: '',
  court_type: 'semi_indoor' as 'indoor' | 'semi_indoor' | 'outdoor',
  surface_type: 'panoramic_turf',
  description: '',
  status: 'active' as 'active' | 'inactive' | 'maintenance',
})

const newRule = ref({
  name: '',
  court_id: '' as string,
  day_of_week: '' as string,
  start_time: '07:00',
  end_time: '16:00',
  price_per_hour: 250000,
  priority: 10,
})

const newBlock = ref({
  court_id: '',
  start_at: '',
  end_at: '',
  reason: '',
})

function formatIdr(amount: string | number): string {
  return new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
  }).format(Number(amount))
}

async function loadAll(): Promise<void> {
  loading.value = true
  errorMessage.value = null
  try {
    const [venueRes, courtsRes, hoursRes, rulesRes, blocksRes] = await Promise.all([
      apiRequest<Venue>(`/admin/venues/${venueId}`),
      apiRequest<Court[]>(`/admin/courts?venue_id=${venueId}`),
      apiRequest<OperatingHour[]>(`/admin/operating-hours?venue_id=${venueId}`),
      apiRequest<PricingRule[]>(`/admin/pricing-rules?venue_id=${venueId}`),
      apiRequest<CourtBlock[]>(`/admin/court-blocks?venue_id=${venueId}`),
    ])

    venue.value = venueRes.data
    courts.value = courtsRes.data

    const existingHours = hoursRes.data
    operatingHours.value = Array.from({ length: 7 }, (_, day) => {
      const found = existingHours.find((h) => h.day_of_week === day)
      return {
        venue_id: venueId,
        day_of_week: day,
        open_time: found ? found.open_time.slice(0, 5) : '07:00',
        close_time: found ? found.close_time.slice(0, 5) : '23:00',
        is_closed: found ? found.is_closed : false,
      }
    })

    pricingRules.value = rulesRes.data
    courtBlocks.value = blocksRes.data
  } catch (err) {
    errorMessage.value =
      err instanceof ApiError ? err.message : 'Gagal memuat data operasional venue.'
  } finally {
    loading.value = false
  }
}

async function createCourt(): Promise<void> {
  errorMessage.value = null
  successMessage.value = null
  try {
    const res = await apiRequest<Court>('/admin/courts', {
      method: 'POST',
      body: JSON.stringify({
        venue_id: venueId,
        ...newCourt.value,
      }),
    })
    courts.value.push(res.data)
    newCourt.value = {
      name: '',
      code: '',
      court_type: 'semi_indoor',
      surface_type: 'panoramic_turf',
      description: '',
      status: 'active',
    }
    successMessage.value = `Lapangan "${res.data.name}" berhasil ditambahkan.`
  } catch (err) {
    errorMessage.value = err instanceof ApiError ? err.message : 'Gagal menambah lapangan.'
  }
}

async function saveOperatingHours(): Promise<void> {
  errorMessage.value = null
  successMessage.value = null
  try {
    await apiRequest<OperatingHour[]>('/admin/operating-hours', {
      method: 'POST',
      body: JSON.stringify({
        venue_id: venueId,
        hours: operatingHours.value,
      }),
    })
    successMessage.value = 'Jam operasional berhasil diperbarui.'
  } catch (err) {
    errorMessage.value =
      err instanceof ApiError ? err.message : 'Gagal menyimpan jam operasional.'
  }
}

async function createPricingRule(): Promise<void> {
  errorMessage.value = null
  successMessage.value = null
  try {
    const res = await apiRequest<PricingRule>('/admin/pricing-rules', {
      method: 'POST',
      body: JSON.stringify({
        venue_id: venueId,
        court_id: newRule.value.court_id || null,
        name: newRule.value.name,
        day_of_week: newRule.value.day_of_week === '' ? null : Number(newRule.value.day_of_week),
        start_time: newRule.value.start_time,
        end_time: newRule.value.end_time,
        price_per_hour: newRule.value.price_per_hour,
        priority: newRule.value.priority,
        is_active: true,
      }),
    })
    pricingRules.value.unshift(res.data)
    newRule.value.name = ''
    successMessage.value = `Aturan tarif "${res.data.name}" berhasil ditambahkan.`
  } catch (err) {
    errorMessage.value = err instanceof ApiError ? err.message : 'Gagal menambah aturan tarif.'
  }
}

async function createCourtBlock(): Promise<void> {
  errorMessage.value = null
  successMessage.value = null
  try {
    const res = await apiRequest<CourtBlock>('/admin/court-blocks', {
      method: 'POST',
      body: JSON.stringify({
        venue_id: venueId,
        court_id: newBlock.value.court_id,
        start_at: newBlock.value.start_at,
        end_at: newBlock.value.end_at,
        reason: newBlock.value.reason,
      }),
    })
    courtBlocks.value.unshift(res.data)
    newBlock.value = { court_id: '', start_at: '', end_at: '', reason: '' }
    successMessage.value = 'Blok jadwal berhasil dibuat.'
  } catch (err) {
    errorMessage.value = err instanceof ApiError ? err.message : 'Gagal membuat blok jadwal.'
  }
}

onMounted(loadAll)
</script>

<template>
  <section class="space-y-6">
    <div
      v-if="loading"
      class="rounded-xl border border-stone-200 bg-white p-10 text-center text-sm text-stone-500"
    >
      Memuat konfigurasi operasional venue...
    </div>

    <template v-else-if="venue">
      <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 class="text-2xl font-bold tracking-tight text-stone-900">
            Lapangan, Jam & Tarif
          </h1>
          <p class="mt-0.5 text-sm text-stone-500">
            {{ venue.name }} • Zona Waktu {{ venue.timezone }}
          </p>
        </div>
      </div>

      <!-- Tab Navigation Bersih -->
      <div class="flex flex-wrap gap-2 border-b border-stone-200 pb-3">
        <button
          v-for="tab in [
            { id: 'courts', label: `Lapangan (${courts.length})` },
            { id: 'hours', label: 'Jam Operasional' },
            { id: 'pricing', label: `Tarif Sewa (${pricingRules.length})` },
            { id: 'blocks', label: `Blok Jadwal (${courtBlocks.length})` },
          ]"
          :key="tab.id"
          type="button"
          class="rounded-lg px-4 py-2 text-xs font-semibold transition-colors"
          :class="
            activeTab === tab.id
              ? 'bg-terracotta-600 text-white shadow-2xs'
              : 'bg-white text-stone-600 border border-stone-200 hover:bg-terracotta-50 hover:text-terracotta-700'
          "
          @click="activeTab = tab.id as any"
        >
          {{ tab.label }}
        </button>
      </div>

      <div
        v-if="successMessage"
        class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800"
      >
        {{ successMessage }}
      </div>

      <div
        v-if="errorMessage"
        class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800"
      >
        {{ errorMessage }}
      </div>

      <!-- Tab 1: Lapangan -->
      <div
        v-if="activeTab === 'courts'"
        class="rounded-xl border border-stone-200 bg-white p-6 space-y-6"
      >
        <div class="grid gap-4 sm:grid-cols-2">
          <div
            v-for="court in courts"
            :key="court.id"
            class="rounded-xl border border-stone-200 bg-[#FAF8F6] p-4 space-y-1.5"
          >
            <div class="flex items-center justify-between">
              <span
                class="rounded bg-terracotta-50 px-2 py-0.5 font-mono text-xs font-semibold text-terracotta-700"
              >
                Kode {{ court.code }}
              </span>
              <span class="text-xs font-medium capitalize text-emerald-700">
                {{ court.status }}
              </span>
            </div>
            <p class="text-base font-bold text-stone-900">{{ court.name }}</p>
            <p class="text-xs text-stone-500">
              Tipe: {{ court.court_type.replace('_', ' ') }} • Permukaan:
              {{ court.surface_type.replace('_', ' ') }}
            </p>
          </div>
        </div>

        <form
          v-if="canManageCourts"
          class="border-t border-stone-100 pt-5 grid gap-3 sm:grid-cols-4"
          @submit.prevent="createCourt"
        >
          <input
            v-model="newCourt.code"
            type="text"
            placeholder="Kode (mis. 003)"
            required
            class="rounded-lg border border-stone-300 px-3 py-2 text-sm"
          />
          <input
            v-model="newCourt.name"
            type="text"
            placeholder="Nama Lapangan"
            required
            class="rounded-lg border border-stone-300 px-3 py-2 text-sm"
          />
          <select
            v-model="newCourt.court_type"
            class="rounded-lg border border-stone-300 px-3 py-2 text-sm"
          >
            <option value="semi_indoor">Semi Indoor</option>
            <option value="indoor">Indoor</option>
            <option value="outdoor">Outdoor</option>
          </select>
          <button
            type="submit"
            class="rounded-lg bg-terracotta-600 px-4 py-2 text-xs font-semibold text-white hover:bg-terracotta-700"
          >
            + Tambah Lapangan
          </button>
        </form>
      </div>

      <!-- Tab 2: Jam Operasional -->
      <form
        v-if="activeTab === 'hours'"
        class="rounded-xl border border-stone-200 bg-white p-6 space-y-4"
        @submit.prevent="saveOperatingHours"
      >
        <div class="flex items-center justify-between border-b border-stone-100 pb-3">
          <h2 class="text-base font-bold text-stone-900">Jam Operasional Mingguan</h2>
          <button
            v-if="canManageSchedule"
            type="submit"
            class="rounded-lg bg-terracotta-600 px-4 py-2 text-xs font-semibold text-white hover:bg-terracotta-700"
          >
            Simpan Perubahan Jam
          </button>
        </div>
        <div class="divide-y divide-stone-100 text-sm">
          <div
            v-for="hour in operatingHours"
            :key="hour.day_of_week"
            class="py-3 flex flex-wrap items-center justify-between gap-4"
          >
            <span class="w-28 font-semibold text-stone-800">{{ dayNames[hour.day_of_week] }}</span>
            <label class="flex items-center gap-2 text-xs text-stone-600">
              <input v-model="hour.is_closed" type="checkbox" :disabled="!canManageSchedule" />
              Tutup
            </label>
            <div class="flex items-center gap-2">
              <input
                v-model="hour.open_time"
                type="time"
                :disabled="hour.is_closed || !canManageSchedule"
                class="rounded-lg border border-stone-300 px-3 py-1.5 text-sm disabled:bg-stone-100"
              />
              <span class="text-stone-400">–</span>
              <input
                v-model="hour.close_time"
                type="time"
                :disabled="hour.is_closed || !canManageSchedule"
                class="rounded-lg border border-stone-300 px-3 py-1.5 text-sm disabled:bg-stone-100"
              />
            </div>
          </div>
        </div>
      </form>

      <!-- Tab 3: Aturan Harga -->
      <div
        v-if="activeTab === 'pricing'"
        class="rounded-xl border border-stone-200 bg-white p-6 space-y-5"
      >
        <ul class="divide-y divide-stone-100 text-sm">
          <li
            v-for="rule in pricingRules"
            :key="rule.id"
            class="py-3 flex items-center justify-between"
          >
            <div>
              <p class="font-semibold text-stone-900">{{ rule.name }}</p>
              <p class="text-xs text-stone-500">
                Jam {{ rule.start_time.slice(0, 5) }} – {{ rule.end_time.slice(0, 5) }} WITA •
                Prioritas {{ rule.priority }}
              </p>
            </div>
            <span class="font-bold text-terracotta-700">
              {{ formatIdr(rule.price_per_hour) }} / jam
            </span>
          </li>
        </ul>

        <form
          v-if="canManagePricing"
          class="border-t border-stone-100 pt-4 grid gap-3 sm:grid-cols-5"
          @submit.prevent="createPricingRule"
        >
          <input
            v-model="newRule.name"
            type="text"
            placeholder="Nama Tarif"
            required
            class="rounded-lg border border-stone-300 px-3 py-2 text-sm"
          />
          <input
            v-model="newRule.start_time"
            type="time"
            required
            class="rounded-lg border border-stone-300 px-3 py-2 text-sm"
          />
          <input
            v-model="newRule.end_time"
            type="time"
            required
            class="rounded-lg border border-stone-300 px-3 py-2 text-sm"
          />
          <input
            v-model.number="newRule.price_per_hour"
            type="number"
            min="0"
            step="5000"
            placeholder="Harga / jam (IDR)"
            required
            class="rounded-lg border border-stone-300 px-3 py-2 text-sm"
          />
          <button
            type="submit"
            class="rounded-lg bg-terracotta-600 px-4 py-2 text-xs font-semibold text-white hover:bg-terracotta-700"
          >
            + Tambah Tarif
          </button>
        </form>
      </div>

      <!-- Tab 4: Blok Jadwal -->
      <div
        v-if="activeTab === 'blocks'"
        class="rounded-xl border border-stone-200 bg-white p-6 space-y-5"
      >
        <div v-if="courtBlocks.length === 0" class="text-sm text-stone-500">
          Belum ada blok jadwal aktif.
        </div>
        <ul v-else class="divide-y divide-stone-100 text-sm">
          <li
            v-for="block in courtBlocks"
            :key="block.id"
            class="py-3 flex items-center justify-between"
          >
            <div>
              <span class="font-semibold text-stone-900">{{ block.court?.name }}</span>
              <span class="ml-2 text-xs text-stone-500">{{ block.reason }}</span>
            </div>
            <span class="text-xs text-stone-500">{{ block.start_at }} – {{ block.end_at }}</span>
          </li>
        </ul>

        <form
          v-if="canManageSchedule && courts.length > 0"
          class="border-t border-stone-100 pt-4 grid gap-3 sm:grid-cols-5"
          @submit.prevent="createCourtBlock"
        >
          <select
            v-model="newBlock.court_id"
            required
            class="rounded-lg border border-stone-300 px-3 py-2 text-sm"
          >
            <option value="" disabled>Pilih Lapangan</option>
            <option v-for="c in courts" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select>
          <input
            v-model="newBlock.start_at"
            type="datetime-local"
            required
            class="rounded-lg border border-stone-300 px-3 py-2 text-sm"
          />
          <input
            v-model="newBlock.end_at"
            type="datetime-local"
            required
            class="rounded-lg border border-stone-300 px-3 py-2 text-sm"
          />
          <input
            v-model="newBlock.reason"
            type="text"
            placeholder="Alasan blok/maintenance"
            required
            class="rounded-lg border border-stone-300 px-3 py-2 text-sm"
          />
          <button
            type="submit"
            class="rounded-lg bg-terracotta-600 px-4 py-2 text-xs font-semibold text-white hover:bg-terracotta-700"
          >
            + Buat Blok
          </button>
        </form>
      </div>
    </template>
  </section>
</template>
