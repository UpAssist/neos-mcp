# Changelog

All notable changes to this project will be documented in this file. See [standard-version](https://github.com/conventional-changelog/standard-version) for commit guidelines.

### [2.0.3](https://github.com/UpAssist/neos-mcp/compare/2.0.2...2.0.3) (2026-04-23)


### Bug Fixes

* render mcp preview for anonymous token holders ([88a2dee](https://github.com/UpAssist/neos-mcp/commit/88a2dee9c428390bfcfb34583c5561c933fc4579)), closes [#1430218623](https://github.com/UpAssist/neos-mcp/issues/1430218623)

## 2.0.2 (2026-04-09)


### Bug Fixes

* remove `neos/content-repository-registry` from composer require — it is bundled with `neos/neos` and not available as a standalone package

## 2.0.1 (2026-04-09)


### Bug Fixes

* expose nested ContentCollections (e.g. Columns → Column) in getPageContent response so their nodeAggregateId is discoverable
* wrap mutation actions (createContentNode, createDocumentNode, moveNode, updateNodeProperty) in try/catch to return structured JSON errors instead of HTML 500 pages

### Documentation

* clarify v1.x (neos-8 branch) vs v2.x (main) version requirements in README

## 0.2.0 (2026-03-25)


### Features

* add getDocumentProperties endpoint and refactor collectDocumentNodes ([9c36f41](https://github.com/UpAssist/neos-mcp/commit/9c36f417c485d246cedca051081afd6f233fdb56))
* initial Neos MCP bridge package ([ff82834](https://github.com/UpAssist/neos-mcp/commit/ff82834ac93f13bf8d665052b009eea4320b40ef))
