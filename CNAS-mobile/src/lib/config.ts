// EXPO_PUBLIC_API_URL from .env (see .env.example). Must be read with static dot notation so Expo inlines it.
const fromEnv = process.env.EXPO_PUBLIC_API_URL;

/** The CNAS-web site, without a trailing slash, e.g. "http://192.168.1.10:8000" or "https://tcc-mapsafe.online". */
export const SITE_URL = (fromEnv && fromEnv.trim() !== '' ? fromEnv.trim() : 'https://tcc-mapsafe.online').replace(/\/+$/, '');

export const API_URL = `${SITE_URL}/api`;
