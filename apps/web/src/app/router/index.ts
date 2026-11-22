import { createRouter, createWebHistory } from 'vue-router'
import PublicLayout from '@/layouts/PublicLayout.vue'
import AdminLayout from '@/layouts/AdminLayout.vue'
import PublicVenueListPage from '@/features/venues/PublicVenueListPage.vue'
import PublicVenueDetailPage from '@/features/venues/PublicVenueDetailPage.vue'
import PublicPoliciesPage from '@/features/venues/PublicPoliciesPage.vue'
import PublicBookingStatusPage from '@/features/booking/PublicBookingStatusPage.vue'
import PublicBookingLookupPage from '@/features/booking/PublicBookingLookupPage.vue'
import AdminOverviewPage from '@/features/admin/AdminOverviewPage.vue'
import AdminBookingsPage from '@/features/booking/AdminBookingsPage.vue'
import AdminPaymentsPage from '@/features/payments/AdminPaymentsPage.vue'
import AdminPromotionsPage from '@/features/promotions/AdminPromotionsPage.vue'
import AdminCustomersPage from '@/features/customers/AdminCustomersPage.vue'
import AdminReportsPage from '@/features/reports/AdminReportsPage.vue'
import LoginPage from '@/features/auth/LoginPage.vue'
import AdminVenuesPage from '@/features/venues/AdminVenuesPage.vue'
import AdminVenueOperationsPage from '@/features/venues/AdminVenueOperationsPage.vue'
import { useAuthStore } from '@/stores/auth'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/',
      component: PublicLayout,
      children: [
        {
          path: '',
          name: 'home',
          component: PublicVenueListPage,
        },
        {
          path: 'venues/:slug',
          name: 'venue-detail',
          component: PublicVenueDetailPage,
        },
        {
          path: 'policies',
          name: 'policies',
          component: PublicPoliciesPage,
        },
        {
          path: 'booking-lookup',
          name: 'booking-lookup',
          component: PublicBookingLookupPage,
        },
        {
          path: 'booking-status/:token',
          name: 'booking-status',
          component: PublicBookingStatusPage,
        },
        {
          path: 'login',
          name: 'login',
          component: LoginPage,
        },
      ],
    },
    {
      path: '/admin',
      component: AdminLayout,
      meta: {
        requiresAuth: true,
      },
      children: [
        {
          path: '',
          name: 'admin-overview',
          component: AdminOverviewPage,
          meta: {
            requiresAuth: true,
            requiredPermission: 'venue.view',
          },
        },
        {
          path: 'bookings',
          name: 'admin-bookings',
          component: AdminBookingsPage,
          meta: {
            requiresAuth: true,
            requiredPermission: 'booking.view',
          },
        },
        {
          path: 'payments',
          name: 'admin-payments',
          component: AdminPaymentsPage,
          meta: {
            requiresAuth: true,
            requiredPermission: 'payment.view',
          },
        },
        {
          path: 'promotions',
          name: 'admin-promotions',
          component: AdminPromotionsPage,
          meta: {
            requiresAuth: true,
            requiredPermission: 'pricing.view',
          },
        },
        {
          path: 'customers',
          name: 'admin-customers',
          component: AdminCustomersPage,
          meta: {
            requiresAuth: true,
            requiredPermission: 'customer.view',
          },
        },
        {
          path: 'reports',
          name: 'admin-reports',
          component: AdminReportsPage,
          meta: {
            requiresAuth: true,
            requiredPermission: 'report.view',
          },
        },
        {
          path: 'venues',
          name: 'admin-venues',
          component: AdminVenuesPage,
          meta: {
            requiresAuth: true,
            requiredPermission: 'venue.view',
          },
        },
        {
          path: 'venues/:id/operations',
          name: 'admin-venue-operations',
          component: AdminVenueOperationsPage,
          meta: {
            requiresAuth: true,
            requiredPermission: 'venue.view',
          },
        },
      ],
    },
  ],
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()
  if (!auth.initialized) {
    await auth.hydrate()
  }

  if (to.meta.requiresAuth && !auth.isAuthenticated) {
    return { name: 'login' }
  }

  const requiredPermission = to.meta.requiredPermission as string | undefined
  if (requiredPermission && !auth.hasPermission(requiredPermission)) {
    return { name: 'home' }
  }

  return true
})

export default router
