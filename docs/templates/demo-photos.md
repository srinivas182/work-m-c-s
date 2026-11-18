# Demo photos for templates

Every design should look finished the moment someone previews it. That means sharp, real photos, never blurred stand-ins. Owners then swap the demo photos for their own (Sprint 20.3).

## Rules

| | |
|---|---|
| **People, work and premises** | Licensed photos only, from **Nappy** first (African people and places), then Pexels or Unsplash. |
| **Decorative places** (backgrounds, textures, abstract banners) | A licensed photo, or a **WURK AI** image. The server refuses AI images anywhere else. |
| **No blur** | Use a photo that is sharp at 1600 pixels wide. Landscape for heroes and wide sections. |
| **Fit** | The photo should show what the place says (Admin shows "Shows: …" for each). Prefer African people, places and products, and no visible brands or logos. |
| **Licences** | Nappy is CC0 (commercial use, changes allowed, no credit needed; don't resell photos unchanged or repost them on stock sites). Pexels and Unsplash have their own free licences with similar terms. Never use "sponsored" photos. |
| **Real people** | Free libraries have no model releases. Demo photos are always marked **demo** on a business's site, the launch checklist asks the owner to replace them, and a demo photo must never be presented as the business's own staff, customers or work. |
| **Records** | Each photo keeps its source page, photographer (when known) and licence. If anyone objects, replace that place's photo: new sites get the new one immediately. |

## Adding photos (Admin)

**Admin → Templates →** a design **→ Demo photos** lists every photo place with what it should show.

1. Choose **Find one: Nappy / Pexels / Unsplash**. Each opens a search with the place's words.
2. Copy the photo's page link (for example `https://nappy.co/photo/…`), or its image link.
3. **Add photo:** paste the link and choose the licence. The photographer is filled in when the page says who it is; you can type it otherwise. Instead of a link, you can upload a file (for a paid licence or your own photo).
4. The server downloads the photo, but only from those three sites, and never follows a redirect elsewhere. It re-encodes the photo without its metadata, makes the sizes, and records the credit.
5. **New sites** with the design get the photo straight away, as do previews, signup and demo sites. Sites made before keep their photos.

**Back to the stand-in** removes the photo from that place for new sites. The file stays, because earlier sites may still use it.

## WURK's chosen photos (installed by default)

`modules/Website/templates/photos/photos.json` lists a chosen **Pexels** photo for every photo place of every design (215 places in 78 designs). Each was picked to match what the place shows, preferring African people and places, with no brands. Each row links the photo's image and its Pexels page (recorded as the credit).

`php artisan website:install-templates`, which runs on every deploy, imports them after installing the designs. So a **new installation shows real photos at once**, like a Wix or WordPress demo site:
- Photos already in place are skipped, so later deploys fetch only what's missing.
- A photo that can't be fetched is reported, and its place keeps its stand-in. The install itself still succeeds. Run it again later, or add that photo in Admin.
- HQ can still change any photo in Admin, and **Back to the stand-in** works as before. The install never replaces a photo HQ chose, because the place is no longer empty.
- To skip the photos: `--no-photos`, or `WURK_TEMPLATE_DEMO_PHOTOS=false` (used in tests and the offline end-to-end setup).

To change the list, edit `photos.json`. A test checks that it covers every photo place, links only to Pexels, and uses no photo twice.

## Importing a list (server)

On a server that can reach the photo sites:

```bash
php artisan website:import-template-photos photos.json --dry-run   # check the list
php artisan website:import-template-photos photos.json
```

`photos.json` is a list:

```json
[
  {"template": "salon-hair-braids", "slot": "hero", "link": "https://images.pexels.com/photos/123/pexels-photo-123.jpeg?w=1920",
   "source": "https://www.pexels.com/photo/…-123/", "photographer": "…", "licence": "Pexels licence"}
]
```

- `link` is the photo's page or its image.
- `source` is the photo's page, recorded as the credit when `link` is the image.
- Licences: `Nappy (CC0)`, `Pexels licence`, `Unsplash licence`, `Paid licence` or `Own photo`. AI images are added in Admin only.
- Rows whose link is already in place are skipped, so the import is safe to run again. Problems are listed, and the other rows still import.

## The photo places

Until HQ adds a photo, a place shows a stand-in. New designs (Sprint 20.4 on) use crisp, tonal placeholders with a line icon, never blurred. `scripts/templates/` writes them along with the designs.


Places come from the stand-in files a design's pages use (`/images/templates/{design}/{place}-1600.webp`). What each should show, and the search words, are in `modules/Website/templates/photos/slots.json`. A test fails if a design uses a place that file doesn't describe.

## On a business's site

- The launch checklist shows **Replace demo photos (N left)** and links to the first page that still has one.
- In the editor, a demo photo has a **Demo** badge and the note "replace it with one of your own business".
- **Choose from my photos** also offers the design's other demo photos, to swap one for a better fit until the owner has their own.
