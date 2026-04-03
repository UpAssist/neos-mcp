# UpAssist.Neos.Mcp

**Let AI assistants manage your Neos CMS content** — safely, with human review before anything goes live.

This package adds an HTTP bridge to your Neos CMS installation that AI tools like Claude Code and Cursor can connect to via the [Model Context Protocol (MCP)](https://modelcontextprotocol.io). The AI can read pages, create and edit content, manage assets, and generate preview links. All changes are staged in a separate workspace so an editor can review before publishing.

## Compatibility

| Package version | Neos CMS | PHP   | MCP Server                |
|----------------|----------|-------|---------------------------|
| 2.x (`main`)   | 9.0+     | 8.2+  | @upassist/neos-mcp 2.x   |
| 1.x (`neos-8`) | 8.3+     | 8.1+  | @upassist/neos-mcp 1.x   |

> **Neos 8 users:** Switch to the `neos-8` branch: `composer require upassist/neos-mcp:^1.0`

## How it works

```
AI Assistant (Claude Code, Cursor, etc.)
    │
    │  MCP protocol (stdio)
    │
neos-mcp-client              ← translates MCP ↔ HTTP
    │
    │  HTTP + Bearer token
    │
UpAssist.Neos.Mcp bridge     ← this package (runs inside Neos)
    │
    │  ContentRepository API
    │
Neos CMS (mcp workspace → live)
```

There are two components to install:

1. **This package** (`upassist/neos-mcp`) — a Neos Flow package that exposes content operations as a REST API
2. **The MCP server** ([`@upassist/neos-mcp`](https://github.com/UpAssist/neos-mcp-client)) — a lightweight Node.js process that translates between MCP protocol and the REST API

## Quick start

### 1. Install the Neos package

```bash
composer require upassist/neos-mcp
```

### 2. Generate a shared token

The bridge uses a Bearer token to authenticate requests. Generate a secure token and add it to your Neos `.env` file:

```bash
# Generate a random token
openssl rand -hex 32
```

```env
# .env
NEOS_MCP_BRIDGE_TOKEN=paste-your-generated-token-here
```

### 3. Install the MCP server

The MCP server is a small Node.js application that runs locally on the developer's machine. See the [neos-mcp-client README](https://github.com/UpAssist/neos-mcp-client) for detailed installation and configuration instructions per editor.

**Quick version for Claude Code:**

```bash
npm install -g @upassist/neos-mcp
```

Add a `.mcp.json` to your Neos project root:

```json
{
  "mcpServers": {
    "neos-local": {
      "command": "neos-mcp",
      "env": {
        "NEOS_MCP_URL": "http://localhost:8081",
        "NEOS_MCP_TOKEN": "paste-your-generated-token-here"
      }
    }
  }
}
```

> **Important:** Add `.mcp.json` to `.gitignore` — it contains your token.

### 4. Try it out

Start your Neos instance, open Claude Code (or Cursor), and ask:

> "Show me the pages on my Neos site"

The AI will call `neos_get_site_context` and show you the site structure. From there you can ask it to edit content, create pages, upload images, and more — all staged for your review.

## Requirements

- Neos CMS 8.3+
- PHP 8.1+
- Node.js 18+ (for the MCP server)

## Configuration

The default settings work out of the box. The only required configuration is the API token in your `.env` file (see step 2 above).

### Workspace

All content changes are written to a dedicated **mcp** workspace (branched from `live`). This workspace is created automatically on first use. Changes remain there until explicitly published — the AI never auto-publishes to live.

### Authentication

Every request to the bridge must include a Bearer token:

```
Authorization: Bearer <your-token>
```

By default, authenticated requests get **Neos.Neos:Administrator** privileges. To restrict this to Editor privileges, add to your site's `Settings.yaml`:

```yaml
Neos:
  Flow:
    security:
      authentication:
        providers:
          'UpAssist.Neos.Mcp:ApiToken':
            providerOptions:
              authenticateRoles:
                - 'Neos.Neos:Editor'
```

### Full settings reference

```yaml
UpAssist:
  Neos:
    Mcp:
      apiToken: '%env:NEOS_MCP_BRIDGE_TOKEN%'
      mcpWorkspaceName: 'mcp'
      mcpWorkspaceTitle: 'MCP Review'
      mcpWorkspaceDescription: 'Shared workspace for AI-assisted content changes, pending human review'
```

## What the AI can do

Once connected, the AI assistant has access to these capabilities:

### Read content

| Tool | Description |
|------|-------------|
| `neos_get_site_context` | Get site structure, all node types with properties, and page tree |
| `neos_list_pages` | List all pages in a workspace |
| `neos_get_page_content` | Get all content nodes on a specific page |
| `neos_get_document_properties` | Get page-level properties (title, SEO fields, etc.) |
| `neos_list_node_types` | List available node types and their properties |

### Create and edit content

| Tool | Description |
|------|-------------|
| `neos_update_node_property` | Update a property on an existing node (text, images, metadata) |
| `neos_create_content_node` | Create a new content element inside a page |
| `neos_create_document_node` | Create a new page |
| `neos_move_node` | Move a node to a new position |
| `neos_delete_node` | Remove a node |

### Manage assets

| Tool | Description |
|------|-------------|
| `neos_list_assets` | Browse the Media Manager (filter by type, tag) |
| `neos_list_asset_tags` | List available asset tags |

### Review and publish

| Tool | Description |
|------|-------------|
| `neos_get_preview_url` | Generate a 24-hour preview link (no Neos login needed) |
| `neos_list_pending_changes` | See what has been changed |
| `neos_publish_changes` | Publish all changes from workspace to live |

### Typical AI workflow

```
1. neos_get_site_context       → Understand the site
2. neos_get_page_content       → Read current content
3. neos_update_node_property   → Make changes
4. neos_get_preview_url        → Generate preview for human review
5. (human reviews the preview)
6. neos_publish_changes        → Go live after confirmation
```

## Entity CRUD (advanced)

Beyond Neos content nodes, the bridge can also expose custom Doctrine entities for CRUD operations. This is useful for managing data stored in custom database tables (e.g. notifications, form submissions, product data).

Packages declare their entities in YAML — no code changes in the bridge itself:

```yaml
UpAssist:
  Neos:
    Mcp:
      entities:
        notifications:
          label: 'Editor Notifications'
          className: 'Vendor\Package\Domain\Model\Notification'
          repository: 'Vendor\Package\Domain\Repository\NotificationRepository'
          service: 'Vendor\Package\Service\NotificationService'  # optional

          fields:
            title:
              type: string
              label: 'Title'
              required: true
            content:
              type: markdown
              label: 'Content'
            status:
              type: enum
              enum: [draft, active, archived]
```

Once configured, the AI gets access to generic entity tools: `neos_entity_discover`, `neos_entity_list`, `neos_entity_show`, `neos_entity_create`, `neos_entity_update`, `neos_entity_delete`, and `neos_entity_action`.

### Supported field types

| Type | Description |
|------|-------------|
| `string`, `text`, `markdown` | Text fields |
| `integer`, `float` | Numeric fields |
| `boolean` | Boolean (also accepts string "true"/"false") |
| `datetime` | ISO 8601 date/time |
| `reference` | UUID reference to another entity |
| `asset` | Reference to a Neos asset (by UUID) |
| `enum` | Validated against a list of allowed values |
| `json` | JSON data |

### Service delegation

When a `service` and `serviceMethods` are configured, CRUD operations are routed through a service class instead of direct repository calls. This preserves business logic like validation, markdown rendering, and side effects. Use `parameterMapping` when service method parameters differ from field names.

<details>
<summary>Full entity configuration reference</summary>

```yaml
UpAssist:
  Neos:
    Mcp:
      entities:
        myEntity:
          label: 'My Entity'
          className: 'Vendor\Package\Domain\Model\MyEntity'
          repository: 'Vendor\Package\Domain\Repository\MyEntityRepository'
          service: 'Vendor\Package\Service\MyEntityService'

          fields:
            title:
              type: string
              label: 'Title'
              required: true

          filters:
            active:
              label: 'Active items'
              method: 'findActive'
            byStatus:
              label: 'Filter by status'
              method: 'findByFilter'
              parameters:
                filter:
                  type: string
                  required: true

          actions:
            publish:
              label: 'Publish'
              method: 'publish'
              serviceMethod: true
              requiresEntity: true

          serviceMethods:
            create:
              method: 'createEntity'
              parameterMapping:
                content: 'contentMarkdown'
            update:
              method: 'updateEntity'
              parameterMapping:
                content: 'contentMarkdown'
            delete:
              method: 'delete'
```

</details>

## Review Status (opt-in)

The package includes an optional review workflow that shows editors which pages were modified by AI and what changed.

### Enabling

Add the mixin to your Document node types:

```yaml
'Your.Site:Mixin.Document':
  superTypes:
    'UpAssist.Neos.Mcp:Mixin.ReviewStatus': true
```

### What it does

- **Automatic change tracking** — When content is modified via MCP, the closest Document node is marked as "needs review" with a changelog of what changed
- **Visual indicators** — Orange dots appear in the Neos document tree and inspector for pages needing review
- **Translated changelog** — Property changes show translated labels (e.g. "Property 'Keywords' changed" instead of raw property names)
- **Approval workflow** — When an editor sets the status to "Approved", the changelog is automatically cleared

The Review tab in the inspector shows:
- **Status**: Approved / Needs Review
- **Last changed**: When the AI last modified the page
- **Changelog**: List of changes since last approval

## API reference

<details>
<summary>Full REST API documentation</summary>

All endpoints are available under `/neos/mcp/` and accept/return JSON. Every request requires a `Authorization: Bearer <token>` header.

### GET /neos/mcp/getSiteContext

Returns site info, all node types with properties, and the page tree. Typically the first call an MCP client makes.

### POST /neos/mcp/setupWorkspace

Creates the MCP workspace if it doesn't exist.

### POST /neos/mcp/listPages

List all document nodes. Optional parameter: `workspace` (default: `mcp`).

### POST /neos/mcp/getPageContent

Get all content nodes for a page. Parameters: `nodePath` (required), `workspace` (default: `mcp`).

### POST /neos/mcp/getDocumentProperties

Get document-level properties. Parameters: `nodePath` (required), `workspace` (default: `mcp`).

### POST /neos/mcp/listNodeTypes

List available node types. Parameter: `filter` — `content`, `document`, or `all` (default: `content`).

### POST /neos/mcp/updateNodeProperty

Update a single property on a node. Parameters: `contextPath` (required), `property` (required), `value` (required), `workspace` (default: `mcp`). For image/asset properties, pass the asset UUID — it will be resolved automatically.

### POST /neos/mcp/createContentNode

Create a content node. Parameters: `parentPath` (required), `nodeType` (required), `properties` (optional), `workspace` (default: `mcp`).

### POST /neos/mcp/createDocumentNode

Create a page. Parameters: `parentPath` (required), `nodeType` (required), `properties` (optional), `workspace` (default: `mcp`), `nodeName` (optional), `insertBefore`/`insertAfter` (optional).

### POST /neos/mcp/moveNode

Move a node. Parameters: `contextPath` (required), plus exactly one of: `insertBefore`, `insertAfter`, or `newParentPath`. Optional: `workspace` (default: `mcp`).

### POST /neos/mcp/deleteNode

Remove a node. Parameters: `contextPath` (required), `workspace` (default: `mcp`).

### POST /neos/mcp/listPendingChanges

List unpublished changes. Parameter: `workspace` (default: `mcp`).

### POST /neos/mcp/getPreviewUrl

Generate a 24-hour preview URL. Parameters: `nodePath` (required), `workspace` (default: `mcp`).

### GET /neos/mcp/listAssets

List assets from Media Manager. Parameters: `mediaType` (default: `image`), `tag`, `limit` (default: `50`), `offset` (default: `0`).

### GET /neos/mcp/listAssetTags

List all asset tags.

### POST /neos/mcp/publishChanges

Publish all pending changes to live. Parameter: `workspace` (default: `mcp`).

### Entity endpoints

All at `/neos/mcp/entity/`, using Bearer token auth:

| Endpoint | Method | Description |
|----------|--------|-------------|
| `listentities` | GET | Discover all exposed entities with schemas |
| `list` | POST | List/filter entities |
| `show` | POST | Get single entity by UUID |
| `create` | POST | Create new entity |
| `update` | POST | Update entity properties |
| `delete` | POST | Delete entity |
| `execute` | POST | Run a named action |

</details>

## Architecture

<details>
<summary>Internal components</summary>

| Component | Purpose |
|-----------|---------|
| `McpBridgeController` | REST API endpoints for all content operations |
| `ApiTokenProvider` | Flow security authentication provider (Bearer token to Neos roles) |
| `PreviewTokenMiddleware` | HTTP middleware that activates workspace preview from `?_mcpPreview=` tokens |
| `PreviewTokenService` | Singleton holding the active preview workspace for the current request |
| `WorkspacePreviewAspect` | AOP aspect that switches ContentRepository context to the preview workspace |
| `ReviewStatusService` | Tracks MCP changes, creates changelog entries, clears on approval |
| `ReviewStatusNodeInfoAspect` | Injects review status into Neos UI tree node data |

### Preview mechanism

1. `PreviewTokenMiddleware` reads the `_mcpPreview` query parameter
2. Validates the token against cache (24h TTL)
3. Activates the preview workspace via `PreviewTokenService`
4. `WorkspacePreviewAspect` intercepts `ContextFactory->create()` and switches to the preview workspace
5. The page renders normally — hidden content is **not** shown in previews

</details>

## License

MIT
