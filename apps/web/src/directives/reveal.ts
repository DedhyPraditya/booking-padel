import type { Directive } from 'vue'

let sharedObserver: IntersectionObserver | null = null

function getObserver(): IntersectionObserver | null {
  if (typeof window === 'undefined' || !('IntersectionObserver' in window)) {
    return null
  }

  if (!sharedObserver) {
    sharedObserver = new IntersectionObserver(
      (entries) => {
        for (const entry of entries) {
          if (entry.isIntersecting) {
            entry.target.classList.add('is-visible')
            sharedObserver?.unobserve(entry.target)
          }
        }
      },
      {
        root: null,
        rootMargin: '0px 0px -40px 0px',
        threshold: 0.08,
      },
    )
  }

  return sharedObserver
}

export const vReveal: Directive<HTMLElement, number | undefined> = {
  mounted(el, binding) {
    el.classList.add('reveal')
    if (typeof binding.value === 'number' && binding.value > 0) {
      el.style.transitionDelay = `${binding.value}ms`
    }

    const observer = getObserver()
    if (!observer) {
      el.classList.add('is-visible')
      return
    }

    observer.observe(el)
  },
  unmounted(el) {
    sharedObserver?.unobserve(el)
  },
}

declare module 'vue' {
  export interface GlobalDirectives {
    vReveal: typeof vReveal
  }
}
