<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import type { Venue } from '@/types/api'
import { ApiError, apiRequest } from '@/services/apiClient'
import { useAuthStore } from '@/stores/auth'
import AppIcon from '@/components/ui/AppIcon.vue'

interface PromotionItem {
  id: string
  venue_id: string
  code: string
  name: string
  discount_type: 'percentage' | 'fixed'
  discount_value: string
  usage_limit: number | null
  used_count: number
  is_active: boolean
}

const auth = useAuthStore()
const promotions = ref<PromotionItem[]>([])
const venues = ref<Venue[]>([])
const loading = ref(true)
const showCreateModal = ref(false)
const saving = ref(false)
const errorMessage = ref<string | null>(null)
const successMessage = ref<string | null>(null)

const canManagePricing = computed(() => auth.hasPermission('pricing.manage'))

const form = ref({
  venue_id: '',
  code: '',
  name: '',
  discount_type: 'percentage' as 'percentage' | 'fixed',
  discount_value: 10,
  usage_limit: 100 as number | null,
})

function formatIdr(amount: string | number): string {
  return new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
  }).format(Number(amount))
}

async function loadPromotions(): Promise<void> {
  loading.value = true
  errorMessage.value = null
  try {
    const [promosRes, venuesRes] = await Promise.all([
      apiRequest<PromotionItem[]>('/admin/promotions'),
      apiRequest<Venue[]>('/admin/venues'),
    ])
    promotions.value = promosRes.data
    venues.value = venuesRes.data
    if (!form.value.venue_id && venues.value[0]) {
      form.value.venue_id = venues.value[0].id
    }
  } catch (err) {
    errorMessage.value =
      err instanceof ApiError ? err.message : 'Gagal memuat daftar promo & voucher.'
  } finally {
    loading.value = false
  }
}

async function createPromotion(): Promise<void> {
  saving.value = true
  errorMessage.value = null
  successMessage.value = null
  try {
    const res = await apiRequest<PromotionItem>('/admin/promotions', {
      method: 'POST',
      body: JSON.stringify({
        ...form.value,
        code: form.value.code.toUpperCase().trim(),
        is_active: true,
      }),
    })
    promotions.value.unshift(res.data)
    showCreateModal.value = false
    form.value.code = ''
    form.value.name = ''
    successMessage.value = `Kode voucher ${res.data.code} berhasil dibuat.`
  } catch (err) {
    errorMessage.value = err instanceof ApiError ? err.message : 'Gagal membuat voucher.'
  } finally {
    saving.value = false
  }
}

async function toggleActive(item: PromotionItem): Promise<void> {
  try {
    const res = await apiRequest<PromotionItem>(`/admin/promotions/${item.id}`, {
      method: 'PATCH',
      body: JSON.stringify({
        is_active: !item.is_active,
      }),
    })
    const idx = promotions.value.findIndex((p) => p.id === item.id)
    if (idx !== -1) promotions.value[idx] = res.data
  } catch (err) {
    errorMessage.value = err instanceof ApiError ? err.message : 'Gagal memperbarui status voucher.'
  }
}

onMounted(loadPromotions)
</script>

<template>
  <section class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
      <div>
        <h1 class="text-2xl font-bold tracking-tight text-stone-900">Voucher & Promo</h1>
        <p class="mt-0.5 text-sm text-stone-500">
          Kelola kode diskon untuk pelanggan PADEL MAKASSAR (persentase maupun potongan nominal).
        </p>
      </div>
      <div class="flex items-center gap-3">
        <button
          type="button"
          class="inline-flex items-center gap-1.5 rounded-xl border border-stone-200 bg-white px-3.5 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50"
          @click="loadPromotions"
        >
          <AppIcon name="refresh" class="w-3.5 h-3.5" />
          <span>Segarkan</span>
        </button>
        <button
          v-if="canManagePricing"
          type="button"
          class="inline-flex items-center gap-1.5 rounded-xl bg-terracotta-600 px-4 py-2 text-xs font-semibold text-white shadow-xs hover:bg-terracotta-700"
          @click="showCreateModal = true"
        >
          <AppIcon name="plus" class="w-3.5 h-3.5" />
          <span>Buat Kode Voucher</span>
        </button>
      </div>
    </div>

    <div
      v-if="successMessage"
      class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800"
    >
      {{ successMessage }}
    </div>

    <div
      v-if="errorMessage && !showCreateModal"
      class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800"
    >
      {{ errorMessage }}
    </div>

    <div
      v-if="loading"
      class="rounded-xl border border-stone-200 bg-white p-10 text-center text-sm text-stone-500"
    >
      Memuat daftar voucher...
    </div>

    <div
      v-else-if="promotions.length === 0"
      class="rounded-xl border border-stone-200 bg-white p-10 text-center text-sm text-stone-500"
    >
      Belum ada kode voucher yang terdaftar.
    </div>

    <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
      <div
        v-for="item in promotions"
        :key="item.id"
        class="rounded-2xl border border-stone-200 bg-white p-5 shadow-2xs flex flex-col justify-between gap-4"
      >
        <div class="space-y-2">
          <div class="flex items-center justify-between">
            <span
              class="rounded-lg bg-terracotta-50 border border-terracotta-200 px-3 py-1 font-mono text-sm font-bold text-terracotta-700"
            >
              {{ item.code }}
            </span>
            <span
              class="rounded-full px-2.5 py-0.5 text-xs font-semibold"
              :class="
                item.is_active
                  ? 'bg-emerald-50 text-emerald-700 border border-emerald-200'
                  : 'bg-stone-100 text-stone-500'
              "
            >
              {{ item.is_active ? 'Aktif' : 'Nonaktif' }}
            </span>
          </div>
          <h2 class="text-base font-bold text-stone-900">{{ item.name }}</h2>
          <p class="text-sm font-semibold text-terracotta-600">
            Potongan:
            {{
              item.discount_type === 'percentage'
                ? `${Number(item.discount_value)}%`
                : formatIdr(item.discount_value)
            }}
          </p>
        </div>

        <div class="pt-3 border-t border-stone-100 flex items-center justify-between text-xs">
          <span class="text-stone-500">
            Terpakai: <strong>{{ item.used_count }}</strong> /
            {{ item.usage_limit ?? 'Tanpa Batas' }}
          </span>
          <button
            v-if="canManagePricing"
            type="button"
            class="font-semibold text-terracotta-700 hover:underline"
            @click="toggleActive(item)"
          >
            {{ item.is_active ? 'Nonaktifkan' : 'Aktifkan' }}
          </button>
        </div>
      </div>
    </div>

    <!-- Modal Buat Voucher -->
    <Teleport to="body">
      <div
        v-if="showCreateModal"
        class="fixed inset-0 z-50 flex items-center justify-center bg-stone-900/50 p-4 backdrop-blur-xs"
        @click.self="showCreateModal = false"
      >
        <form
          class="w-full max-w-md rounded-2xl border border-stone-200 bg-white p-6 shadow-xl space-y-4"
          @submit.prevent="createPromotion"
        >
          <div class="flex items-center justify-between border-b border-stone-100 pb-3">
            <h2 class="text-base font-bold text-stone-900">Buat Kode Voucher Baru</h2>
            <button
              type="button"
              class="text-stone-400 hover:text-stone-700"
              @click="showCreateModal = false"
            >
              ✕
            </button>
          </div>

          <div
            v-if="errorMessage"
            class="rounded-lg border border-red-200 bg-red-50 p-3 text-xs text-red-800"
          >
            {{ errorMessage }}
          </div>

          <div>
            <label class="block text-xs font-medium text-stone-700">Kode Voucher *</label>
            <input
              v-model="form.code"
              type="text"
              required
              placeholder="Mis. OPENING10 atau MEMBER50K"
              class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 font-mono text-sm uppercase"
            />
          </div>

          <div>
            <label class="block text-xs font-medium text-stone-700">Nama / Keterangan Promo *</label>
            <input
              v-model="form.name"
              type="text"
              required
              placeholder="Mis. Diskon Grand Opening 10%"
              class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm"
            />
          </div>

          <div class="grid gap-3 sm:grid-cols-2">
            <div>
              <label class="block text-xs font-medium text-stone-700">Tipe Diskon</label>
              <select
                v-model="form.discount_type"
                class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm"
              >
                <option value="percentage">Persentase (%)</option>
                <option value="fixed">Nominal Tetap (Rp)</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-medium text-stone-700">Nilai Diskon *</label>
              <input
                v-model.number="form.discount_value"
                type="number"
                min="1"
                required
                class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm"
              />
            </div>
          </div>

          <div>
            <label class="block text-xs font-medium text-stone-700">Kuota Maksimal Pemakaian</label>
            <input
              v-model.number="form.usage_limit"
              type="number"
              min="1"
              placeholder="Mis. 100"
              class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm"
            />
          </div>

          <div class="flex items-center justify-end gap-3 border-t border-stone-100 pt-4">
            <button
              type="button"
              class="rounded-lg border border-stone-200 px-4 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50"
              @click="showCreateModal = false"
            >
              Batal
            </button>
            <button
              type="submit"
              :disabled="saving"
              class="rounded-lg bg-terracotta-600 px-5 py-2 text-xs font-semibold text-white hover:bg-terracotta-700 disabled:opacity-50"
            >
              {{ saving ? 'Menyimpan...' : 'Simpan Voucher' }}
            </button>
          </div>
        </form>
      </div>
    </Teleport>
  </section>
</template>
