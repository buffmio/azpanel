export class HttpError extends Error {
  constructor(message, { status = 0, cause } = {}) {
    super(message, { cause });
    this.name = 'HttpError';
    this.status = status;
  }
}

export function isSuccess(response) {
  return String(response?.status) === '1';
}

export async function postForm(url, fields, {
  fetchImpl = globalThis.fetch,
  timeoutMs = 15000
} = {}) {
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), timeoutMs);

  try {
    const response = await fetchImpl(url, {
      method: 'POST',
      headers: { 'content-type': 'application/x-www-form-urlencoded;charset=UTF-8' },
      body: new URLSearchParams(Object.entries(fields).filter(([, value]) => value !== undefined)),
      credentials: 'same-origin',
      signal: controller.signal
    });
    const contentType = response.headers.get('content-type') ?? '';
    if (!response.ok || !contentType.includes('application/json')) {
      throw new HttpError('服务器暂时无法处理请求', { status: response.status });
    }
    try {
      return await response.json();
    } catch (error) {
      if (error?.name === 'AbortError' && controller.signal.aborted) throw error;
      throw new HttpError('服务器暂时无法处理请求', { status: response.status, cause: error });
    }
  } catch (error) {
    if (error instanceof HttpError) throw error;
    if (error?.name === 'AbortError') throw new HttpError('请求超时，请稍后重试', { cause: error });
    throw new HttpError('网络连接失败，请检查连接后重试', { cause: error });
  } finally {
    clearTimeout(timeout);
  }
}
