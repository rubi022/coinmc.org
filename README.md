# coinmc.org

A cryptocurrency market data platform for tracking coin prices, market caps, trading volume and market trends — built in plain PHP + MySQL, modeled on CoinMarketCap / CoinGecko.

## Quick start (macOS, local)

```bash
# 1. PHP 8.1+ and MySQL (skip if already installed)
brew install php mysql && brew services start mysql

# 2. Create the database `coinmc` (user root, no password — edit app/config.php to change)
php bin/install.php

# 3. Pull live data (coins, exchange tickers, history). First run takes ~5–10 minutes.
php cron/run.php all

# 4. Run the site
php -S localhost:8000 -t public public/router.php
# open http://localhost:8000
```

No network yet? `php bin/seed_demo.php` fills the DB with offline demo data so you can preview the UI. The live sync overwrites it.

### Keep data fresh (cron)

```bash
crontab -e
* * * * * cd /path/to/coinmc && php cron/run.php schedule >> storage/logs/cron.log 2>&1
```

The scheduler runs each task when it's due:

| Task | Every | Source | What it does |
|---|---|---|---|
| `tickers` | 2 min | Binance, Coinbase, Kraken, OKX, Bybit, KuCoin | All spot pairs (1 request per exchange) → `markets` table → **live VWAP price** for every coin |
| `markets` | 5 min | CoinGecko | Top 1,000 coins: names, logos, supply, rank, 1h/24h/7d/30d/1y %, 7d sparkline |
| `history` | 5 min (6 coins/run) | Binance klines (2017→), Coinbase candles (2015→), CoinGecko 365d | Daily OHLCV + market-cap history |
| `hourly` | 60 min | Binance klines | Hourly prices (top 200) for 1D/7D/1M charts |
| `meta` | 10 min (8 coins/run) | CoinGecko | Descriptions, links, categories |
| `global`, `trending`, `categories`, `exchanges`, `fiat` | 10–360 min | CoinGecko | Global stats, trending, sectors, exchange directory, fiat rates |

Run a single task any time: `php cron/run.php tickers`. Logs: `storage/logs/sync.log`, `storage/logs/http.log`.

**Rate limits:** CoinGecko works without a key but is slow (~10 req/min). A free **Demo key** (coingecko.com/en/api) triples throughput — put it in `app/config.local.php`:

```php
<?php return ['coingecko_key' => 'CG-xxxx', 'site_url' => 'https://coinmc.org'];
```

**US servers:** `api.binance.com` blocks US IPs, so the sync uses Binance's public market-data mirror `data-api.binance.vision` first, then falls back to `api.binance.com` / `api.binance.us`.

## Pages & URL structure (all server-rendered, crawlable)

| URL | Page | Target keywords |
|---|---|---|
| `/` , `/?page=N` | Coin rankings (100/page) | "crypto prices", "cryptocurrency market cap" |
| `/currencies/{coin}/` | Coin page: price, chart, markets, stats, FAQ, about | "bitcoin price", "btc price today" |
| `/currencies/{coin}/historical-data/` | Last 365 days OHLCV + CSV | "bitcoin historical data" |
| `/currencies/{coin}/historical-data/{year}/` | One page per year | "bitcoin price history 2021" |
| `/convert/{from}/{to}/` | ~8,000 converter pages (coin→fiat, fiat→coin, coin→coin) | "btc to usd", "eth to inr", "1 bitcoin in rupees" |
| `/categories/`, `/categories/{slug}/` | Sectors (DeFi, AI, Meme, L1…) | "best ai crypto coins", "meme coins" |
| `/exchanges/`, `/exchanges/{slug}/` | Exchange directory + live pairs | "binance volume" |
| `/gainers-losers/`, `/trending/`, `/new/` | Movers | "top crypto gainers today", "new crypto coins" |
| `/charts/` | Global market cap & BTC dominance | "total crypto market cap" |
| `/sitemap.xml` | Sitemap index → coins, history, convert, categories, exchanges | |

## SEO built in

- Unique, data-driven `<title>` and meta description on every page (live price, volume, rank baked in — the CoinMarketCap pattern).
- Canonical URLs, trailing-slash + lowercase 301 normalisation, `rel=prev/next`, `noindex` on search/watchlist/empty pages, proper 404s.
- JSON-LD: `Organization` + `WebSite` with `SearchAction` (sitelinks search box), `BreadcrumbList`, `FAQPage` (coin & converter pages), `ItemList`, `Dataset` (historical-data pages — eligible for Google Dataset Search), `ExchangeRateSpecification`.
- Programmatic long-tail pages: coin × year history pages, coin × fiat converter pages.
- Unique prose on every coin page generated from live numbers + project description + FAQ; dense internal linking (related coins, categories, conversions, footer link hubs).
- Open Graph / Twitter cards, auto-split XML sitemaps with `lastmod`, `robots.txt`.
- Core Web Vitals: zero-JS SVG sparklines, self-hosted 160 KB chart lib loaded only on chart pages, system fonts, 60-second full-page HTML cache (`storage/cache`), lazy-loaded logos with fixed dimensions.

## Production

- Point the web root at `public/`. Apache uses `public/.htaccess`. Nginx:

```nginx
server {
  server_name coinmc.org;
  root /var/www/coinmc/public;
  index index.php;
  gzip on; gzip_types text/css application/javascript application/json application/xml image/svg+xml text/csv;
  location /assets/ { expires 30d; access_log off; }
  location / { try_files $uri /index.php?$query_string; }
  location ~ \.php$ { include fastcgi_params; fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; fastcgi_pass unix:/run/php/php-fpm.sock; }
}
```

- Set `site_url` in `app/config.local.php`, make `storage/` writable, add the cron line, then submit `https://coinmc.org/sitemap.xml` in Google Search Console and Bing Webmaster Tools.
- Put Cloudflare in front (cache HTML for 60s) to handle traffic spikes.

## Project layout

```
app/
  config.php            settings (override in config.local.php, git-ignored)
  routes.php            router + controllers + JSON API + sitemaps
  lib/                  db, helpers (formatting, sparklines), seo (JSON-LD), repo (queries), http client
  sources/              CoinGecko.php, Exchanges.php (Binance/Coinbase/Kraken/OKX/Bybit/KuCoin)
  views/                layout + page templates
cron/run.php, tasks.php data sync
bin/install.php         create DB + schema
bin/seed_demo.php       offline demo data
database/schema.sql
public/                 web root (index.php, router.php, assets)
```

## Next SEO growth steps

1. **Localised versions** (`/es/`, `/pt-br/`, `/tr/`, `/hi/`, `/id/` …) with `hreflang` — CoinMarketCap/CoinGecko get a large share of traffic this way.
2. Price prediction / "how to buy {coin}" / "is {coin} a good investment" content per top-200 coin, written with real data.
3. Learn/glossary hub (`/learn/what-is-market-cap/` …) for topical authority, linking into coin pages.
4. Expand coin coverage beyond 1,000 (`max_coins`) once a CoinGecko key is in place.
5. Embeddable price widgets for other sites → backlinks.

---

## Admin panel & accounts

**Setup**
```
php bin/install.php                         # creates/migrates all tables (idempotent)
php bin/create_admin.php you@example.com    # prompts for a password; creates a super_admin
```
Open `/admin/`. Staff must enable 2FA (setting *Require 2FA for staff*, on by default): sign in, go to `/account/security/`, enter the setup key in an authenticator app, confirm a code, and save the recovery codes.

**Cron** (every minute): `php cron/run.php` — runs due tasks (market sync, overrides, analytics rollup, alerts, GSC/GA4, weekly SEO audit, account purge, digest).

**Modules**: Dashboard · Analytics (own cookie-less tracking, CWV field data) · Crypto (coins, exchanges, verified/risk flags, manual listings) · Market data ops · SEO (metadata, redirects, robots.txt, 404 monitor, audit, Search Console) · Advertising (approval workflow, labeled "Sponsored" slots) · Affiliates · Finance ledger · Content (CMS) · Users & roles · Alerts · Reports · Security/Audit/Settings.

**Roles**: super_admin, seo_manager, content_editor, ads_manager, data_ops, finance_manager, moderator, user.

**Google integrations** (Settings): paste a service-account JSON (stored AES-256-GCM encrypted; key in `storage/app.key`, back it up, never commit it), add the account's email as a user on the Search Console property / GA4 property, then set *gsc_site* (e.g. `sc-domain:coinmc.org`) and *ga4_property*.

**Advertising rules built in**: every paid placement shows a non-removable "Sponsored" label with a disclosure link; campaigns need a verified advertiser, a policy confirmation and a second approver (when more than one approver exists); editing an approved creative/URL sends it back to review; global kill switch in Settings.

**Security notes**: CSRF on all POSTs, login throttling, optional admin IP allow-list, audit log with secret masking, sessions invalidated on password change/suspension.

**Not included yet**: competitor intelligence, email marketing, support tickets, API-key billing, backlink monitoring (needs third-party data).
