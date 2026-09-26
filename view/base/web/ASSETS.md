# Warranty label assets

Static assets for the EU harmonised legal-guarantee notice and the GARAN
durability label, per Commission Implementing Regulation (EU) 2025/1960.

Downloaded / extracted: **2026-09-15**.

## Sources

- Official notice SVG/PNG/JPG packages and the GARAN label:
  https://commission.europa.eu/publications/practical-guidelines-and-high-resolution-vector-files-eu-notice-and-label-product-guarantees_en
  - `SVG.zip` — 24 languages, colour + `_BW` (black & white, unused here)
  - `PNG and JPG.zip` — 24 languages, colour + `_BW`, PNG and JPG
  - `Harmonised notice in 24 languages colour and black and white_0.zip`
  - `GARAN label for website.zip`
- Font: **Inter 4.1** (Rasmus Andersson / The Inter Project Authors),
  https://rsms.me/inter/ — SIL OFL 1.1.

## License notes

- **EU notice/label files:** the Commission's download page states no
  explicit licence; the files are described as "provided for use by sellers"
  of the harmonised notice/label. We treat them as usable as-is for their
  intended purpose (displaying the mandatory notice/label to consumers) and
  apply the Commission's own guidelines §2.1.4 restriction below. No
  redistribution licence text is bundled with the ZIPs.
- **Inter:** SIL Open Font License 1.1. Full text copied to
  `fonts/LICENSE-Inter.txt` (from `inter/LICENSE.txt` in the official
  release archive).

## Rule — never modify the official artwork

Per the Commission's guidelines §2.1.4: **never recolour, crop, distort, or
add elements to** the notice or the GARAN label. Only the language variant
and (within the allowed layout) the rendered size may be chosen. All files
below are copied byte-for-byte from the official packages; the only
generated exception is `notice/en.png` (see below).

## File list

`notice/` — 24 official colour SVGs (`{lang}.svg`) and 24 colour PNGs
(`{lang}.png`, 1654×2339 px), one language per ISO 639-1 code (lowercase):
bg cs da de el en es et fi fr ga hr hu it lt lv mt nl pl pt ro sk sl sv.
`notice/CHECKSUMS` holds `sha256sum` lines for all 48 files, sorted by
filename.

- 23 of the 24 SVGs and PNGs (all except `en`) are byte-identical copies of
  the official `SVG.zip` / `PNG and JPG.zip` contents — verified with `cmp`.
- `fonts/` — `fonts/ttf/Inter-{Regular,SemiBold,ExtraBold}.ttf` (Inter 4.1,
  byte-identical to `inter/extras/ttf/*`) and `fonts/LICENSE-Inter.txt`.
  The TTFs are used server-side only: `FieldFitChecker` measures the GARAN
  label fields and `GaranPngRenderer` draws brand, model and duration onto the
  label backgrounds. No web font is shipped — the storefront and emails show
  cached PNGs, so browsers never request Inter (and never from Google Fonts).

## Deviation: `notice/en.png` is generated, not official

The official `PNG and JPG.zip` package **does not contain an English colour
PNG or JPG** (every other of the 24 languages has one). `notice/en.png` was
therefore rasterised from the official `notice/en.svg` in this repo.

**Method selection.** No `rsvg-convert` or Inkscape CLI is available on the
build host. Available options were ImageMagick 7.1.2 (`convert`, whose `SVG`
coder itself shells out to `rsvg-convert`, which is absent — so it silently
falls back to its own limited internal MSVG/SVG renderer) and Python
`PyGObject` bindings to the actual **librsvg** engine (`gi.repository.Rsvg`
+ `cairo`), both present via the `python3-gi` / `librsvg2-common` system
packages. To pick the best method, `notice/de.svg` was rasterised with each
candidate at 1654×2339 px and compared against the official
`Legal guarantee_notice_DE.png` with ImageMagick `compare`:

| method | `compare -metric AE -fuzz 5%` | `compare -metric RMSE` |
|---|---|---|
| **librsvg via PyGObject (`Rsvg.Handle` + `cairo.ImageSurface`)** | **169047 px (4.37%)** | **5984.65 (9.13%)** |
| `convert svg -background white -resize 1654x2339! -flatten` | 812035 px (20.99%) | 19285.5 (29.43%) |
| `convert -density 200 svg -resize 1654x2339! -flatten` | 525001 px (13.57%) | 18632.7 (28.43%) |

The librsvg/PyGObject route was ~3-5x closer on both metrics and, on visual
inspection (read back as an image), reproduces text, colours and the QR
code faithfully — the residual ~4-9% difference is anti-aliasing/hinting
noise, not missing content or wrong colours. The two ImageMagick fallbacks
showed visibly heavier edges and colour banding. **`notice/en.png` was
generated with the librsvg/PyGObject method** at exactly 1654×2339 px
(script used: rasterise `en.svg` with `Rsvg.Handle.render_document` into a
white-flattened cairo ARGB32 surface scaled to the target pixel size).

This is a **documented deviation** from "official PNG, byte-identical" for
this one file only; all other 47 notice files are official, unmodified
bytes.

## QR code measurement

The QR code in the notice SVGs is drawn as ~300-560 small vector `<rect>`
"module" elements (except `en.svg`, see anomaly below), in a 595.28×841.89
pt (A4) viewBox. Bounding boxes were extracted directly from the SVG
geometry (not from rasterised pixels — cross-checked against a raster
measurement on the official `de.png`, which agreed to within 0.4
percentage points: 19.09% vector vs 19.17% raster for `de`).

- **`de.svg`**: QR bounding box 113.65 × 113.65 pt → **19.09%** of the
  595.28 pt notice width.
- **QR size is not identical across languages** — it varies with the
  per-language QR payload (module count 302-563 rects). Measured across all
  23 vector-based languages, the QR width fraction ranges from **18.04%
  (`fr`, smallest)** to **20.00% (`pl`, largest)**; `de` (19.09%) and `en`
  (see below) sit in the middle/upper part of that range. All measured QR
  boxes are square (width ≈ height), confirming consistent layout.
- **`en.svg` is structurally different**: it is the only one of the 24
  official language files where the QR is embedded as a low-resolution
  raster `<image>` (a 498×498 px PNG, base64-inlined, ~3.9 KB) inside the
  SVG instead of vector rects. Its rendered box is 120×120 pt → **20.16%**
  of the notice width, i.e. layout position/size is consistent with the
  other languages (within the same range), but visual fidelity at large
  print sizes will be lower for English specifically since it's a small
  raster, not vector. **Flagging this as a genuine anomaly in the official
  EU source package, not something introduced here** — worth knowing if the
  notice is ever printed large or the QR needs to be swapped out
  programmatically for English.

**Minimum notice width for a scannable QR (≥ 2 cm = 75.6 CSS px @ 96 dpi):**

`min_width_px = 75.6 / qr_width_fraction`, rounded up to the next 10 px.

- Using `de.svg` alone: 75.6 / 0.19092 ≈ 396.0 → **400 px**.
- Using the worst case across **all 24 languages** (smallest fraction,
  `fr` at 18.04%): 75.6 / 0.18037 ≈ 419.1 → **420 px**.

**Recommendation: render the notice component at a minimum width of
`420 px`** so the QR code stays scannable (≥ 2 cm) on every language
variant, not just `de`/`en`. If a caller only ever needs a fixed subset of
languages, 400 px is sufficient for `de` but is not safe for `fr`/`bg`/`mt`/
`nl`/`sl` etc., which have a smaller QR fraction than `de`.

## Anything else worth flagging

- No other structural differences were found between the 23 vector-based
  language SVGs beyond the expected per-language QR module count and text
  length/wrapping differences.
- `en.svg`'s embedded raster QR (previous section) is the one outlier worth
  a second look if pixel-perfect large-format printing of the English
  notice is ever required — the official package simply doesn't provide a
  vector QR for English.
