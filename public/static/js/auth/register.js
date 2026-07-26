import { HttpError, isSuccess, postForm } from '../app/http.js';
import { showNotice } from '../app/notice.js';

const form = document.querySelector('[data-auth-register]');
const requestCode = document.querySelector('[data-request-code]');

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
    delete fields['h-captcha-response'];
    const response = await postForm(form.action, fields);
    showNotice(response);
    if (isSuccess(response)) {
      redirecting = true;
      setTimeout(() => window.location.assign('/login'), 1500);
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

requestCode?.addEventListener('click', async () => {
  if (!form || requestCode.disabled) return;

  requestCode.disabled = true;
  requestCode.setAttribute('aria-busy', 'true');

  try {
    showNotice(await postForm(requestCode.dataset.requestCode, { email: form.elements.email.value }));
  } catch (error) {
    showNotice({ title: '请求失败', content: error instanceof HttpError ? error.message : '发生未知错误' });
  } finally {
    requestCode.disabled = false;
    requestCode.removeAttribute('aria-busy');
  }
});
