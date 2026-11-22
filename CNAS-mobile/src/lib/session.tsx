import * as Device from 'expo-device';
import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';

import { api, ApiError, setAuthToken, setUnauthorizedHandler } from './api';
import { clearToken, getToken, readJson, removeKeys, saveToken, writeJson } from './storage';
import type { User } from './types';

// The last /api/me answer, so a signed-in student still sees Home (and the emergency numbers) when the server can't
// be reached at start-up. Cleared on sign-out.
export const USER_CACHE_KEY = 'cnas.user';
// "I'm safe" answers already sent from this phone (alerts.tsx). Cleared on sign-out.
export const MARKED_SAFE_KEY = 'cnas.alerts.safe';

type Session = {
  /** false until the stored token has been checked at start-up. */
  ready: boolean;
  user: User | null;
  signIn: (login: string, password: string) => Promise<User>;
  signOut: () => Promise<void>;
  setUser: (user: User) => void;
};

const SessionContext = createContext<Session | null>(null);

/** One token per device, named after the phone (shown in the admin's sign-in history). */
function deviceName(): string {
  return [Device.manufacturer, Device.modelName].filter(Boolean).join(' ').trim() || 'Phone';
}

export function SessionProvider({ children }: { children: ReactNode }) {
  const [ready, setReady] = useState(false);
  const [user, setUserState] = useState<User | null>(null);

  const setUser = useCallback((next: User) => {
    setUserState(next);
    writeJson(USER_CACHE_KEY, next);
  }, []);

  const forget = useCallback(async () => {
    setAuthToken(null);
    setUserState(null);
    await clearToken();
    await removeKeys([USER_CACHE_KEY, MARKED_SAFE_KEY]);
  }, []);

  // A 401 from any call means the token expired or was revoked (password reset, admin): back to Welcome.
  useEffect(() => {
    setUnauthorizedHandler(() => {
      forget();
    });
    return () => setUnauthorizedHandler(null);
  }, [forget]);

  useEffect(() => {
    (async () => {
      const token = await getToken();
      if (token) {
        setAuthToken(token);
        try {
          setUser(await api<User>('/me'));
        } catch (error) {
          // 401 already signed out (handler above). Offline or a server error: keep the token, use the last answer.
          if (!(error instanceof ApiError && error.status === 401)) {
            const cached = await readJson<User | null>(USER_CACHE_KEY, null);
            if (cached) setUserState(cached);
          }
        }
      }
      setReady(true);
    })();
  }, [setUser]);

  const signIn = useCallback(
    async (login: string, password: string) => {
      const result = await api<{ token: string; user: User }>('/login', {
        method: 'POST',
        body: { login: login.trim(), password, device_name: deviceName() },
      });
      await saveToken(result.token);
      setAuthToken(result.token);
      setUser(result.user);
      return result.user;
    },
    [setUser],
  );

  const signOut = useCallback(async () => {
    try {
      await api('/logout', { method: 'POST' });
    } catch {
      // Offline or already expired: the token is forgotten on this phone either way.
    }
    await forget();
  }, [forget]);

  const value = useMemo(
    () => ({ ready, user, signIn, signOut, setUser }),
    [ready, user, signIn, signOut, setUser],
  );

  return <SessionContext.Provider value={value}>{children}</SessionContext.Provider>;
}

export function useSession(): Session {
  const session = useContext(SessionContext);
  if (!session) throw new Error('useSession must be used inside <SessionProvider>');
  return session;
}
