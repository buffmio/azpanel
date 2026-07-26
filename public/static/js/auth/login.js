import { HttpError, isSuccess, postForm } from '../app/http.js';
import { showNotice } from '../app/notice.js';

const form = document.querySelector('[data-auth-login]');

form?.addEventListener('submit', async event => {
  event.preventDefault();
  const submit = form.querySelector('[data-submit]');
  if (submit.disabled) return;

  submit.disabled = true;
  submit.setAttribute('aria-busy', 'true');
  let redirecting = false;

  try {
    const fields = Object.fromEntries(new FormData(form));
    fields.hcaptcha_result = form.querySelector('[name="h-captcha-response"]')?.value ?? '';
    const response = await postForm(form.action, fields);
    showNotice(response);
    if (isSuccess(response)) {
      redirecting = true;
      setTimeout(() => window.location.assign('/user'), 1500);
    }
  } catch (error) {
    showNotice({ title: '请求失败', content: error instanceof HttpError ? error.message : '发生未知错误' });
  } finally {
    if (!redirecting) {
      submit.disabled = false;
      submit.removeAttribute('aria-busy');
    }
  }
});
