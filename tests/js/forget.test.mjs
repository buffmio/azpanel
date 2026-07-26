import assert from 'node:assert/strict';
import test from 'node:test';
import { pathToFileURL } from 'node:url';
import { installAuthPage, preserveBrowserGlobals } from './auth-page-test-helper.mjs';

const forgetScript = pathToFileURL(`${process.cwd()}/public/static/js/auth/forget.js`).href;
const forgetFields = [
  ['email', 'user@example.com'],
  ['passwd', 'new-secret'],
  ['repeat_passwd', 'new-secret'],
  ['verify_code', '654321']
];

test('password reset posts every field and stays disabled until the successful redirect', async () => {
  const restoreGlobals = preserveBrowserGlobals();
  const page = installAuthPage({
    action: '/forget',
    fields: forgetFields,
    formSelector: '[data-auth-forget]'
  });
  const redirects = [];
  const redirectTimers = [];
  let requestBody;
  globalThis.fetch = async (url, init) => {
    assert.equal(url, '/forget');
    requestBody = init.body.toString();
    return new Response(JSON.stringify({ status: '1', title: '重置成功', content: '即将跳转' }), {
      headers: { 'content-type': 'application/json' }
    });
  };
  globalThis.setTimeout = (callback, milliseconds) => {
    if (milliseconds === 1500) {
      redirectTimers.push(callback);
      return 0;
    }
    return 0;
  };
  globalThis.window = { location: { assign(path) { redirects.push(path); } } };

  try {
    await import(`${forgetScript}?successful-redirect`);
    await page.listeners.submit({ preventDefault() {} });

    assert.equal(
      requestBody,
      'email=user%40example.com&passwd=new-secret&repeat_passwd=new-secret&verify_code=654321'
    );
    assert.equal(page.submit.disabled, true);
    assert.equal(page.submit.attributes.get('aria-busy'), 'true');
    assert.equal(page.title.textContent, '重置成功');
    assert.equal(page.content.textContent, '即将跳转');
    assert.equal(redirectTimers.length, 1);
    redirectTimers[0]();
    assert.deepEqual(redirects, ['/login']);
  } finally {
    restoreGlobals();
  }
});

test('password reset restores the submit button after a rejected response', async () => {
  const restoreGlobals = preserveBrowserGlobals();
  const page = installAuthPage({
    action: '/forget',
    fields: forgetFields,
    formSelector: '[data-auth-forget]'
  });
  globalThis.fetch = async () => new Response(
    JSON.stringify({ status: '0', title: '重置失败', content: '验证码错误' }),
    { headers: { 'content-type': 'application/json' } }
  );
  globalThis.window = { location: { assign() { throw new Error('unexpected redirect'); } } };

  try {
    await import(`${forgetScript}?rejected-response`);
    await page.listeners.submit({ preventDefault() {} });

    assert.equal(page.submit.disabled, false);
    assert.equal(page.submit.attributes.has('aria-busy'), false);
    assert.equal(page.title.textContent, '重置失败');
    assert.equal(page.content.textContent, '验证码错误');
  } finally {
    restoreGlobals();
  }
});

test('password reset ignores repeated verification-code requests and restores after errors', async () => {
  const restoreGlobals = preserveBrowserGlobals();
  const page = installAuthPage({
    action: '/forget',
    fields: forgetFields,
    formSelector: '[data-auth-forget]'
  });
  let calls = 0;
  let rejectRequest;
  globalThis.fetch = async (url, init) => {
    calls += 1;
    assert.equal(url, '/forget/code');
    assert.equal(init.body.toString(), 'email=user%40example.com');
    return new Promise((resolve, reject) => {
      rejectRequest = () => reject(new TypeError('offline'));
    });
  };
  globalThis.window = { location: { assign() {} } };

  try {
    await import(`${forgetScript}?request-code-error`);
    const first = page.listeners.requestCode();
    await Promise.resolve();
    const second = page.listeners.requestCode();
    await Promise.resolve();

    assert.equal(calls, 1);
    assert.equal(page.requestCode.disabled, true);
    assert.equal(page.requestCode.attributes.get('aria-busy'), 'true');

    rejectRequest();
    await Promise.all([first, second]);
    assert.equal(page.requestCode.disabled, false);
    assert.equal(page.requestCode.attributes.has('aria-busy'), false);
    assert.equal(page.title.textContent, '请求失败');
    assert.equal(page.content.textContent, '网络连接失败，请检查连接后重试');
  } finally {
    restoreGlobals();
  }
});
