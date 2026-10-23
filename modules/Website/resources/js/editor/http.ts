/** JSON requests from the editor, with Laravel's CSRF protection (XSRF-TOKEN cookie). */
function xsrf(): string {
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
  return match?.[1] ? decodeURIComponent(match[1]) : '';
}

export async function request<T>(
  method: 'GET' | 'POST' | 'PUT',
  url: string,
  body?: unknown,
): Promise<{ status: number; data: T }> {
  const isForm = body instanceof FormData;
  const response = await fetch(url, {
    method,
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      'X-XSRF-TOKEN': xsrf(),
      'X-Requested-With': 'XMLHttpRequest',
      ...(body !== undefined && !isForm ? { 'Content-Type': 'application/json' } : {}),
    },
    body: body === undefined ? undefined : isForm ? body : JSON.stringify(body),
  });
  const data = (await response.json().catch(() => ({}))) as T;
  return { status: response.status, data };
}

/** The first validation message Laravel returned, if any. */
export function firstError(data: unknown): string | null {
  const errors = (data as { errors?: Record<string, string[]> }).errors;
  const first = errors ? Object.values(errors)[0]?.[0] : undefined;
  return first ?? (data as { message?: string }).message ?? null;
}
