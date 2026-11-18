import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { VideoPicker } from './VideoPicker';

const tour = {
  id: '01JTOUR',
  name: 'tour.mov',
  seconds: 12,
  src: '/storage/sites/t/tour.mp4',
  poster: '/storage/sites/t/tour-1280.webp',
};

function respond(status: number, body: unknown) {
  const fetch = vi.fn(
    async () =>
      new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
      }),
  );
  vi.stubGlobal('fetch', fetch);
  return fetch;
}

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('VideoPicker (Sprint 20.2)', () => {
  it('chooses an uploaded video, storing its MP4 and poster', async () => {
    respond(200, { videos: [tour], max_mb: 20, max_seconds: 30 });
    const onChange = vi.fn();
    render(
      <VideoPicker
        value={{ src: '', poster: '' }}
        onChange={onChange}
        videosUrl="/t/website/videos"
      />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Choose from my videos' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Use tour.mov (12 seconds)' }));
    expect(onChange).toHaveBeenCalledWith({ src: tour.src, poster: tour.poster });
  });

  it('uploads a clip and says when the server refuses it', async () => {
    const fetch = respond(422, { errors: { video: ['Upload an MP4, MOV or WebM video.'] } });
    const onChange = vi.fn();
    render(
      <VideoPicker
        value={{ src: '', poster: '' }}
        onChange={onChange}
        videosUrl="/t/website/videos"
      />,
    );
    const file = new File(['x'], 'clip.avi', { type: 'video/x-msvideo' });
    fireEvent.change(screen.getByLabelText('Choose a video to upload'), {
      target: { files: [file] },
    });
    expect((await screen.findByRole('alert')).textContent).toBe(
      'Upload an MP4, MOV or WebM video.',
    );
    expect(fetch.mock.calls[0]![0]).toBe('/t/website/videos');
    expect(onChange).not.toHaveBeenCalled();
  });

  it('removes a video, and explains that owners add videos in the template editor', async () => {
    const onChange = vi.fn();
    render(<VideoPicker value={tour} onChange={onChange} videosUrl="/t/website/videos" />);
    fireEvent.click(screen.getByRole('button', { name: 'Remove video' }));
    expect(onChange).toHaveBeenCalledWith({ src: '', poster: '' });
    cleanup();
    render(<VideoPicker value={{ src: '', poster: '' }} onChange={onChange} />);
    await waitFor(() => expect(screen.getByText(/Owners add their own video/)).toBeTruthy());
  });
});
