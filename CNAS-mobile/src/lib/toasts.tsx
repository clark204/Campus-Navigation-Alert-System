import { createContext, useCallback, useContext, useMemo, useRef, useState, type ReactNode } from 'react';

import type { IconName } from '@/components/icon';

/**
 * One toast system for the whole app, like the website's (CNAS-web resources/js/toasts.js, partials/toasts):
 * floating above the tab bar, never part of a screen's layout, so nothing moves when one appears.
 * - success ("Saved", "Password changed"): closes by itself, with the gold timer bar
 * - warning (an app message that needs attention): stays until closed
 * - alert tones, from AlertToasts: `alertWarning` (orange, stays), `alertAdvisory` (yellow, closes by itself),
 *   `alertClear` ("All clear" or "False alarm", green, closes by itself)
 * The emergency is never a toast: it has the takeover and the red pill (components/alert-overlays.tsx).
 */
export type ToastTone = 'success' | 'warning' | 'alertWarning' | 'alertAdvisory' | 'alertClear';

export type ToastInput = {
  tone: ToastTone;
  title: string;
  body?: string | null;
  /** Small capitals line above the title: "WARNING · FLOOD". */
  kind?: string;
  /** The alert's type icon, for the alert tones. */
  icon?: IconName;
  /** A toast with the same key replaces the earlier one (one alert toast at a time). */
  key?: string;
  /** Tapping the toast (not its close button). */
  onPress?: () => void;
};

export type Toast = ToastInput & { id: number; seconds: number | null };

/** These close by themselves; the others stay until closed. */
const AUTO_CLOSE: ToastTone[] = ['success', 'alertAdvisory', 'alertClear'];

/** Same as the web: 6 s, plus 1 s for every 15 characters past 60, up to 12 s. */
export function toastSeconds(text: string): number {
  return Math.min(12, 6 + Math.floor(Math.max(0, text.length - 60) / 15));
}

/** The gap between stacked toasts, as on the web (STACK_GAP). */
export const TOAST_GAP = 12;

type Toasts = {
  toasts: Toast[];
  show: (toast: ToastInput) => number;
  close: (id: number) => void;
  /** Close every toast (the emergency takeover opened). */
  clear: () => void;
};

const ToastContext = createContext<Toasts | null>(null);

export function ToastProvider({ children }: { children: ReactNode }) {
  const [toasts, setToasts] = useState<Toast[]>([]);
  const nextId = useRef(1);

  const show = useCallback((input: ToastInput) => {
    const id = nextId.current++;
    const seconds = AUTO_CLOSE.includes(input.tone) ? toastSeconds(`${input.title}${input.body ?? ''}`) : null;
    setToasts((list) => [...list.filter((toast) => !input.key || toast.key !== input.key), { ...input, id, seconds }]);
    return id;
  }, []);

  const close = useCallback((id: number) => setToasts((list) => list.filter((toast) => toast.id !== id)), []);
  const clear = useCallback(() => setToasts([]), []);

  const value = useMemo(() => ({ toasts, show, close, clear }), [toasts, show, close, clear]);
  return <ToastContext.Provider value={value}>{children}</ToastContext.Provider>;
}

export function useToasts(): Toasts {
  const toasts = useContext(ToastContext);
  if (!toasts) throw new Error('useToasts must be used inside <ToastProvider>');
  return toasts;
}

/** `toast('Saved.')`: the success toast most screens need. */
export function useToast() {
  const { show } = useToasts();
  return useCallback((title: string, tone: 'success' | 'warning' = 'success') => show({ tone, title }), [show]);
}
