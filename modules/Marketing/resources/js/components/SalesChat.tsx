import { useEffect, useRef, useState } from 'react';

/**
 * WURK's sales assistant on wurk.co.za (Sprint 19.5b): a chat button, shown only while HQ has it switched on. The chat
 * itself is the website chat (same accessible panel, cards and fallbacks), loaded only when the button is first tapped.
 */
export function SalesChat() {
  const [on, setOn] = useState(false);
  const button = useRef<HTMLButtonElement>(null);
  useEffect(() => {
    let live = true;
    fetch('/_sales', { headers: { Accept: 'application/json' } })
      .then((r) => live && setOn(r.ok))
      .catch(() => undefined);
    return () => {
      live = false;
    };
  }, []);
  if (!on) return null;
  return (
    <button
      ref={button}
      type="button"
      aria-haspopup="dialog"
      aria-label="Chat with us"
      title="Chat with us"
      className="fixed right-4 bottom-4 z-50 grid size-14 cursor-pointer place-items-center rounded-full bg-[#1B1640] text-white shadow-lg"
      onClick={() => {
        const opener = button.current;
        if (!opener) return;
        void import('@/site-chat-panel').then((m) =>
          m.openChat(opener, { config: '/_sales', message: '/_sales/message' }),
        );
      }}
    >
      <svg
        width="26"
        height="26"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth="2"
        strokeLinecap="round"
        strokeLinejoin="round"
        aria-hidden="true"
      >
        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
      </svg>
    </button>
  );
}
