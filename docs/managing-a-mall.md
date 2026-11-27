<a id="top"></a>
# Managing an OXID EE mall (several shops on several domains)

One OXID eShop Enterprise installation, one database, one admin — several shops, each on its own domain with its own
theme, catalogue and start page, sharing payment, modules, shipping and (optionally) customers.

This guide explains how EE decides which shop answers a request, what is stored per shop and what is shared, how
`oe:demodata-mall:create-mall` sets a mall up, and how to find and fix the gaps that stop a sub-shop from selling.

<a id="contents"></a>
## Contents

| Anchor | Section |
|---|---|
| [`#model`](#model) | The mall model: one installation, many shops |
| [`#per-shop-vs-shared`](#per-shop-vs-shared) | What is per shop and what is shared |
| [`#domain-routing`](#domain-routing) | How a domain becomes a shop (`sMallShopURL`, `iMallMode`) |
| [`#create-mall`](#create-mall) | Creating a mall with `oe:demodata-mall:create-mall` |
| [`#create-mall-options`](#create-mall-options) | Command options |
| [`#create-mall-json`](#create-mall-json) | JSON mall file |
| [`#create-mall-steps`](#create-mall-steps) | What the command does per shop |
| [`#admin`](#admin) | Working with the admin (shop selector, rights) |
| [`#themes`](#themes) | Themes per shop and child themes |
| [`#themes-child-module-hooks`](#themes-child-module-hooks) | Module template hooks under child themes |
| [`#modules`](#modules) | Modules and module settings per shop |
| [`#shipping`](#shipping) | Shipping methods and cost rules |
| [`#payment`](#payment) | Payment methods and providers |
| [`#customers`](#customers) | Customers: per shop or shared (`blMallUsers`) |
| [`#one-page-checkout`](#one-page-checkout) | One-page checkout in a mall |
| [`#content`](#content) | Catalogue and CMS per shop |
| [`#dns-tls`](#dns-tls) | Domains, DNS, TLS, local-only domains |
| [`#verify`](#verify) | Checklist: is every shop able to sell? |
| [`#troubleshooting`](#troubleshooting) | Symptom → cause → fix |
| [`#safety`](#safety) | Safety rules (backups, replace mode, DB copies) |

<a id="model"></a>
## The mall model: one installation, many shops

- Shop `1` is the **base shop** (default shop). Every other shop is a **sub-shop** with `oxshops.OXPARENTID = 1`.
- All shops share the code, the database, the admin (`/admin/`) and the installed modules.
- A sub-shop is **inherited** (`OXISINHERITED = 1`: sees the parent's catalogue) or **not inherited** (own catalogue).
  `create-mall` and `install --mode=subshop` create **not inherited** shops so every vertical has only its own
  products. Inheritance can only be chosen when a shop is created.
- Shop data lives in the same tables; EE assigns rows to shops through `*2shop` mapping tables
  (`oxarticles2shop`, `oxcategories2shop`, `oxdeliveryset2shop`, …) and reads them through per-shop views
  (`oxv_oxarticles_<shop>_<lang>`).

[↑ top](#top)

<a id="per-shop-vs-shared"></a>
## What is per shop and what is shared

| Area | Per shop | Shared | Where | Section |
|---|---|---|---|---|
| Domain / URL | ✔ | | `oxconfig` `sMallShopURL`, `sMallSSLShopURL` | [#domain-routing](#domain-routing) |
| Theme | ✔ | | `oxconfig` `sTheme`, `sCustomTheme`, `theme:<id>` settings | [#themes](#themes) |
| Catalogue (products, categories, manufacturers) | ✔ (not inherited) | | `*2shop` tables | [#content](#content) |
| CMS pages, start page snippets | ✔ (copied at creation) | | `oxcontents.OXSHOPID` | [#content](#content) |
| Shop config (`oxconfig`) | ✔ (copied at creation) | | `oxconfig.OXSHOPID` | — |
| Module activation | ✔ | code | `var/configuration/shops/<id>/modules/*.yaml` | [#modules](#modules) |
| Module settings (API keys, options) | ✔ | | same YAML files | [#modules](#modules) |
| Shipping methods, cost rules | ✔ (assignment) | definitions | `oxdeliveryset2shop`, `oxdelivery2shop`, `oxobject2delivery` | [#shipping](#shipping) |
| Payment methods | | ✔ | `oxpayments`, `oxobject2payment` | [#payment](#payment) |
| Customers | ✔ by default | ✔ with `blMallUsers` | `oxuser.OXSHOPID` | [#customers](#customers) |
| Orders | ✔ | | `oxorder.OXSHOPID` | — |
| Admin users | `malladmin` sees all shops | | `oxuser.OXRIGHTS` | [#admin](#admin) |

[↑ top](#top)

<a id="domain-routing"></a>
## How a domain becomes a shop

EE picks the shop from the request host: it compares the current URL with every shop's `sMallShopURL` /
`sMallSSLShopURL`. A match selects that shop; no match → the base shop (`sShopURL` in `config.inc.php`).

- Set the URL per shop (`create-mall` does it, or *Master Settings → Core Settings → Mall* in the admin).
- The web server must pass the **original host** to PHP. A reverse proxy that rewrites `Host` to the base domain
  makes every domain show shop 1.
- <a id="mall-mode"></a>**`iMallMode`** decides what the base URL shows when several shops are active and the shop has
  no own URL: `1` = a shop chooser ("mallstart"), `0` = the base shop's start page. `create-mall` sets `0` on all
  shops (`--mall-mode`).
- Shops without an own URL are reached with `?shp=<id>` (and EE adds `shp=` to their links).

[↑ top](#top)

<a id="create-mall"></a>
## Creating a mall with `oe:demodata-mall:create-mall`

```bash
# every vertical as shop1.example.com, shop2.example.com, …
vendor/bin/oe-console oe:demodata-mall:create-mall --domain=example.com --shared-customers

# chosen hosts and themes
vendor/bin/oe-console oe:demodata-mall:create-mall --domain=example.com --shared-customers \
  --shop=fashion:apparel:apex-child-3 \
  --shop=electronics:electronics:apex-child-2 \
  --shop=medical-devices:med:apex-child-1

# from a file, plan only
vendor/bin/oe-console oe:demodata-mall:create-mall --config=mall.json --dry-run
```

Run it in the base shop (no `--shop-id`, or `--shop-id=1`). It is **re-runnable**: a sub-shop is found by its URL and
brought up to date instead of being created twice. Start with `--dry-run`: it prints which shops exist and which
would be created.

<a id="create-mall-options"></a>
### Options

| Option | Default | Meaning |
|---|---|---|
| `--domain=<domain>` | — | Base domain for hosts without a dot |
| `--shop=<vertical>[:<host>[:<theme>]]` | every vertical | One sub-shop, repeatable. `host` without a dot → `<host>.<domain>`; with a dot → used as is |
| `--host-pattern=<pattern>` | `shop{n}` | Host for a `--shop` without host; `{n}` = position (1, 2, …), `{vertical}` = vertical id |
| `--scheme=<scheme>` | `https` | URL scheme |
| `--config=<file>` | — | JSON mall file ([#create-mall-json](#create-mall-json)) instead of the options above |
| `--delivery-rules=<titles>` | `Standard,Express` | Cost rules linked to each sub-shop's categories ([#shipping](#shipping)) |
| `--shared-customers` | off | `blMallUsers = true` ([#customers](#customers)) |
| `--mall-mode=<0\|1>` | `0` | `iMallMode` on all mall shops ([#mall-mode](#mall-mode)) |
| `--skip-module=<id>` | — | Module not activated (and settings not copied) in sub-shops, repeatable — e.g. test fixtures |
| `--sync-module-settings` | off | Also copy the base shop's module settings into sub-shops that already exist |
| `--no-content` / `--no-cms` / `--no-pictures` | off | Skip catalogue+CMS / only CMS / pictures |
| `--dry-run` | off | Show the plan only |

<a id="create-mall-json"></a>
### JSON mall file

```json
{
  "domain": "example.com",
  "scheme": "https",
  "hostPattern": "shop{n}",
  "sharedCustomers": true,
  "mallMode": 0,
  "deliveryRules": ["Standard", "Express"],
  "shops": [
    { "vertical": "fashion", "host": "apparel", "theme": "apex-child-3", "name": "Apparel" },
    { "vertical": "electronics", "host": "electronics", "theme": "apex-child-2" },
    { "vertical": "medical-devices", "host": "med.example.org", "theme": "apex-child-1", "name": "Medical Store" }
  ]
}
```

`name` defaults to the vertical's shop name; `host` defaults to `hostPattern`; `theme` empty = keep the copied theme.

<a id="create-mall-steps"></a>
### What the command does per shop

1. **Find or create** the sub-shop by URL — creation runs the admin's *New shop* logic (copies shop 1's fields,
   config, CMS pages, static SEO URLs, module configuration, views) and sets `sMallShopURL`/`sMallSSLShopURL`.
2. **Module settings** — new shops get shop 1's module settings (per-shop secrets such as GraphQL's
   `sJsonWebTokenSignature` excluded) ([#modules](#modules)).
3. **Modules** — activates every module active in shop 1 (two passes, for dependencies), except `--skip-module`.
4. **Content** — `oe:demodata-mall:install <vertical> --mode=add --cms --shop-id=<id>` (catalogue, pictures, CMS).
5. **Shipping** — assigns shop 1's shipping methods and cost rules, links the parcel rules to the shop's categories
   ([#shipping](#shipping)).
6. **Theme** — `oe:theme:activate <theme> --shop-id=<id>` when a theme is given.

Then mall-wide: `iMallMode` on all mall shops, `blMallUsers` with `--shared-customers`, cache clear.

[↑ top](#top)

<a id="admin"></a>
## Working with the admin

- One admin for all shops: `https://<base domain>/admin/`.
- The **shop selector** at the top of the left navigation decides which shop you edit — products, categories,
  CMS pages, settings, orders. With the base shop selected you only see the base shop's products; switch to the
  sub-shop to see its catalogue. The selector exists when more than one shop is active.
- Only **mall administrators** (`oxuser.OXRIGHTS = 'malladmin'`) see all shops; a shop admin sees only their shop.
- After creating shops log out and in again (the navigation is built at login).
- A product can be added to further shops in its **Mall** tab.

[↑ top](#top)

<a id="themes"></a>
## Themes per shop and child themes

- Each shop has its own theme: `bin/oe-console oe:theme:activate <theme-id> --shop-id=<id>`
  (without `--shop-id` it changes **shop 1**).
- A **child theme** (`theme.php` with `parentTheme`) stores `sTheme = <parent>` and `sCustomTheme = <child>`; its
  `tpl/` and `out/` are searched before the parent's.
- Theme settings are per shop and per theme (`oxconfig.OXMODULE = 'theme:<id>'`).

<a id="themes-child-module-hooks"></a>
### Module template hooks under child themes

OXID 7's Twig component loads a module's template extensions only for the **active** theme id
(`views/twig/extensions/themes/<active id>/` and `default`). Under a child theme the active id is the child's, so a
module that ships `themes/apex/` hooks (e.g. the one-page checkout) silently loses them: no "Buy now" modal,
checkout falls back to the home page.

Fix (project level): register a parent-theme-aware `ShopTemplateChainAppender` (active → parent → `default`) in
`var/configuration/configurable_services.yaml`. A ready implementation is
`OxidEsales\ApexChildThemes\Twig\ParentThemeAwareShopTemplateChainAppender` in
[dantweb/oxid-apex-children](https://github.com/dantweb/oxid-apex-children) (see its README, section
*Modules and child themes*).

[↑ top](#top)

<a id="modules"></a>
## Modules and module settings per shop

- Activation is per shop: `bin/oe-console oe:module:activate <id> --shop-id=<id>`.
- Settings are per shop, in `var/configuration/shops/<id>/modules/<module>.yaml`. A new shop gets the defaults from
  each module's `metadata.php` — **no payment keys**. `create-mall` copies shop 1's settings into new shops.
- Changing a setting in the admin changes it **only for the selected shop**. To push shop 1's settings to all
  sub-shops again: `create-mall … --sync-module-settings --no-content` (same hosts as before).
- Activation order matters (dependencies such as `oe_graphql_base` → `oe_payment_base` → payment modules → OPC).
- Compare a module between shops:
  `diff <(grep -v ^activated var/configuration/shops/1/modules/<m>.yaml) <(grep -v ^activated var/configuration/shops/<id>/modules/<m>.yaml)`

[↑ top](#top)

<a id="shipping"></a>
## Shipping methods and cost rules

Two things must be true for a basket of a sub-shop:

1. <a id="shipping-assignment"></a>**The shipping method and its cost rules are assigned to the shop**
   (`oxdeliveryset2shop`, `oxdelivery2shop`). A non-inherited shop starts with none → *"No shipping methods found"*.
2. <a id="shipping-rule-match"></a>**A cost rule matches the basket.** A rule restricted to categories (or articles)
   only applies to products of those categories (`oxobject2delivery`, `OXTYPE = 'oxcategories'`). Rules of the base
   shop know only the base shop's categories → OPC shows *"No shipping methods available"*.

`create-mall` assigns shop 1's methods and rules and links the rules named in `--delivery-rules` (default: the EE
demo data's parcel rules `Standard`, `Express`) to every category of the sub-shop. Rules without category restriction
need no link. In the admin: *Shop Settings → Shipping Cost Rules → <rule> → Categories*.

Check in the database (shop `<id>`, language `de`):

```sql
SELECT OXTITLE FROM oxv_oxdeliveryset_<id>_de WHERE OXACTIVE = 1;      -- methods visible in the shop
SELECT d.OXTITLE, COUNT(*) FROM oxobject2delivery o JOIN oxdelivery d ON d.OXID = o.OXDELIVERYID
  JOIN oxcategories c ON c.OXID = o.OXOBJECTID AND c.OXSHOPID = <id> GROUP BY d.OXTITLE;  -- rules ↔ categories
```

[↑ top](#top)

<a id="payment"></a>
## Payment methods and providers

- Payment methods (`oxpayments`) and their links to shipping methods are shared by all shops.
- Payment **modules** must be active in each shop with valid settings ([#modules](#modules)).
- Provider-side setup is per domain:
  - **Stripe**: webhook per shop (`https://<domain>/index.php?cl=StripeWebhookController&shp=<id>`, "Create webhooks"
    once per shop); **Apple Pay** needs every domain registered and verified in the Stripe dashboard.
  - Other providers: register return/notification URLs for every domain if the provider requires it.

[↑ top](#top)

<a id="customers"></a>
## Customers: per shop or shared

- By default a customer belongs to one shop (`oxuser.OXSHOPID`) and can log in only there.
- `blMallUsers = true` (*customers shared by all shops*, `create-mall --shared-customers`) lets every customer log in
  on every shop; e-mail addresses are then unique across the mall. This setting is read **only from shop 1**.
- Code that looks customers up must honour it: use `User::getIdByUserName()` (EE searches all shops when
  `blMallUsers` is on) — never `WHERE OXUSERNAME = ? AND OXSHOPID = <current shop>`.

[↑ top](#top)

<a id="one-page-checkout"></a>
## One-page checkout in a mall

- OPC replaces the classic steps: `index.php?cl=user` / `cl=payment` show the home page in **every** shop; checkout
  happens in the OPC modal ("Buy now" / cart checkout button).
- Needs: OPC active per shop ([#modules](#modules)), its theme hooks ([#themes-child-module-hooks](#themes-child-module-hooks)),
  shipping ([#shipping](#shipping)).
- Shared customers: OPC versions before the fix on `b-7.4.x-multishop-demo-data-mall` (one-page-checkout) looked
  customers up in the current shop only → *"Ungültige E-Mail oder Passwort"* / `invalid_credentials` on sub-shops,
  and the failures trip OPC's e-mail lockout. Unlock a locked customer with
  `EmailLockoutService::unlock('<email>')`; OPC's login-attempt window also compares PHP local time with MySQL
  `NOW()`, so with a time-zone difference a lockout can last longer than configured.

[↑ top](#top)

<a id="content"></a>
## Catalogue and CMS per shop

- `oe:demodata-mall:install <vertical> --mode=add --cms --shop-id=<id>` installs a vertical's catalogue, pictures
  and start page CMS into a shop; re-runnable (stable ids).
- CMS pages are copied from shop 1 when a shop is created; `--cms` replaces the start page snippets
  (`oxstartslot1-4`, USPs, meta, footer) and deactivates the EE demo data's car landing pages in that shop.
- Pictures are shared files (`source/out/pictures`) — see [#safety](#safety).

[↑ top](#top)

<a id="dns-tls"></a>
## Domains, DNS, TLS, local-only domains

- **Public**: each domain needs DNS to the server (or a tunnel ingress) and a certificate. Sub-sub-domains such as
  `shop1.dev.example.com` are **not** covered by a `*.example.com` certificate (Cloudflare Universal SSL included) —
  use one-level names (`shop1-dev.example.com`) or an extra certificate.
- **Proxy / tunnel**: keep the original `Host` header for mall domains ([#domain-routing](#domain-routing)).
- **Local only**: map the hosts in `/etc/hosts` to `127.0.0.1`, serve them from a vhost with a certificate from a local
  CA and import the CA into the browser. If the parent domain sends HSTS with `includeSubDomains`, an untrusted
  certificate cannot be clicked through.
- **CDN caches** (`/out/` assets) delay theme changes — purge after rebuilding CSS.

[↑ top](#top)

<a id="verify"></a>
## Checklist: is every shop able to sell?

| # | Check | How | Expected |
|---|---|---|---|
| 1 | Domain resolves to the shop | `curl -s -H "Host: <domain>" http://localhost/ \| grep -o '<body class="[^ "]*'` | `cl-start`, shop's own title (not `cl-mallstart`) |
| 2 | Theme | `oxconfig` `sTheme`/`sCustomTheme` for the shop | intended theme |
| 3 | Modules | `grep -l '^activated: true' var/configuration/shops/<id>/modules/*.yaml \| wc -l` | as shop 1 (minus skipped) |
| 4 | Module settings | `diff` per module ([#modules](#modules)) | only per-shop secrets differ |
| 5 | Shipping methods | `oxv_oxdeliveryset_<id>_de` | ≥ 1 active |
| 6 | Cost rules match | `oxobject2delivery` for the shop's categories ([#shipping](#shipping)) | every category covered |
| 7 | Child theme hooks | `configurable_services.yaml` override ([#themes-child-module-hooks](#themes-child-module-hooks)) | present |
| 8 | Customers | `blMallUsers` on shop 1 if customers are shared | as intended |
| 9 | Payment provider per domain | webhooks / domain registration ([#payment](#payment)) | done |
| 10 | End to end | log in in the checkout, buy with a test payment | thank-you page, order in the right shop |

[↑ top](#top)

<a id="troubleshooting"></a>
## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| <a id="ts-no-shipping"></a>"No shipping methods found" | shipping not assigned to the shop | [#shipping-assignment](#shipping-assignment), `create-mall` |
| <a id="ts-opc-no-shipping"></a>OPC "No shipping methods available" | no cost rule matches the shop's categories | [#shipping-rule-match](#shipping-rule-match) |
| <a id="ts-buy-now-nothing"></a>"Buy now" does nothing, no OPC modal | OPC inactive in the shop, or child theme without module hooks | [#modules](#modules), [#themes-child-module-hooks](#themes-child-module-hooks) |
| <a id="ts-payment-home"></a>`cl=payment` shows the home page | normal with OPC; or empty basket / no session | use the OPC modal; check the cart |
| <a id="ts-invalid-credentials"></a>Login works on shop 1 only / "invalid credentials" in OPC | customers per shop, or OPC without the shared-customer fix, or e-mail lockout | [#customers](#customers), [#one-page-checkout](#one-page-checkout) |
| <a id="ts-mallstart"></a>Base domain shows a shop chooser | `iMallMode = 1` | [#mall-mode](#mall-mode) |
| <a id="ts-all-shop1"></a>Every domain shows shop 1 | missing `sMallShopURL`, or proxy rewrites `Host` | [#domain-routing](#domain-routing) |
| <a id="ts-admin-products"></a>Admin does not list a shop's products | wrong shop selected in the admin | [#admin](#admin) |
| <a id="ts-payment-keys"></a>Payment fails only in sub-shops | module settings (keys) not copied | [#modules](#modules), `--sync-module-settings` |
| <a id="ts-no-pictures"></a>"No picture" boxes in the base shop | master pictures deleted by a replace install sharing the file system | [#safety](#safety); restore pictures |

[↑ top](#top)

<a id="safety"></a>
## Safety rules

- **Back up the database** before creating shops or installing content (`mysqldump --single-transaction`).
- **`--mode=replace` deletes products through the shop models — including their master pictures in the shared
  `source/out/pictures`.** Never run it against a *copy* of the database through the same installation
  (e.g. `OXID_DB_NAME=<copy>`): it deletes the real shop's pictures.
- Theme activation without `--shop-id` changes shop 1.
- `create-mall --dry-run` first; the command never deletes shops or data.

[↑ top](#top)
