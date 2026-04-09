# Changelog

All notable changes to this project will be documented in this file. See [standard-version](https://github.com/conventional-changelog/standard-version) for commit guidelines.

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
