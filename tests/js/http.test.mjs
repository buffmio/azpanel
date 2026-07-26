import test from 'node:test';
import assert from 'node:assert/strict';
import { HttpError, isSuccess, postForm } from '../../public/static/js/app/http.js';
import { showNotice } from '../../public/static/js/app/notice.js';

test('postForm preserves legacy form encoding and response fields', async () => {
  const fetchImpl = async (url, init) => {
    assert.equal(url, '/login');
    assert.equal(init.method, 'POST');
    assert.equal(init.body.toString(), 'email=a%40example.com&password=secret');
    return new Response(JSON.stringify({ status: '1', title: '登录成功', content: '欢迎回来' }), {
      status: 200,
      headers: { 'content-type': 'application/json' }
    });
  };

  const response = await postForm('/login', { email: 'a@example.com', password: 'secret' }, { fetchImpl });
  assert.equal(isSuccess(response), true);
  assert.equal(response.title, '登录成功');
});

test('postForm throws HttpError for non-json server failures', async () => {
  const fetchImpl = async () => new Response('Bad Gateway', { status: 502 });

  await assert.rejects(
    postForm('/login', {}, { fetchImpl }),
    error => error instanceof HttpError && error.status === 502
  );
});

test('postForm throws HttpError for JSON HTTP failures', async () => {
  const fetchImpl = async () => new Response(JSON.stringify({ status: '0' }), {
    status: 500,
    headers: { 'content-type': 'application/json' }
  });

  await assert.rejects(
    postForm('/login', {}, { fetchImpl }),
    error => error instanceof HttpError && error.status === 500
  );
});

test('postForm preserves the status for malformed JSON responses', async () => {
  const fetchImpl = async () => new Response('{', {
    status: 200,
    headers: { 'content-type': 'application/json' }
  });

  await assert.rejects(
    postForm('/login', {}, { fetchImpl }),
    error => error instanceof HttpError
      && error.status === 200
      && error.message === '服务器暂时无法处理请求'
  );
});

test('postForm reports a timeout when its abort signal is triggered', async () => {
  const fetchImpl = async (_url, { signal }) => new Promise((resolve, reject) => {
    signal.addEventListener('abort', () => {
      reject(new DOMException('The operation was aborted.', 'AbortError'));
    }, { once: true });
  });

  await assert.rejects(
    postForm('/login', {}, { fetchImpl, timeoutMs: 1 }),
    error => error instanceof HttpError && error.status === 0 && error.message === '请求超时，请稍后重试'
  );
});

test('showNotice renders server text as text content instead of HTML', () => {
  const previousDocument = globalThis.document;
  const titleNode = { textContent: '' };
  const contentNode = { textContent: '' };
  Object.defineProperty(contentNode, 'innerHTML', {
    set() {
      throw new Error('unsafe HTML rendering');
    }
  });
  const dialog = {
    open: false,
    querySelector(selector) {
      return selector === '[data-notice-title]' ? titleNode : contentNode;
    },
    showModal() {
      this.open = true;
    }
  };
  globalThis.document = {
    querySelector(selector) {
      return selector === '[data-notice-dialog]' ? dialog : null;
    }
  };

  try {
    showNotice({ title: '<strong>提示</strong>', content: '<img src=x onerror=alert(1)>' });
    assert.equal(titleNode.textContent, '<strong>提示</strong>');
    assert.equal(contentNode.textContent, '<img src=x onerror=alert(1)>');
    assert.equal(dialog.open, true);
  } finally {
    globalThis.document = previousDocument;
  }
});
