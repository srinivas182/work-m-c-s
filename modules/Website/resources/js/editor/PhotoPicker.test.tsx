import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { PhotoPicker } from './PhotoPicker';

const library = {
  photos: [
    { src: '/storage/sites/t/real-1600.webp', alt: 'Braids', name: 'braids.jpg', ai: false },
    { src: '/storage/sites/t/aiimg-1600.webp', alt: 'Calm light', name: 'AI image', ai: true },
  ],
  used_mb: 1,
  limit_mb: 0,
};
const ai = { url: '/t/website/ai/image', quoteUrl: '/t/website/ai/quote', tenantBase: '/t' };

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

function open(allowAi: boolean) {
  vi.stubGlobal(
    'fetch',
    vi.fn(
      async () =>
        new Response(JSON.stringify(library), {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        }),
    ),
  );
  render(
    <PhotoPicker
      value={[]}
      onChange={() => {}}
      max={1}
      photosUrl="/t/website/photos"
      allowAi={allowAi}
      ai={ai}
    />,
  );
}

describe('PhotoPicker and AI images', () => {
  it('offers generating and shows AI images (badged) where AI images are allowed', async () => {
    open(true);
    expect(screen.getByLabelText(/Or generate an image with WURK AI/)).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Choose from my photos' }));
    await waitFor(() =>
      expect(screen.getByRole('button', { name: 'Use AI image (AI image)' })).toBeTruthy(),
    );
    expect(screen.getByRole('button', { name: 'Use braids.jpg' })).toBeTruthy();
  });

  it('neither offers nor shows AI images elsewhere', async () => {
    open(false);
    expect(screen.queryByLabelText(/Or generate an image with WURK AI/)).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Choose from my photos' }));
    await waitFor(() =>
      expect(screen.getByRole('button', { name: 'Use braids.jpg' })).toBeTruthy(),
    );
    expect(screen.queryByRole('button', { name: /AI image/ })).toBeNull();
  });
});
