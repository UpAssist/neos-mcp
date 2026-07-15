# Changelog

All notable changes to this project will be documented in this file. See [standard-version](https://github.com/conventional-changelog/standard-version) for commit guidelines.

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
