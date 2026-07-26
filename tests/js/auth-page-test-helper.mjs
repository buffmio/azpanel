import assert from 'node:assert/strict';

export function preserveBrowserGlobals() {
  const originals = new Map(
    ['FormData', 'document', 'fetch', 'setTimeout', 'window'].map(name => [name, globalThis[name]])
  );

  return () => {
    for (const [name, value] of originals) {
      if (value === undefined) {
        delete globalThis[name];
      } else {
        globalThis[name] = value;
      }
    }
  };
}

export function installAuthPage({
  action,
  fields,
  formSelector,
  hcaptchaResponse = null
}) {
  const listeners = {};
  const createButton = () => ({
    disabled: false,
    attributes: new Map(),
    setAttribute(name, value) { this.attributes.set(name, value); },
    removeAttribute(name) { this.attributes.delete(name); }
  });
  const submit = createButton();
  const requestCode = createButton();
  requestCode.dataset = { requestCode: `${action}/code` };
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
    action,
    elements: {
      email: { value: fields.find(([name]) => name === 'email')?.[1] ?? '' }
    },
    addEventListener(type, callback) {
      assert.equal(type, 'submit');
      listeners.submit = callback;
    },
    querySelector(selector) {
      if (selector === '[data-submit]') return submit;
      if (selector === '[name="h-captcha-response"]') {
        return hcaptchaResponse === null ? null : { value: hcaptchaResponse };
      }
      return null;
    }
  };
  requestCode.addEventListener = (type, callback) => {
    assert.equal(type, 'click');
    listeners.requestCode = callback;
  };

  globalThis.document = {
    querySelector(selector) {
      if (selector === formSelector) return form;
      if (selector === '[data-request-code]') return requestCode;
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

  return { content, form, listeners, requestCode, submit, title };
}
