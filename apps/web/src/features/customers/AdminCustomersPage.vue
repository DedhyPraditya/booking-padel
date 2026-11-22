<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import type { Venue } from '@/types/api'
import { ApiError, apiRequest } from '@/services/apiClient'
import { useAuthStore } from '@/stores/auth'
import AppIcon from '@/components/ui/AppIcon.vue'

interface CustomerSummary {
  customer_phone: string
  customer_name: string
  customer_email: string | null
  total_bookings: number
  total_spent: string
  last_booking_at: string
}

interface StaffItem {
  id: string
  name: string
  email: string
  phone: string
  is_active: boolean
  roles: Array<{ role: string; venue_name?: string }>
}

const auth = useAuthStore()
const activeTab = ref<'customers' | 'staff'>('customers')
const customers = ref<CustomerSummary[]>([])
const staffList = ref<StaffItem[]>([])
const venues = ref<Venue[]>([])
const search = ref('')
const loading = ref(true)
const showStaffModal = ref(false)
const savingStaff = ref(false)
const errorMessage = ref<string | null>(null)
const successMessage = ref<string | null>(null)

const canManageUsers = computed(() => auth.hasPermission('user.manage'))

const staffForm = ref({
  venue_id: '',
  name: '',
  email: '',
  phone: '',
  password: '',
  role: 'booking_staff',
})

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

async function loadData(): Promise<void> {
  loading.value = true
  errorMessage.value = null
  try {
    const q = search.value ? `?search=${encodeURIComponent(search.value)}` : ''
    const [custRes, venuesRes] = await Promise.all([
      apiRequest<CustomerSummary[]>(`/admin/customers${q}`),
      apiRequest<Venue[]>('/admin/venues'),
    ])
    customers.value = custRes.data
    venues.value = venuesRes.data
    if (!staffForm.value.venue_id && venues.value[0]) {
      staffForm.value.venue_id = venues.value[0].id
    }

    if (canManageUsers.value) {
      const staffRes = await apiRequest<StaffItem[]>('/admin/staff')
      staffList.value = staffRes.data
    }
  } catch (err) {
    errorMessage.value =
      err instanceof ApiError ? err.message : 'Gagal memuat data pelanggan & petugas.'
  } finally {
    loading.value = false
  }
}

async function createStaff(): Promise<void> {
  savingStaff.value = true
  errorMessage.value = null
  successMessage.value = null
  try {
    await apiRequest('/admin/staff', {
      method: 'POST',
      body: JSON.stringify(staffForm.value),
    })
    showStaffModal.value = false
    staffForm.value.name = ''
    staffForm.value.email = ''
    staffForm.value.phone = ''
    staffForm.value.password = ''
    successMessage.value = 'Akun petugas baru berhasil ditambahkan.'
    await loadData()
  } catch (err) {
    errorMessage.value = err instanceof ApiError ? err.message : 'Gagal menambah akun petugas.'
  } finally {
    savingStaff.value = false
  }
}

onMounted(loadData)
</script>

<template>
  <section class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
      <div>
        <h1 class="text-2xl font-bold tracking-tight text-stone-900">Pelanggan & Petugas</h1>
        <p class="mt-0.5 text-sm text-stone-500">
          Direktori pelanggan PADEL MAKASSAR beserta riwayat nilai transaksi dan hak akses staff.
        </p>
      </div>
      <div class="flex items-center gap-2">
        <button
          type="button"
          class="rounded-xl px-4 py-2 text-xs font-semibold transition-colors"
          :class="
            activeTab === 'customers'
              ? 'bg-terracotta-600 text-white'
              : 'bg-white border border-stone-200 text-stone-600 hover:bg-terracotta-50'
          "
          @click="activeTab = 'customers'"
        >
          Direktori Pelanggan ({{ customers.length }})
        </button>
        <button
          v-if="canManageUsers"
          type="button"
          class="rounded-xl px-4 py-2 text-xs font-semibold transition-colors"
          :class="
            activeTab === 'staff'
              ? 'bg-terracotta-600 text-white'
              : 'bg-white border border-stone-200 text-stone-600 hover:bg-terracotta-50'
          "
          @click="activeTab = 'staff'"
        >
          Akun Petugas / Staff ({{ staffList.length }})
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
      v-if="errorMessage && !showStaffModal"
      class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800"
    >
      {{ errorMessage }}
    </div>

    <!-- Tab 1: Direktori Pelanggan -->
    <div v-if="activeTab === 'customers'" class="space-y-4">
      <div class="flex items-center gap-3">
        <div class="relative flex-1 max-w-sm">
          <input
            v-model="search"
            type="text"
            placeholder="Cari nama atau nomor WA pelanggan..."
            class="w-full rounded-xl border border-stone-200 bg-white pl-9 pr-3 py-2 text-sm"
            @keyup.enter="loadData"
          />
          <AppIcon name="search" class="w-4 h-4 text-stone-400 absolute left-3 top-2.5" />
        </div>
        <button
          type="button"
          class="rounded-xl border border-stone-200 bg-white px-4 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50"
          @click="loadData"
        >
          Cari
        </button>
      </div>

      <div
        v-if="loading"
        class="rounded-xl border border-stone-200 bg-white p-10 text-center text-sm text-stone-500"
      >
        Memuat data pelanggan...
      </div>

      <div
        v-else-if="customers.length === 0"
        class="rounded-xl border border-stone-200 bg-white p-10 text-center text-sm text-stone-500"
      >
        Belum ada data pelanggan tercatat.
      </div>

      <div v-else class="overflow-x-auto rounded-2xl border border-stone-200 bg-white shadow-2xs">
        <table class="min-w-full divide-y divide-stone-100 text-sm">
          <thead class="bg-[#FAF8F6] text-left text-xs font-semibold text-stone-500">
            <tr>
              <th class="px-6 py-3.5">Nama Pelanggan</th>
              <th class="px-6 py-3.5">No. WhatsApp</th>
              <th class="px-6 py-3.5">Email</th>
              <th class="px-6 py-3.5">Total Booking</th>
              <th class="px-6 py-3.5">Total Transaksi Lunas</th>
              <th class="px-6 py-3.5">Jadwal Terakhir</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-stone-100">
            <tr v-for="c in customers" :key="c.customer_phone" class="hover:bg-stone-50/70">
              <td class="px-6 py-3.5 font-semibold text-stone-900">{{ c.customer_name }}</td>
              <td class="px-6 py-3.5 font-mono text-xs text-stone-700">{{ c.customer_phone }}</td>
              <td class="px-6 py-3.5 text-xs text-stone-500">{{ c.customer_email || '-' }}</td>
              <td class="px-6 py-3.5 font-medium">{{ c.total_bookings }}x</td>
              <td class="px-6 py-3.5 font-bold text-terracotta-700">
                {{ formatIdr(c.total_spent) }}
              </td>
              <td class="px-6 py-3.5 text-xs text-stone-500">
                {{ formatWita(c.last_booking_at) }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Tab 2: Manajemen Petugas -->
    <div v-else class="space-y-4">
      <div class="flex justify-end">
        <button
          type="button"
          class="inline-flex items-center gap-1.5 rounded-xl bg-terracotta-600 px-4 py-2 text-xs font-semibold text-white shadow-xs hover:bg-terracotta-700"
          @click="showStaffModal = true"
        >
          <AppIcon name="plus" class="w-3.5 h-3.5" />
          <span>Tambah Petugas Baru</span>
        </button>
      </div>

      <div class="overflow-x-auto rounded-2xl border border-stone-200 bg-white shadow-2xs">
        <table class="min-w-full divide-y divide-stone-100 text-sm">
          <thead class="bg-[#FAF8F6] text-left text-xs font-semibold text-stone-500">
            <tr>
              <th class="px-6 py-3.5">Nama Petugas</th>
              <th class="px-6 py-3.5">Email Login</th>
              <th class="px-6 py-3.5">No. Telepon</th>
              <th class="px-6 py-3.5">Role & Hak Akses</th>
              <th class="px-6 py-3.5">Status</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-stone-100">
            <tr v-for="s in staffList" :key="s.id">
              <td class="px-6 py-3.5 font-semibold text-stone-900">{{ s.name }}</td>
              <td class="px-6 py-3.5 text-stone-700">{{ s.email }}</td>
              <td class="px-6 py-3.5 font-mono text-xs text-stone-600">{{ s.phone }}</td>
              <td class="px-6 py-3.5">
                <span
                  v-for="(r, idx) in s.roles"
                  :key="idx"
                  class="mr-1.5 inline-flex rounded-md bg-terracotta-50 border border-terracotta-200 px-2.5 py-0.5 text-xs font-semibold text-terracotta-700"
                >
                  {{ r.role }}
                </span>
              </td>
              <td class="px-6 py-3.5">
                <span class="rounded-full bg-emerald-50 border border-emerald-200 px-2.5 py-0.5 text-xs font-semibold text-emerald-700">
                  Aktif
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Modal Tambah Petugas -->
    <Teleport to="body">
      <div
        v-if="showStaffModal"
        class="fixed inset-0 z-50 flex items-center justify-center bg-stone-900/50 p-4 backdrop-blur-xs"
        @click.self="showStaffModal = false"
      >
        <form
          class="w-full max-w-md rounded-2xl border border-stone-200 bg-white p-6 shadow-xl space-y-4"
          @submit.prevent="createStaff"
        >
          <div class="flex items-center justify-between border-b border-stone-100 pb-3">
            <h2 class="text-base font-bold text-stone-900">Tambah Akun Petugas</h2>
            <button
              type="button"
              class="text-stone-400 hover:text-stone-700"
              @click="showStaffModal = false"
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
            <label class="block text-xs font-medium text-stone-700">Nama Lengkap *</label>
            <input
              v-model="staffForm.name"
              type="text"
              required
              class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm"
            />
          </div>

          <div>
            <label class="block text-xs font-medium text-stone-700">Email Login *</label>
            <input
              v-model="staffForm.email"
              type="email"
              required
              class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm"
            />
          </div>

          <div>
            <label class="block text-xs font-medium text-stone-700">No. WhatsApp / HP *</label>
            <input
              v-model="staffForm.phone"
              type="text"
              required
              class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm"
            />
          </div>

          <div>
            <label class="block text-xs font-medium text-stone-700">Role Operasional *</label>
            <select
              v-model="staffForm.role"
              class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm"
            >
              <option value="booking_staff">Booking Staff (Kelola Booking & Check-In)</option>
              <option value="finance_staff">Finance / Cashier (Verifikasi Transfer & Laporan)</option>
              <option value="operations_staff">Operational Staff (Jadwal Harian & Check-In)</option>
              <option value="venue_admin">Venue Admin (Akses Penuh Venue)</option>
            </select>
          </div>

          <div>
            <label class="block text-xs font-medium text-stone-700">Password Awal (Min 8 Karakter) *</label>
            <input
              v-model="staffForm.password"
              type="password"
              minlength="8"
              required
              class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm"
            />
          </div>

          <div class="flex items-center justify-end gap-3 border-t border-stone-100 pt-4">
            <button
              type="button"
              class="rounded-lg border border-stone-200 px-4 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50"
              @click="showStaffModal = false"
            >
              Batal
            </button>
            <button
              type="submit"
              :disabled="savingStaff"
              class="rounded-lg bg-terracotta-600 px-5 py-2 text-xs font-semibold text-white hover:bg-terracotta-700 disabled:opacity-50"
            >
              {{ savingStaff ? 'Menyimpan...' : 'Buat Akun Petugas' }}
            </button>
          </div>
        </form>
      </div>
    </Teleport>
  </section>
</template>
