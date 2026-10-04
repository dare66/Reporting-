# Embedded analytics SDK

> **Status: design only. The embedded SDK is not built.** Nothing in the product offers embedding today; this document is the agreed design.

## Goal (brief §39)

Let an organisation put governed AIXBI content (a dashboard, a chart, a KPI, a report or the AI analyst) inside its own applications. The data shown must still follow that viewer's tenant, row-level security and column rules.

## Design

**Embed tokens.** The host application's server calls `POST /api/v1/embed/tokens` with an API key (service account, not yet built) and receives a short-lived signed token. The token names:

- the content allowed (`dashboard:{id}`, `metric:{ref}`, …);
- the viewer's identity and row-level attributes (e.g. `country_codes`);
- an expiry (default 10 minutes, refreshable).

The token never grants more than the service account itself holds.

**Delivery, in order:**
1. **iframe**: `https://aixbi.example/embed/dashboard/{id}?token=…`. A chrome-less route of the existing Angular app. It works everywhere, with white-label theme tokens passed in the token.
2. **Web component** `<aixbi-dashboard token="…">`. Built from the same Angular components with Angular Elements, so it works in React, Vue and plain HTML without separate SDKs per framework.
3. **REST** for data-only use: the existing `/query` and `/kpis` endpoints, authorised by the embed token.

**Security rules:**
- Embed tokens are verified by the same middleware as user sessions. Tenancy and row-level security apply unchanged.
- Allowed parent origins are configured per organisation and enforced with `Content-Security-Policy: frame-ancestors` on embed routes only. Everything else keeps `X-Frame-Options: DENY`.
- Every embedded view is audited with the service account and the viewer identity from the token.

## Prerequisites

1. API keys and service accounts (identity, brief §46).
2. A per-organisation theme (also needed for EMGS branding).
3. An `embed` permission and an allowed-origins setting in Administration.
