import { API_URL } from './config';

/**
 * A failed API call, with a message ready to show. status 0 means the server couldn't be reached.
 * fields: Laravel's 422 errors, first message per field ("code", "email", …).
 */
export class ApiError extends Error {
  constructor(
    message: string,
    readonly status: number,
    readonly fields: Record<string, string> = {},
  ) {
    super(message);
    this.name = 'ApiError';
  }

  /** The message for one field, if the server sent one. */
  field(name: string): string | undefined {
    return this.fields[name];
  }
}

export const NETWORK_MESSAGE = 'Can’t reach CNAS. Check your internet connection and try again.';
const TIMEOUT_MESSAGE = 'CNAS is taking too long to answer. Check your internet connection and try again.';
const SERVER_MESSAGE = 'Something went wrong on the server. Please try again in a moment.';
const GENERIC_MESSAGE = 'Something went wrong. Please try again.';

let authToken: string | null = null;
let onUnauthorized: (() => void) | null = null;

/** The bearer token sent with every call (the session sets it). */
export function setAuthToken(token: string | null) {
  authToken = token;
}

/** Called when the server rejects the token (401), so the session can sign out. */
export function setUnauthorizedHandler(handler: (() => void) | null) {
  onUnauthorized = handler;
}

type Options = {
  method?: 'GET' | 'POST' | 'PUT' | 'DELETE';
  /** A plain object is sent as JSON; FormData as multipart (file uploads). */
  body?: Record<string, unknown> | FormData;
  timeoutMs?: number;
};

/**
 * Call the CNAS-web API. Resolves with the JSON body (undefined for 204); throws ApiError otherwise.
 */
export async function api<T = unknown>(path: string, { method = 'GET', body, timeoutMs = 20000 }: Options = {}): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' };
  const sentToken = authToken;
  if (sentToken) headers.Authorization = `Bearer ${sentToken}`;

  let payload: BodyInit | undefined;
  if (body instanceof FormData) {
    payload = body;
  } else if (body !== undefined) {
    headers['Content-Type'] = 'application/json';
    payload = JSON.stringify(body);
  }

  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);

  let response: Response;
  try {
    response = await fetch(`${API_URL}${path}`, { method, headers, body: payload, signal: controller.signal });
  } catch {
    throw new ApiError(controller.signal.aborted ? TIMEOUT_MESSAGE : NETWORK_MESSAGE, 0);
  } finally {
    clearTimeout(timer);
  }

  if (response.status === 204) return undefined as T;

  const data = await response.json().catch(() => null);

  if (response.ok) return data as T;

  throw toError(response, data, sentToken !== null);
}

function toError(response: Response, data: any, hadToken: boolean): ApiError {
  const serverMessage: string | undefined = typeof data?.message === 'string' ? data.message : undefined;

  switch (response.status) {
    case 401:
      if (hadToken) onUnauthorized?.();
      return new ApiError('Please sign in again.', 401);

    case 422: {
      const fields: Record<string, string> = {};
      for (const [key, messages] of Object.entries<unknown>(data?.errors ?? {})) {
        if (Array.isArray(messages) && typeof messages[0] === 'string') fields[key] = messages[0];
      }
      // Laravel's top message adds "(and 1 more error)"; the first field message reads better on its own.
      const first = Object.values(fields)[0];
      return new ApiError(first ?? serverMessage ?? GENERIC_MESSAGE, 422, fields);
    }

    case 429: {
      // The CNAS limiters already say "Please try again in N seconds."; the plain throttle only says "Too Many Attempts.".
      if (serverMessage && /try again/i.test(serverMessage)) return new ApiError(serverMessage, 429);
      const seconds = Number(response.headers.get('Retry-After'));
      const wait = Number.isFinite(seconds) && seconds > 0 ? `in ${seconds} seconds` : 'in a minute';
      return new ApiError(`Too many tries. Please try again ${wait}.`, 429);
    }

    default:
      if (response.status >= 500) return new ApiError(SERVER_MESSAGE, response.status);
      return new ApiError(serverMessage ?? GENERIC_MESSAGE, response.status);
  }
}

/** A message to show for any error thrown by api(). */
export function errorMessage(error: unknown): string {
  return error instanceof ApiError ? error.message : GENERIC_MESSAGE;
}
