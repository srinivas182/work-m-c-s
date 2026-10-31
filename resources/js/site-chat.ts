// The website chat's loader: tiny, so pages stay fast. The panel loads only when the chat is first opened.
const button = document.querySelector<HTMLButtonElement>('[data-chat-open]');
if (button) {
  let opening: Promise<void> | null = null;
  button.addEventListener('click', () => {
    opening ??= import('./site-chat-panel').then((m) => m.openChat(button));
    void opening.then(() => undefined);
  });
}
