import assert from 'node:assert/strict';
import test from 'node:test';
import { pathToFileURL } from 'node:url';
import { installAuthPage, preserveBrowserGlobals } from './auth-page-test-helper.mjs';

const registerScript = pathToFileURL(`${process.cwd()}/public/static/js/auth/register.js`).href;
const registerFields = [
  ['email', 'new@example.com'],
  ['passwd', 'secret'],
  ['repeat_passwd', 'secret'],
  ['verify_code', '123456'],
  ['code', 'captcha'],
  ['hcaptcha_result', ''],
  ['h-captcha-response', 'hcaptcha-token']
];

test('register maps hCaptcha and stays disabled until the successful redirect', async () => {
  const restoreGlobals = preserveBrowserGlobals();
  const page = installAuthPage({
    action: '/register',
    fields: registerFields,
    formSelector: '[data-auth-register]',
    hcaptchaResponse: 'hcaptcha-token'
  });
  const redirects = [];
  const redirectTimers = [];
  let requestBody;
  globalThis.fetch = async (url, init) => {
    assert.equal(url, '/register');
    requestBody = init.body.toString();
    return new Response(JSON.stringify({ status: '1', title: '注册成功', content: '即将跳转' }), {
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
    await import(`${registerScript}?successful-redirect`);
    await page.listeners.submit({ preventDefault() {} });

    assert.equal(
      requestBody,
      'email=new%40example.com&passwd=secret&repeat_passwd=secret&verify_code=123456&code=captcha&hcaptcha_result=hcaptcha-token'
    );
    assert.equal(page.submit.disabled, true);
    assert.equal(page.submit.attributes.get('aria-busy'), 'true');
    assert.equal(page.title.textContent, '注册成功');
    assert.equal(page.content.textContent, '即将跳转');
    assert.equal(redirectTimers.length, 1);
    redirectTimers[0]();
    assert.deepEqual(redirects, ['/login']);
  } finally {
    restoreGlobals();
  }
});

test('register restores the submit button after a rejected response', async () => {
  const restoreGlobals = preserveBrowserGlobals();
  const page = installAuthPage({
    action: '/register',
    fields: registerFields,
    formSelector: '[data-auth-register]'
  });
  globalThis.fetch = async () => new Response(
    JSON.stringify({ status: '0', title: '注册失败', content: '验证码错误' }),
    { headers: { 'content-type': 'application/json' } }
  );
  globalThis.window = { location: { assign() { throw new Error('unexpected redirect'); } } };

  try {
    await import(`${registerScript}?rejected-response`);
    await page.listeners.submit({ preventDefault() {} });

    assert.equal(page.submit.disabled, false);
    assert.equal(page.submit.attributes.has('aria-busy'), false);
    assert.equal(page.title.textContent, '注册失败');
    assert.equal(page.content.textContent, '验证码错误');
  } finally {
    restoreGlobals();
  }
});

test('register ignores repeated verification-code requests and restores the button', async () => {
  const restoreGlobals = preserveBrowserGlobals();
  const page = installAuthPage({
    action: '/register',
    fields: registerFields,
    formSelector: '[data-auth-register]'
  });
  let calls = 0;
  let resolveRequest;
  globalThis.fetch = async (url, init) => {
    calls += 1;
    assert.equal(url, '/register/code');
    assert.equal(init.body.toString(), 'email=new%40example.com');
    return new Promise(resolve => {
      resolveRequest = () => resolve(new Response(
        JSON.stringify({ status: '1', title: '已发送', content: '请检查邮箱' }),
        { headers: { 'content-type': 'application/json' } }
      ));
    });
  };
  globalThis.window = { location: { assign() {} } };

  try {
    await import(`${registerScript}?request-code`);
    const first = page.listeners.requestCode();
    await Promise.resolve();
    const second = page.listeners.requestCode();
    await Promise.resolve();

    assert.equal(calls, 1);
    assert.equal(page.requestCode.disabled, true);
    assert.equal(page.requestCode.attributes.get('aria-busy'), 'true');

    resolveRequest();
    await Promise.all([first, second]);
    assert.equal(page.requestCode.disabled, false);
    assert.equal(page.requestCode.attributes.has('aria-busy'), false);
    assert.equal(page.title.textContent, '已发送');
  } finally {
    restoreGlobals();
  }
});

test('register restores the submit button and reports an unexpected request error', async () => {
  const restoreGlobals = preserveBrowserGlobals();
  const page = installAuthPage({
    action: '/register',
    fields: registerFields,
    formSelector: '[data-auth-register]'
  });
  globalThis.fetch = async () => {
    throw new TypeError('offline');
  };
  globalThis.window = { location: { assign() {} } };

  try {
    await import(`${registerScript}?request-error`);
    await page.listeners.submit({ preventDefault() {} });

    assert.equal(page.submit.disabled, false);
    assert.equal(page.submit.attributes.has('aria-busy'), false);
    assert.equal(page.title.textContent, '请求失败');
    assert.equal(page.content.textContent, '网络连接失败，请检查连接后重试');
  } finally {
    restoreGlobals();
  }
});
