export function showNotice({ title = '提示', content = '' } = {}) {
  const dialog = document.querySelector('[data-notice-dialog]');
  const titleNode = dialog?.querySelector('[data-notice-title]');
  const contentNode = dialog?.querySelector('[data-notice-content]');
  if (!dialog || !titleNode || !contentNode) return;

  titleNode.textContent = String(title);
  contentNode.textContent = String(content);
  if (typeof dialog.showModal === 'function') {
    if (!dialog.open) dialog.showModal();
    return;
  }
  dialog.hidden = false;
}
