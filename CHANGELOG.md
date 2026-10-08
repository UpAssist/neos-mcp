# Changelog

All notable changes to this project will be documented in this file. See [standard-version](https://github.com/conventional-changelog/standard-version) for commit guidelines.

## [1.1.0](https://github.com/UpAssist/neos-mcp/compare/1.0.3...1.1.0) (2026-10-08)


### Features

* add uploadAsset endpoint for uploading files to the Media Manager (port of [#5](https://github.com/UpAssist/neos-mcp/issues/5) to neos-8) ([d300a5e](https://github.com/UpAssist/neos-mcp/commit/d300a5ef584893a5cffd9d12afe3ad99bfc8107b))


### Bug Fixes

* **listAssets:** apply the tag filter in the query so total, limit and offset match the filtered set ([38e3d66](https://github.com/UpAssist/neos-mcp/commit/38e3d66a67b8701047f1b3757674253a9f9a8a10))

### [1.0.3](https://github.com/UpAssist/neos-mcp/compare/1.0.2...1.0.3) (2026-08-13)


### Bug Fixes

* resolve property values on node create, not just update ([4ac618b](https://github.com/UpAssist/neos-mcp/commit/4ac618bf3bddb7b52081e6b7d5ffb9a99b2e3781))

### [1.0.2](https://github.com/UpAssist/neos-mcp/compare/1.0.1...1.0.2) (2026-07-15)


### Bug Fixes

* guard asset property serialization against non-object values ([c0340b2](https://github.com/UpAssist/neos-mcp/commit/c0340b2f62031c820301ec138540383eac603ee1))

## 1.0.1 (2026-06-04)

### Bug fixes

- Remove MCP controllers from `Neos.Neos:Backend` requestPatterns — the WebRedirect entryPoint was intercepting Bearer token API calls and redirecting to `/neos/login`
- Add `X-MCP-Token` header fallback in `checkAuth()` and `ApiTokenProvider` for servers where nginx/PHP-FPM strips the `Authorization` header before PHP sees it

## 0.2.0 (2026-03-25)

### Features

- add getDocumentProperties endpoint and refactor collectDocumentNodes ([9c36f41](https://github.com/UpAssist/neos-mcp/commit/9c36f417c485d246cedca051081afd6f233fdb56))
- initial Neos MCP bridge package ([ff82834](https://github.com/UpAssist/neos-mcp/commit/ff82834ac93f13bf8d665052b009eea4320b40ef))
