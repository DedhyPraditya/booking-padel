<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { ApiError } from '@/services/apiClient'

const auth = useAuthStore()
const router = useRouter()

const email = ref('')
const password = ref('')
const errorMessage = ref<string | null>(null)
const fieldErrors = ref<Record<string, string[]>>({})

async function handleSubmit(): Promise<void> {
  errorMessage.value = null
  fieldErrors.value = {}
  try {
    await auth.login(email.value, password.value)
    if (auth.hasPermission('venue.view')) {
      await router.push({ name: 'admin-overview' })
    } else {
      await router.push({ name: 'home' })
    }
  } catch (err) {
    if (err instanceof ApiError) {
      errorMessage.value = err.message
      fieldErrors.value = err.errors || {}
    } else {
      errorMessage.value = 'Gagal menghubungi server autentikasi.'
    }
  }
}
</script>

<template>
  <section class="mx-auto max-w-md rounded-lg border border-stone-200 bg-white p-6 shadow-xs space-y-5">
    <div>
      <h1 class="text-xl font-bold text-stone-900">Login Admin</h1>
    </div>

    <div
      v-if="errorMessage"
      class="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-800"
    >
      {{ errorMessage }}
    </div>

    <form class="space-y-4" @submit.prevent="handleSubmit">
      <div>
        <label for="email" class="block text-sm font-medium text-stone-700">Email</label>
        <input
          id="email"
          v-model="email"
          type="email"
          required
          autocomplete="email"
          class="mt-1 block w-full rounded-md border border-stone-300 px-3 py-2 text-sm focus:border-terracotta-600 focus:outline-none"
        />
        <p v-if="fieldErrors.email" class="mt-1 text-xs text-red-700">
          {{ fieldErrors.email[0] }}
        </p>
      </div>

      <div>
        <label for="password" class="block text-sm font-medium text-stone-700">Password</label>
        <input
          id="password"
          v-model="password"
          type="password"
          required
          autocomplete="current-password"
          class="mt-1 block w-full rounded-md border border-stone-300 px-3 py-2 text-sm focus:border-terracotta-600 focus:outline-none"
        />
      </div>

      <button
        type="submit"
        :disabled="auth.loading"
        class="w-full rounded-md bg-terracotta-700 px-4 py-2 text-sm font-medium text-white hover:bg-terracotta-800 disabled:opacity-50"
      >
        {{ auth.loading ? 'Memproses...' : 'Masuk' }}
      </button>
    </form>
  </section>
</template>
