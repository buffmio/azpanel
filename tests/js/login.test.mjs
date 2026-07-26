import assert from 'node:assert/strict';
import test from 'node:test';
import { pathToFileURL } from 'node:url';

const loginScript = pathToFileURL(`${process.cwd()}/public/static/js/auth/login.js`).href;

function installLoginPage({ fields = [['email', 'a@example.com'], ['password', 'secret']], hcaptchaResponse = null } = {}) {
  let listener;
  const submit = {
    disabled: false,
    attributes: new Map(),
    setAttribute(name, value) { this.attributes.set(name, value); },
    removeAttribute(name) { this.attributes.delete(name); }
  };
  const title = { textContent: '' };
  const content = { textContent: '' };
  const dialog = {
    open: false,
    querySelector(selector) {
      return selector === '[data-notice-title]' ? title : content;
    },
    showModal() { this.open = true; }
  };
  const form = {
    action: '/login',
    addEventListener(type, callback) {
      assert.equal(type, 'submit');
      listener = callback;
    },
    querySelector(selector) {
      if (selector === '[data-submit]') return submit;
      if (selector === '[name="h-captcha-response"]') return hcaptchaResponse === null ? null : { value: hcaptchaResponse };
      return null;
    }
  };

  globalThis.document = {
    querySelector(selector) {
      if (selector === '[data-auth-login]') return form;
      if (selector === '[data-notice-dialog]') return dialog;
      return null;
    }
  };
  globalThis.FormData = class {
    constructor(receivedForm) {
      assert.equal(receivedForm, form);
    }

    *[Symbol.iterator]() {
      yield* fields;
    }
  };

  return { content, form, getListener: () => listener, submit, title };
}

test('login ignores a second submit while the first request is pending', async () => {
  const original = {
    FormData: globalThis.FormData,
    document: globalThis.document,
    fetch: globalThis.fetch,
    window: globalThis.window
  };
  const page = installLoginPage();
  let calls = 0;
  const resolveResponses = [];
  let first;
  let second;
  globalThis.fetch = async () => {
    calls += 1;
    return new Promise(resolve => {
      resolveResponses.push(() => resolve(new Response(JSON.stringify({ status: '0' }), {
        headers: { 'content-type': 'application/json' }
      })));
    });
  };
  globalThis.window = { location: { assign() {} } };

  try {
    await import(`${loginScript}?duplicate-submit`);
    const event = { preventDefault() {} };
    first = page.getListener()(event);
    await Promise.resolve();
    second = page.getListener()(event);
    await Promise.resolve();

    assert.equal(calls, 1);

    resolveResponses.forEach(resolve => resolve());
    await Promise.all([first, second]);
  } finally {
    resolveResponses.forEach(resolve => resolve());
    await Promise.allSettled([first, second].filter(Boolean));
    Object.assign(globalThis, original);
  }
});

test('login maps hCaptcha response and stays disabled until successful redirect', async () => {
  const original = {
    FormData: globalThis.FormData,
    document: globalThis.document,
    fetch: globalThis.fetch,
    setTimeout: globalThis.setTimeout,
    window: globalThis.window
  };
  const page = installLoginPage({
    fields: [
      ['email', 'a@example.com'],
      ['password', 'secret'],
      ['hcaptcha_result', ''],
      ['h-captcha-response', 'hcaptcha-token']
    ],
    hcaptchaResponse: 'hcaptcha-token'
  });
  const redirects = [];
  const redirectTimers = [];
  let requestBody;
  globalThis.fetch = async (url, init) => {
    assert.equal(url, '/login');
    requestBody = init.body.toString();
    return new Response(JSON.stringify({ status: '1', title: '登录成功', content: '欢迎回来' }), {
      headers: { 'content-type': 'application/json' }
    });
  };
  globalThis.setTimeout = (callback, milliseconds) => {
    if (milliseconds === 1500) {
      redirectTimers.push(callback);
      return 0;
    }
    return original.setTimeout(callback, milliseconds);
  };
  globalThis.window = { location: { assign(path) { redirects.push(path); } } };

  try {
    await import(`${loginScript}?successful-redirect`);
    await page.getListener()({ preventDefault() {} });

    assert.equal(requestBody, 'email=a%40example.com&password=secret&hcaptcha_result=hcaptcha-token');
    assert.equal(page.submit.disabled, true);
    assert.equal(page.submit.attributes.get('aria-busy'), 'true');
    assert.equal(page.title.textContent, '登录成功');
    assert.equal(page.content.textContent, '欢迎回来');
    assert.equal(redirectTimers.length, 1);
    redirectTimers[0]();
    assert.deepEqual(redirects, ['/user']);
  } finally {
    Object.assign(globalThis, original);
  }
});

test('login restores the submit button after a request error', async () => {
  const original = {
    FormData: globalThis.FormData,
    document: globalThis.document,
    fetch: globalThis.fetch,
    window: globalThis.window
  };
  const page = installLoginPage();
  globalThis.fetch = async () => {
    throw new Error('offline');
  };
  globalThis.window = { location: { assign() {} } };

  try {
    await import(`${loginScript}?error-cleanup`);
    await page.getListener()({ preventDefault() {} });

    assert.equal(page.submit.disabled, false);
    assert.equal(page.submit.attributes.has('aria-busy'), false);
    assert.equal(page.title.textContent, '请求失败');
    assert.equal(page.content.textContent, '网络连接失败，请检查连接后重试');
  } finally {
    Object.assign(globalThis, original);
  }
});
