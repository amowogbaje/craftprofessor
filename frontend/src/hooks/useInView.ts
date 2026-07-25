import { useEffect, useRef } from 'react'

export function useInView(onInView: () => void, enabled: boolean) {
  const ref = useRef<HTMLDivElement | null>(null)

  useEffect(() => {
    if (!enabled || !ref.current) return
    const el = ref.current
    const observer = new IntersectionObserver(
      (entries) => {
        if (entries[0]?.isIntersecting) onInView()
      },
      { rootMargin: '600px 0px' }, // fire well before the sentinel is on-screen
    )
    observer.observe(el)
    return () => observer.disconnect()
  }, [onInView, enabled])

  return ref
}
