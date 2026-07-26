const fallbackCloseButtons = new WeakSet();

export function showNotice({ title = '提示', content = '' } = {}) {
  const dialog = document.querySelector('[data-notice-dialog]');
  const titleNode = dialog?.querySelector('[data-notice-title]');
  const contentNode = dialog?.querySelector('[data-notice-content]');
  if (!dialog || !titleNode || !contentNode) return;

  titleNode.textContent = String(title);
  contentNode.textContent = String(content);
  const closeButton = dialog.querySelector('[data-notice-close]');
  if (typeof closeButton?.addEventListener === 'function' && !fallbackCloseButtons.has(closeButton)) {
    closeButton.addEventListener('click', () => {
      if (typeof dialog.close === 'function') {
        dialog.close();
      } else {
        dialog.hidden = true;
      }
    });
    fallbackCloseButtons.add(closeButton);
  }

  if (typeof dialog.showModal === 'function') {
    if (!dialog.open) dialog.showModal();
    return;
  }

  dialog.setAttribute('role', 'dialog');
  dialog.setAttribute('aria-modal', 'true');
  dialog.hidden = false;
}
