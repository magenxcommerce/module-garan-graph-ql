# Magenx_GaranGraphQl

EU **harmonised legal-guarantee notice** and **EU GARAN durability label** for headless Magento storefronts,
served over GraphQL. Both become mandatory on **27 September 2026** under Directive (EU) 2024/825 and
Implementing Regulation (EU) 2025/1960:

1. **Legal guarantee notice** (Annex I): every shop selling goods to EU consumers.
2. **GARAN label** (Annex II): wherever a producer offers a free commercial guarantee of durability covering the
   whole good for more than two years.

Fork of [`CopeX/magento-warranty-label`](https://github.com/CopeX/magento-warranty-label) (MIT, v1.3.1). The
domain logic is kept: product attributes, label validation against the official layout, configurable-parent
inheritance, PNG rendering onto the official artwork, the order-item snapshot and the order-confirmation email
additions. Every Luma/Hyvä output (layouts, templates, JS, checkout config provider) is dropped. The storefront
decides where the notice and labels go; this module only serves the data.

> This module does not constitute legal advice. The upstream legal mapping is in
> [`docs/COMPLIANCE-DE.md`](https://github.com/CopeX/magento-warranty-label/blob/main/docs/COMPLIANCE-DE.md) (German).

## Requirements

- Magento 2.4.8, PHP 8.3+
- **ext-gd with FreeType.** Needed to check that brand and model fit the label and to render the label PNGs.
  Without it no label is served.

## GraphQL surface

The full contract is in [`etc/schema.graphqls`](etc/schema.graphqls).

| Field | Resolver | Notes |
|---|---|---|
| `Query.garanNotice: GaranNotice` | plain | Official notice SVG/PNG media URL (`media/garan/notice/`) of the configured language, Your Europe link, alt text. Null when disabled. |
| `ProductInterface.garan_label: GaranLabel` | **batch** | One configurable-parent query and one query per EAV table for the whole branch. |
| `CartItemInterface.garan_labels: [GaranLabel!]` | **batch** | Covers the item, the selected variant, or every bundle child. |
| `OrderItemInterface.garan_labels: [GaranLabel!]` | plain | Read from the snapshot column loaded with the item, so it costs no query. |
| `Cart.garan_notice_required: Boolean` | plain | True when at least one line is not an excluded product type. |

`GaranLabel.image_url` / `nested_image_url` point at PNGs in `pub/media/magenx_garan/garan/`. They are keyed by
content hash and rendered on first request. Show `alt_text` when a URL is null.

Reads fail soft. Disabled config, invalid product data or an exception all give `null` (or an empty list), and the
error is logged. The field never breaks a product or cart query.

```graphql
{
  garanNotice { svg_url link_url link_label alt_text }
  products(filter: { sku: { eq: "KETTLE-1" } }) {
    items { garan_label { brand model_identifier formatted_duration terms_url image_url nested_image_url alt_text info_url } }
  }
}
```

## Configuration

**Stores → Configuration → Magenx → EU Guarantee Notice & GARAN Label.** Every setting is per store view, and
everything ships switched off.

| Path | Purpose |
|---|---|
| `magenx_garan/general/enabled` | Kill switch for the notice, the labels and the email additions. |
| `magenx_garan/general/language` | Which of the 24 official notice versions to serve. Empty falls back to the store locale, then English. |
| `magenx_garan/general/excluded_product_types` | Product types that are not goods, such as gift cards and downloads. |
| `magenx_garan/garan/enabled` | Turns the GARAN label on. Needs the kill switch on as well. |
| `magenx_garan/garan/brand_source`, `brand_attribute`, `brand_value` | Brand fallback when the product has no `garan_brand`. |
| `magenx_garan/garan/model_source` | Model identifier fallback: the GARAN attribute only, or the product name. |
| `magenx_garan/garan/terms_url` | Default guarantee terms URL. |
| `magenx_garan/email/notice`, `email/garan` | Order-confirmation email: `no`, `inline` or `attachment` (PNG). |
| `magenx_garan/email/attach_terms`, `terms_file`, `terms_filename` | Attaches the producer's terms PDF, which satisfies the durable-medium duty (CJEU C-49/11). |

## Product data

The data patch adds four EAV attributes to every product type, scoped per store view, in the attribute group
**EU GARAN Guarantee**:

| Attribute | Rule |
|---|---|
| `garan_brand` | Checked against the width of the label field. |
| `garan_model_identifier` | Must fit on one line together with the brand. |
| `garan_duration_years` | Whole or half years, more than 2 and at most 99. `4,5` is accepted. |
| `garan_terms_url` | An absolute http(s) URL. |

A label appears only when all four resolve to valid values, whether from the product, its configurable parent or
the config fallbacks. Values that do not fit are rejected when the product is saved, and again when it is read,
because mass updates and imports skip the backend models.

## Install

```bash
composer require magenxcommerce/module-garan-graph-ql
bin/magento module:enable Magenx_GaranGraphQl
bin/magento setup:upgrade && bin/magento setup:di:compile && bin/magento cache:flush
```

To uninstall, run `bin/magento module:uninstall Magenx_GaranGraphQl --remove-data`. The flag is required: without
it the four attributes keep backend models pointing at deleted classes, and every product page then fails.

## Tests

```bash
composer install   # magento/* come from the public Mage-OS mirror declared in composer.json
vendor/bin/phpunit
```

The `repositories` entry only affects installs where this package is the root (local runs and CI, which has
no repo.magento.com key pair). Composer ignores it when the module is installed into a store.

To run the suite inside an existing Magento installation, set `GARAN_AUTOLOAD` to that installation's
`vendor/autoload.php`.

## Assets

The artwork and fonts are **not shipped with the module**. Deploy them once per environment to `pub/media/garan/`:

```
pub/media/garan/
├── garan/    label.svg, label.png, nested.svg, nested.png, label-blank@4x.png, nested-blank@4x.png
├── fonts/    LICENSE-Inter.txt, ttf/Inter-{Regular,SemiBold,ExtraBold}.ttf
└── notice/   {lang}.svg and {lang}.png for the 24 EU languages
```

They are served from the store media URL like any other Magento media, e.g.
`https://shop.example/media/garan/notice/de.svg`, so static content deploys never touch them. The GARAN blanks and
the Inter TTFs are also read server-side for the email PNGs; a missing file makes the affected URL or attachment
`null` and logs an error instead of breaking the request.

The official artwork is byte-identical to the Commission's files and must never be altered. Verify a deploy with
[`docs/notice.CHECKSUMS`](docs/notice.CHECKSUMS):

```bash
cd pub/media/garan/notice && sha256sum -c /path/to/module/docs/notice.CHECKSUMS
```

Sources, licences and the one generated file (`notice/en.png`) are documented in [`docs/ASSETS.md`](docs/ASSETS.md).

## Licence

MIT. Original work © CopeX GmbH, fork © Magenx. See [LICENSE](LICENSE).
