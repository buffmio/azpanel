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

test('postForm accepts JSON media types with parameters and structured suffixes', async () => {
  for (const contentType of [
    'application/json; charset=UTF-8',
    'Application/JSON ; charset=utf-8',
    'application/problem+json',
    'application/vnd.azpanel.response+json; version=1',
    'text/vnd.azpanel.event+json'
  ]) {
    const fetchImpl = async () => new Response(JSON.stringify({ status: '1' }), {
      headers: { 'content-type': contentType }
    });

    assert.equal(isSuccess(await postForm('/login', {}, { fetchImpl })), true, contentType);
  }
});

test('postForm rejects media types that merely contain application/json text', async () => {
  for (const contentType of [
    'application/jsonp',
    'application/json-patch',
    'text/application/json',
    'text/plain; profile="application/json"'
  ]) {
    const fetchImpl = async () => new Response(JSON.stringify({ status: '1' }), {
      headers: { 'content-type': contentType }
    });

    await assert.rejects(
      postForm('/login', {}, { fetchImpl }),
      error => error instanceof HttpError && error.status === 200,
      contentType
    );
  }
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

test('postForm reports a timeout when the JSON body aborts after response headers arrive', async () => {
  const fetchImpl = async (_url, { signal }) => ({
    ok: true,
    status: 200,
    headers: new Headers({ 'content-type': 'application/json' }),
    json: () => new Promise((resolve, reject) => {
      signal.addEventListener('abort', () => {
        reject(new DOMException('The operation was aborted.', 'AbortError'));
      }, { once: true });
    })
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

test('showNotice fallback can be opened, closed, hidden, and opened again', () => {
  const previousDocument = globalThis.document;
  let closeHandler;
  let closeBindings = 0;
  const titleNode = { textContent: '' };
  const contentNode = { textContent: '' };
  const closeButton = {
    addEventListener(type, handler) {
      assert.equal(type, 'click');
      closeBindings += 1;
      closeHandler = handler;
    }
  };
  const dialog = {
    hidden: true,
    attributes: new Map(),
    querySelector(selector) {
      if (selector === '[data-notice-title]') return titleNode;
      if (selector === '[data-notice-content]') return contentNode;
      if (selector === '[data-notice-close]') return closeButton;
      return null;
    },
    setAttribute(name, value) {
      this.attributes.set(name, value);
    }
  };
  globalThis.document = {
    querySelector(selector) {
      return selector === '[data-notice-dialog]' ? dialog : null;
    }
  };

  try {
    showNotice({ title: '第一次', content: '内容' });
    assert.equal(dialog.hidden, false);
    assert.equal(dialog.attributes.get('role'), 'dialog');
    assert.equal(dialog.attributes.get('aria-modal'), 'true');
    assert.equal(typeof closeHandler, 'function');

    closeHandler();
    assert.equal(dialog.hidden, true);

    showNotice({ title: '第二次', content: '新内容' });
    assert.equal(dialog.hidden, false);
    assert.equal(titleNode.textContent, '第二次');
    assert.equal(contentNode.textContent, '新内容');
    assert.equal(closeBindings, 1);
  } finally {
    globalThis.document = previousDocument;
  }
});
