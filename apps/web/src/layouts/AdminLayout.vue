<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import type { Venue } from '@/types/api'
import { apiRequest } from '@/services/apiClient'
import AppIcon from '@/components/ui/AppIcon.vue'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()
const primaryVenueId = ref<string | null>(null)

const primaryRoleLabel = computed(() => {
  const role = auth.user?.roles?.[0]?.role || 'staff'
  return role.replace('_', ' ').toUpperCase()
})

const navGroups = computed(() => [
  {
    title: 'OPERASIONAL HARIAN',
    items: [
      {
        label: 'Ringkasan Dashboard',
        icon: 'dashboard' as const,
        to: { name: 'admin-overview' },
        matchNames: ['admin-overview'],
        show: true,
      },
      {
        label: 'Booking & Check-In',
        icon: 'calendar' as const,
        to: { name: 'admin-bookings' },
        matchNames: ['admin-bookings'],
        show: auth.hasPermission('booking.view'),
      },
      {
        label: 'Verifikasi Pembayaran',
        icon: 'credit-card' as const,
        to: { name: 'admin-payments' },
        matchNames: ['admin-payments'],
        show: auth.hasPermission('payment.view'),
      },
    ].filter((i) => i.show),
  },
  {
    title: 'MANAJEMEN VENUE',
    items: [
      {
        label: 'Lapangan, Jam & Tarif',
        icon: 'court' as const,
        to: primaryVenueId.value
          ? { name: 'admin-venue-operations', params: { id: primaryVenueId.value } }
          : { name: 'admin-venues' },
        matchNames: ['admin-venue-operations'],
        show: auth.hasPermission('venue.view'),
      },
      {
        label: 'Voucher & Promo',
        icon: 'tag' as const,
        to: { name: 'admin-promotions' },
        matchNames: ['admin-promotions'],
        show: auth.hasPermission('pricing.view'),
      },
      {
        label: 'Pelanggan & Petugas',
        icon: 'users' as const,
        to: { name: 'admin-customers' },
        matchNames: ['admin-customers'],
        show: auth.hasPermission('customer.view'),
      },
      {
        label: 'Pengaturan Venue & Bank',
        icon: 'settings' as const,
        to: { name: 'admin-venues' },
        matchNames: ['admin-venues'],
        show: auth.hasPermission('venue.view'),
      },
      {
        label: 'Laporan & Audit Log',
        icon: 'chart' as const,
        to: { name: 'admin-reports' },
        matchNames: ['admin-reports'],
        show: auth.hasPermission('report.view'),
      },
    ].filter((i) => i.show),
  },
])

function isActive(matchNames: string[]): boolean {
  return matchNames.includes(String(route.name))
}

async function loadPrimaryVenueId(): Promise<void> {
  try {
    const res = await apiRequest<Venue[]>('/admin/venues')
    primaryVenueId.value = res.data[0]?.id ?? null
  } catch {
    primaryVenueId.value = null
  }
}

async function handleLogout(): Promise<void> {
  await auth.logout()
  await router.push({ name: 'login' })
}

onMounted(loadPrimaryVenueId)
</script>

<template>
  <div class="min-h-screen flex bg-[#FAF8F6] text-stone-900">
    <!-- Sidebar Desktop Minimalis dengan Ikon SVG & Grouping -->
    <aside class="hidden md:flex md:w-68 md:flex-col border-r border-stone-200/90 bg-white">
      <div class="h-16 px-6 flex items-center justify-between border-b border-stone-100">
        <RouterLink :to="{ name: 'admin-overview' }" class="flex items-center gap-3">
          <span
            class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-terracotta-600 text-sm font-bold text-white shadow-xs"
          >
            PM
          </span>
          <div>
            <p class="text-sm font-bold tracking-tight text-stone-900 leading-none">
              PADEL MAKASSAR
            </p>
            <div class="mt-1 flex items-center gap-1.5">
              <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
              <span class="text-[11px] font-medium text-stone-400">2 Court Semi-Indoor</span>
            </div>
          </div>
        </RouterLink>
      </div>

      <div class="flex-1 overflow-y-auto px-3.5 py-5 space-y-6">
        <div v-for="group in navGroups" :key="group.title" class="space-y-1">
          <p class="px-3 pb-1 text-[10px] font-bold tracking-wider text-stone-400 uppercase">
            {{ group.title }}
          </p>
          <RouterLink
            v-for="item in group.items"
            :key="item.label"
            :to="item.to"
            class="group flex items-center gap-3 rounded-xl px-3.5 py-2.5 text-sm font-medium transition-all"
            :class="
              isActive(item.matchNames)
                ? 'bg-terracotta-600 text-white shadow-xs'
                : 'text-stone-600 hover:bg-terracotta-50/80 hover:text-terracotta-700'
            "
          >
            <AppIcon
              :name="item.icon"
              class="w-4 h-4 shrink-0 transition-colors"
              :class="
                isActive(item.matchNames)
                  ? 'text-white'
                  : 'text-stone-400 group-hover:text-terracotta-600'
              "
            />
            <span class="truncate">{{ item.label }}</span>
          </RouterLink>
        </div>
      </div>

      <!-- User Profile Card di Bawah Sidebar -->
      <div class="border-t border-stone-100 p-4 space-y-3 bg-[#FAF8F6]/50">
        <div class="flex items-center gap-3 px-1">
          <div
            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-terracotta-100 text-xs font-bold text-terracotta-800"
          >
            {{ auth.user?.name?.slice(0, 2).toUpperCase() || 'AD' }}
          </div>
          <div class="min-w-0 flex-1">
            <p class="text-xs font-bold text-stone-900 truncate">{{ auth.user?.name }}</p>
            <p class="text-[10px] font-semibold text-terracotta-700 truncate">
              {{ primaryRoleLabel }}
            </p>
          </div>
        </div>

        <div class="grid grid-cols-2 gap-2">
          <RouterLink
            :to="{ name: 'home' }"
            class="inline-flex items-center justify-center gap-1.5 rounded-lg border border-stone-200 bg-white px-3 py-1.5 text-xs font-semibold text-stone-700 hover:bg-stone-50"
          >
            <AppIcon name="external" class="w-3.5 h-3.5" />
            <span>Lihat Web</span>
          </RouterLink>
          <button
            type="button"
            class="inline-flex items-center justify-center gap-1.5 rounded-lg border border-stone-200 bg-white px-3 py-1.5 text-xs font-semibold text-stone-600 hover:bg-red-50 hover:text-red-700 hover:border-red-200"
            @click="handleLogout"
          >
            <AppIcon name="logout" class="w-3.5 h-3.5" />
            <span>Keluar</span>
          </button>
        </div>
      </div>
    </aside>

    <!-- Area Konten Utama -->
    <div class="flex-1 flex flex-col min-w-0">
      <!-- Topbar -->
      <header
        class="h-16 border-b border-stone-200/90 bg-white px-4 sm:px-8 flex items-center justify-between gap-4"
      >
        <div class="flex items-center gap-2 overflow-x-auto md:hidden">
          <template v-for="group in navGroups" :key="group.title">
            <RouterLink
              v-for="item in group.items"
              :key="item.label"
              :to="item.to"
              class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg px-2.5 py-1.5 text-xs font-medium"
              :class="
                isActive(item.matchNames)
                  ? 'bg-terracotta-600 text-white'
                  : 'text-stone-600 hover:bg-terracotta-50'
              "
            >
              <AppIcon :name="item.icon" class="w-3.5 h-3.5" />
              <span>{{ item.label }}</span>
            </RouterLink>
          </template>
        </div>

        <div class="hidden md:flex items-center gap-2.5 text-xs font-medium text-stone-500">
          <AppIcon name="clock" class="w-4 h-4 text-terracotta-600" />
          <span>Jl. Letjen Hertasning No.2, Panakkukang • Zona Waktu WITA (Asia/Makassar)</span>
        </div>

        <div class="flex items-center gap-3">
          <RouterLink
            :to="{ name: 'home' }"
            class="inline-flex items-center gap-1.5 rounded-lg border border-terracotta-200 bg-terracotta-50/70 px-3 py-1.5 text-xs font-semibold text-terracotta-700 hover:bg-terracotta-100"
          >
            <span>Halaman Booking Publik</span>
            <AppIcon name="external" class="w-3.5 h-3.5" />
          </RouterLink>
        </div>
      </header>

      <main class="flex-1 p-4 sm:p-8 max-w-6xl w-full mx-auto">
        <RouterView />
      </main>
    </div>
  </div>
</template>
