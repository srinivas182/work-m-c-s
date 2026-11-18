# Template quality bar

Every WURK design must meet this bar before release, measured against the premium templates of Squarespace, Wix, WordPress (Astra, Kadence, Elementor kits) and Shopify. The bar applies in each of the three styles (Luxury, Modern minimal, Bold) and in any colour an owner chooses.

Each line says how it's checked:
- **[auto]:** a test or CI gate fails if it isn't met.
- **[review]:** checked by a person before release, using the checklist at the end.

## 1. Structure fits the business

- **[review]** The pages are the ones this kind of business needs: what you do, prices or packages, proof, how to reach you or book. The home page answers "what, where, how much, how to book" in the first two screens.
- **[review]** The working sections are in place: booking, quote form with the right questions, menu, rooms, listings, shop, timetable or giving, whichever fits.
- **[review]** The three designs in a category differ in structure (layout, order and sections), not just in colour.
- **[auto]** Every design has one main heading per page, and every page has content on the live site even before the owner adds anything (public-page accessibility checks).
- **[auto]** Every category has at least three designs (`InstallTemplatesTest`).

## 2. First screen

- **[review]** A strong first screen: a clear promise in under ten words, one sentence of detail, one main button, and a photo, collage, slideshow or video that shows the work.
- **[auto]** Text on photos is readable (gradient shading, AA contrast in the contrast checks).
- **[review]** The main button leads to the action the business wants: book, quote, order or call.

## 3. Typography and spacing

- **[review]** One type pairing per design, from the eight WURK pairings. Headings are balanced (`text-balance`), and body text stays at 16 px or more.
- **[auto]** Styles set the type scale, spacing rhythm, corners and button shape (`theme.test.ts`). Every other section is a band (`site.css`).
- **[review]** Sections breathe: the style's section spacing, with no cramped cards and no orphaned single items. Feature grids use 3 or 6 items; four steps sit in one row.

## 4. Photography

- **[review]** Sharp, real photos, never blurred, showing African people, places and products, from licensed libraries (`docs/templates/demo-photos.md`). WURK AI only in decorative places.
- **[auto]** Every photo place has a description and search words (`TemplatePhotosTest`), and stand-ins exist in both sizes (`InstallTemplatesTest`).
- **[auto]** Owners see demo photos marked **Demo**, and the launch checklist counts them.

## 5. Copy

- **[review]** Real copy for that kind of business, in plain English, written to the visitor ("you"). No lorem ipsum. Where only the owner knows the fact (history, team, registration numbers), the copy says what to write ("Tell people…").
- **[auto]** No invented reviews: Testimonials ship empty (`InstallTemplatesTest`).
- **[review]** No invented facts presented as true. Prices ship as "Ask us" or "Price on request". Numbers are service promises the owner edits, or prompts for the owner.
- **[review]** Regulated businesses show where their registration goes: PSIRA, PPRA, LPC, FSP, NHBRC, QCTO/SETA, DBE, NPO/PBO. Law firms state that the site isn't legal advice.

## 6. Mobile

- **[auto]** Every page fits a 360 px-wide phone without sideways scrolling, in every style (`phone-width.spec`).
- **[auto]** Touch targets are 44 px or more (accessibility crawl).
- **[review]** On a phone, the main button is on the first screen.

## 7. Motion

- **[auto]** Motion is CSS only, never fades text, and is off in the editor and under "reduce motion" (`site.css`, blocks tests).
- **[auto]** A video hero shows its still picture under "reduce motion", Save-Data and slow connections, and always has a pause button (islands tests).

## 8. Speed

- **[auto]** Mobile Lighthouse score of 90 or more, with layout shift under 0.1, on every design's home page (performance gate, CI).
- **[auto]** No third-party requests on a published page. The map loads only when tapped (`public-site.spec`, CSP).
- **[review]** Photos are WebP at 640 and 1600 px. The first photo loads eagerly; the others load lazily.

## 9. Accessibility

- **[auto]** No serious or critical WCAG 2.2 AA issues on any page of any design. The home page is checked in its own colours and six others, and in the Luxury and Bold styles (`public-templates.spec`, `template-contrast.spec`).
- **[auto]** Keyboard: skip link, visible focus, and the slideshow and carousels reachable by keyboard (`keyboard.spec`, accessibility crawl).

## 10. Launch in under an hour

- **[review]** A new owner can pick a design and style at signup, replace the demo photos, add contact details and prices, review legal pages, and publish in under an hour. The launch checklist leads them through it.
- **[auto]** Switching design or style keeps every word and photo the owner added (`InstallTemplatesTest`, `TemplateSwitchTest`).

## Release checklist (per design)

| Check | How |
|---|---|
| Structure, first screen, copy | Read every page in the template preview, in each style |
| Photos | Admin → Templates → Demo photos: no stand-ins left, credits recorded |
| Phone | Preview at 360 px; the main button is on the first screen |
| Automated gates | CI green: accessibility, contrast, phone width, performance |
