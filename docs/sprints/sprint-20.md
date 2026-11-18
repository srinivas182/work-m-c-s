# Sprint 20 — Premium templates

| | |
|---|---|
| **Status** | Approved by WURK (18 Nov 2026: "All approved", with Sprints 19–25 in sequence; photos: "real photographs tenants can replace … an African library or AI generated") |
| **Release** | v0.21.0 |
| **Depends on** | v0.20.0. Builds on: the 24 templates (Sprints 6–7), the section registry and site renderer, the palette engine (WCAG AA pairings), the media library, WURK AI images (decorative only, Sprint 7), the launch checklist, the per-template accessibility, contrast and phone-width checks (18.5, 19.4a) |
| **Next** | Sprint 21 — WhatsApp both ways |

## Why

WURK's businesses compare their site with what they see on Wix, Squarespace and WordPress. Today every category has two designs, one look each, and soft blurred stand-ins instead of photos. To win owners who have never had a website, the first preview must look **better than a premium template elsewhere**, show *their* kind of business (African people, places and products), and still be done in under an hour by someone new to technology.

## What the market does

| Product | What it does well | Gap for WURK's businesses |
|---|---|---|
| Squarespace | Strong art direction, large type, generous spacing, video backgrounds, consistent styles across sections | Few templates per local trade; photos almost never African; US pricing; no booking/deposits through local gateways |
| Wix | Very many templates per industry, motion and scroll effects, video heroes | Templates are hard to switch without rebuilding; heavy pages, slow on mobile data; easy to break the layout |
| WordPress premium themes (Astra, Kadence, Elementor kits) | Many "starter sites", style kits (one design, several looks), sections for every need | Need setup skills; plugins for booking and payments; speed and security depend on the owner |
| Shopify themes | Polished commerce sections, a theme's "presets" (one design, several styles) | Shop only; dollar pricing; no services or bookings |

What leaders agree on (and reviewers look for): structure that fits the business (the hardest thing to change later), a strong first screen, sections such as features, testimonials and galleries, mobile layouts that hold up, motion that suits the brand, and integrations (booking, shop) built in.

**WURK's edge:** every design is built for one kind of African business and already has its booking, shop, menu, rooms or quote sections working; **one design comes in three styles**, and switching style or design never loses the owner's content; pages stay fast on mobile data and pass WCAG 2.2 AA in every colour.

## Scope

### 20.1 Three styles for every design
- Each design comes in **Luxury**, **Modern minimal** and **Bold**. A style sets the type pairing and scale, spacing rhythm, corners, button shape, section backgrounds (Luxury: deep, quiet sections with fine rules; Modern minimal: white space, hairlines, one accent; Bold: large type, solid colour bands), and image treatment.
- The owner chooses a style in signup (with the design) and can change it any time in the editor's **Look** panel, with a live preview. Content is never lost.
- Every style keeps the palette engine's guarantees: text is readable on every background, in every colour the owner chooses.

### 20.2 Richer first screens, motion and new sections
- **Heroes:** full-bleed photo with overlay, split, Ken Burns slideshow, and **video background** (muted, looped, with a visible pause button; a still poster instead under "reduce motion", Save-Data or slow connections). The media library accepts short videos (MP4/WebM, up to 20 MB and 30 seconds).
- **Motion:** gentle reveal on scroll and hover effects, CSS only, off under "reduce motion".
- **New sections:** Numbers (stats that matter), How it works (steps), Logos (brands, clients, medical aids, press), Map and directions (address, a landmark, Google Maps / Waze / Apple Maps buttons, and a map that loads only when tapped), Video. A pricing table and an announcement bar already exist as "Packages & specials" and "Promotion banner".

### 20.3 Demo photos owners replace
- Every design lists its **photo slots** (hero, gallery, team, about…), each with what it should show.
- **Admin → Template photos:** HQ uploads licensed photos per slot, with source, licence and photographer recorded; or generates an **AI banner** for decorative slots (backgrounds, textures, abstract banners) with WURK AI. AI images never stand in for a business's people, work or premises (Sprint 7's rule), so nobody's site shows fake "our work".
- On an owner's site, template photos are marked **demo**:
  - The launch checklist shows "Replace demo photos (N left)".
  - The editor shows a small "Demo photo — replace" badge.
  - Replacing offers the owner's own upload, the media library, another demo photo from the design's set, or an AI banner (decorative slots only).
- **Import by link:** for each slot, HQ pastes a link to a photo on Nappy, Pexels or Unsplash; the server downloads it (only from those sites), makes the sizes and records photographer, licence and source. The slot's description and search words open the right search on each site. `php artisan website:import-template-photos <file>` imports a list in one go.
- Every design must look finished and professional with demo content (as on Wix or WordPress): sharp photos, real-sounding copy, no blurred stand-ins on a launched template.

### 20.4 Fourteen new categories, three designs each
Barbershop, church and NGO, school, catering, spaza and grocery, cleaning, security, real estate, legal and accounting, tailoring and fashion, car wash, agriculture, funeral services, and beauty supplies. Each design has its own structure (not a colour swap), real copy for that business, the right sections (e.g. quote form for cleaning and security, product grid for spaza and beauty supplies, listings for real estate, a donations call to action for church and NGO), and the category's legal pages.

### 20.5 A third design for the twelve existing categories
So every category has at least three designs.

### 20.6 Quality bar and release
- **A written quality bar** (docs/templates/quality-bar.md): what every design must meet against the leaders' premium templates. It covers the first screen, typography, spacing, photography, mobile, motion, speed, accessibility, copy, and launch in under an hour.
- **Checks for every design and style:**
  - WCAG 2.2 AA in its own colours and six others;
  - phone width;
  - mobile Lighthouse ≥ 90 and layout shift < 0.1 (default style; a sample of the others in CI).
- Release **v0.21.0**.

## Decisions

| # | Decision | Recommendation |
|---|---|---|
| A | Where demo photos come from | **Decided (WURK, 18 Nov 2026):** real, sharp photos (no blurred stand-ins) from Nappy, with Pexels and Unsplash for gaps; WURK AI only for decorative banners, used as demo content. Nappy's licence (CC0 plus its own terms) allows commercial use and modification without credit; it forbids reselling photos without significant modification, reposting them on stock sites, and implying the photographer endorses anything. Shipping them as replaceable demo content in templates is ordinary commercial use. CC0 doesn't cover the rights of people in a photo (no model releases), so people photos are always marked demo, the launch checklist asks owners to replace them, and Nappy's sponsored photos are not used. Each photo records its source link, photographer and licence, so one can be swapped everywhere at once. This workspace can't download from photo sites, so HQ imports them on the server (Admin → Template photos, or `website:import-template-photos`) |
| B | AI pictures of people or "our work" | **No.** They'd show customers things the business didn't do. Decorative only, as agreed in Sprint 7 |
| C | Video backgrounds | Owner-uploaded, short (≤ 30 s, ≤ 20 MB); a poster image on phones with Save-Data or slow connections and under "reduce motion"; always a pause button |
| D | How many designs | 3 per category to start (14 new categories × 3 = 42; 12 existing categories + 1 each = 12; 78 designs in all), each in 3 styles (234 looks). A fourth design per category later, where owners ask |
| E | Map section | **Revised in 20.2:** a static image needs a map-tile service, a licence and attribution. Instead: address, landmark and buttons for the map apps people use; the owner can add a map that loads only when the visitor taps "Show the map" (Google's address embed, no key; until the tap, nothing loads and no provider learns of the visit) |

## Acceptance

1. A new owner picks "Barbershop", sees three designs, previews each in Luxury, Modern minimal and Bold, and launches in under an hour.
2. Switching design or style keeps every word and photo the owner added.
3. Every design and style passes WCAG 2.2 AA in seven colour sets and fits a phone; default styles score ≥ 90 on mobile Lighthouse.
4. HQ uploads a licensed photo for a slot; new sites with that design show it, marked demo; the owner replaces it in two taps.
5. A video hero shows a still and a pause button under "reduce motion" and Save-Data, and never autoplays sound.
6. The quality bar is written down, and each design is checked against it before release.

## Parts

20.1 → 20.2 → 20.3 → 20.4 (a–d) → 20.5 → 20.6. Small parts, one full CI run each, and every spec item verified before closing.

## Progress

| Part | PR | Notes |
|---|---|---|
| 20.1 Three styles | #210 | Styles are CSS variables set from `branding.style`, and `site.css` rules that sit outside Tailwind's layers, so all 33 section types follow without being rewritten. The page sets the rhythm: every section except first screens, banners and call-to-action cards is a band candidate, and every other one is a band (`:nth-child(even of .s-band)`; the editor's canvas is counted through Puck's wrappers with `:has()`). Luxury's bands redefine the colour variables from the palette engine's dark scale, so nested cards, borders and links follow and stay AA. Choosing a style sets its fonts and corners (presets kept equal in PHP and TypeScript by a test). Signup asks for the style next to the design. |
| 20.2a New sections and motion | #212 | 20.2 split: 20.2a = four new sections and motion; 20.2b = the richer heroes, video upload, the video hero and the Video section. New: Numbers, How it works, Logos, and Map and directions (decision E revised). The map's frame is allowed by the public sites' policy (`frame-src https://www.google.com`) and created only on tap, with focus moved to it. Motion: sections rise into place as they scroll in (CSS scroll-driven animations; still where unsupported); a full-photo hero settles with a slow zoom. Never while editing or under "reduce motion"; nothing fades, so text is never faint and contrast checks stay true. |
| 20.2b Video | #212 | Uploads are re-encoded by ffmpeg on the server (H.264, ≤ 1280 wide, first 30 s, AAC, metadata removed, fast start) and keep a WebP poster as the row's photo sizes; the storage quota counts both. The published HTML holds only the poster: an island adds the muted loop when motion, Save-Data and the connection (Network Information API: not 2G/3G) allow, with a pause button whose name says what it does next. The Video section plays only on tap, with controls; without JavaScript the poster links to the MP4. Media lists videos apart from photos, finds where each is used by its MP4 or poster address, and deletes the MP4 with it. The image installs ffmpeg; CI's "real tools" job installs it and runs the encode tests with `--fail-on-skipped`. Also fixed a duplicate `security` key in `config/wurk.php` that hid the admin allow-list's emergency switch. |
| 20.3 Demo photos | #213 | Photo places come from each design's stand-in files and are described in `templates/photos/slots.json` (70 places, each with what it shows and search words; a test fails for an undescribed one). HQ fills a place in Admin → a design → Demo photos, by a link to Nappy, Pexels or Unsplash (the server downloads it from those hosts only, follows no redirect elsewhere, and reads the photographer from the page) or by upload. Each photo records licence, photographer and source. WURK AI is refused except for decorative places. `Templates::published()` swaps the stand-ins for HQ's photos, so previews, signup, demo sites and new sites get them at once without a new template version. Demo = anything served from a design's folder: the launch checklist counts them ("Replace demo photos (N left)"), the editor badges them, and the photo picker offers the design's demo set. `website:import-template-photos` imports a list. How-to: docs/templates/demo-photos.md. **Choosing the photos needs a person's eye** (this workspace can't download or view them). |
| 20.4a Barbershop, cleaning, car wash, catering | #214 | Twelve designs written with `scripts/templates/` (Python: `lib.py` helpers, one file per part), which also records each photo place in `photos/slots.json` and draws crisp tonal stand-ins with a line icon (no blur, as WURK asked) until HQ imports photos. Structures differ per design (e.g. barbershop: split hero + menu + gallery; studio: full photo + numbers + video + memberships; mobile: steps + areas + group quotes). No invented reviews or prices; numbers are service promises the owner edits. New categories in `SeedCategoriesCommand` and signup icons. A test checks every design's photos exist in both sizes, block ids are unique, reviews are empty, and new categories have three designs. Fixed: the hero slideshow track is keyboard-scrollable (axe scrollable-region-focusable, first used by catering-events). |
| 20.4b Spaza & grocery, beauty supplies, tailoring & fashion, agriculture | — | Twelve more designs (`scripts/templates/part_b.py`), shop-led where it fits (product sections with an intro block so a page without products still has its heading on the live site — found by the public-page checks). Co-op design uses values instead of numbers, so nothing invented appears as fact. |
