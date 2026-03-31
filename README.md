# UpAssist.Neos.Mcp

HTTP bridge that enables AI tools (via MCP protocol) to read, create, edit, and publish content in Neos CMS. Changes are staged in a dedicated workspace for human review before going live.

## Requirements

- Neos CMS 8.3+
- PHP 8.1+

## Installation

Since this is a private package, add the VCS repository to your project's `composer.json`:

```json
{
  "repositories": {
    "upassist/neos-mcp": {
      "type": "git",
      "url": "git@github.com:UpAssist/neos-mcp.git"
    }
  }
}
```

Then install:

```bash
composer require upassist/neos-mcp:dev-main
```

## Configuration

Add the API token to your `.env` file:

```env
NEOS_MCP_BRIDGE_TOKEN=your-secret-token-here
```

The default settings in `Configuration/Settings.yaml`:

```yaml
UpAssist:
  Neos:
    Mcp:
      apiToken: '%env:NEOS_MCP_BRIDGE_TOKEN%'
      mcpWorkspaceName: 'mcp'
      mcpWorkspaceTitle: 'MCP Review'
      mcpWorkspaceDescription: 'Shared workspace for AI-assisted content changes, pending human review'
```

### Workspace

All content changes are written to a dedicated **mcp** workspace (branched from `live`). This workspace is created automatically on first use. Changes remain there until explicitly published.

### Authentication & Security

The bridge uses Bearer token authentication. Every request must include:

```
Authorization: Bearer <your-token>
```

When a valid token is provided, the request is authenticated with **Neos.Neos:Administrator** privileges. This is configurable in `Settings.yaml`:

```yaml
Neos:
  Flow:
    security:
      authentication:
        providers:
          'UpAssist.Neos.Mcp:ApiToken':
            providerOptions:
              authenticateRoles:
                - 'Neos.Neos:Editor'  # Use Editor instead of Administrator
```

## API Endpoints

All endpoints are available under `/neos/mcp/` and accept/return JSON.

### GET /neos/mcp/getSiteContext

Returns full site context: site info, all node types with properties, and the page tree. This is typically the first call an MCP client makes to understand the site structure.

**Response:**
```json
{
  "siteName": "example.com",
  "siteNodePath": "/sites/example",
  "mcpWorkspace": "mcp",
  "nodeTypes": [
    {
      "name": "Vendor.Site:Content.Text",
      "isContent": true,
      "isDocument": false,
      "properties": {
        "text": { "type": "string", "label": "Text" }
      }
    }
  ],
  "pages": [
    {
      "path": "/sites/example",
      "title": "Home",
      "nodeType": "Vendor.Site:Document.Home",
      "hidden": false,
      "depth": 0
    }
  ],
  "workflowInstructions": "Always write content changes to the \"mcp\" workspace..."
}
```

### POST /neos/mcp/setupWorkspace

Creates the MCP workspace if it doesn't exist yet.

**Response:**
```json
{
  "success": true,
  "workspace": { "name": "mcp", "title": "MCP Review" }
}
```

### POST /neos/mcp/listPages

Lists all document nodes (pages) in a workspace.

**Parameters:**
| Name | Type | Default | Description |
|------|------|---------|-------------|
| `workspace` | string | `mcp` | Workspace to read from |

**Response:**
```json
{
  "pages": [
    {
      "path": "/sites/example",
      "title": "Home",
      "nodeType": "Vendor.Site:Document.Home",
      "hidden": false,
      "depth": 0
    }
  ]
}
```

### POST /neos/mcp/getPageContent

Returns all content nodes for a specific page, including nested content.

**Parameters:**
| Name | Type | Default | Description |
|------|------|---------|-------------|
| `nodePath` | string | *required* | Path to the document node |
| `workspace` | string | `mcp` | Workspace to read from |

**Response:**
```json
{
  "page": {
    "identifier": "abc-123",
    "contextPath": "/sites/example@mcp",
    "path": "/sites/example",
    "nodeType": "Vendor.Site:Document.Home",
    "title": "Home"
  },
  "contentNodes": [
    {
      "identifier": "def-456",
      "contextPath": "/sites/example/main/text-1@mcp",
      "nodeType": "Vendor.Site:Content.Text",
      "properties": {
        "text": "<p>Hello world</p>"
      }
    }
  ]
}
```

### POST /neos/mcp/listNodeTypes

Lists available node types with their properties.

**Parameters:**
| Name | Type | Default | Description |
|------|------|---------|-------------|
| `filter` | string | `content` | `content`, `document`, or `all` |

### POST /neos/mcp/updateNodeProperty

Updates a single property on an existing node. Supports automatic type resolution for asset/image properties and boolean string coercion.

**Parameters:**
| Name | Type | Default | Description |
|------|------|---------|-------------|
| `contextPath` | string | *required* | Node context path (e.g. `/sites/example@mcp`) |
| `property` | string | *required* | Property name |
| `value` | string | *required* | New property value. For image/asset properties, pass the asset UUID (e.g. `"ce372ab4-cde1-4dc4-b49c-a9b90313df0b"`) — it will be resolved to the actual Asset object automatically. |
| `workspace` | string | `mcp` | Target workspace |

**Response:**
```json
{
  "success": true,
  "contextPath": "/sites/example@mcp",
  "property": "image",
  "newValue": "asset,ce372ab4-cde1-4dc4-b49c-a9b90313df0b"
}
```

### POST /neos/mcp/createContentNode

Creates a new content node inside a parent node.

**Parameters:**
| Name | Type | Default | Description |
|------|------|---------|-------------|
| `parentPath` | string | *required* | Path to the parent node |
| `nodeType` | string | *required* | Fully qualified node type name |
| `properties` | object | `{}` | Initial property values |
| `workspace` | string | `mcp` | Target workspace |

### POST /neos/mcp/createDocumentNode

Creates a new document (page) node.

**Parameters:**
| Name | Type | Default | Description |
|------|------|---------|-------------|
| `parentPath` | string | *required* | Path to the parent document node |
| `nodeType` | string | *required* | Fully qualified node type name |
| `properties` | object | `{}` | Initial property values |
| `workspace` | string | `mcp` | Target workspace |
| `nodeName` | string | auto-generated | URL-safe node name |
| `insertBefore` | string | | Context path of sibling to insert before |
| `insertAfter` | string | | Context path of sibling to insert after |

### POST /neos/mcp/moveNode

Moves a node to a new position.

**Parameters:**
| Name | Type | Default | Description |
|------|------|---------|-------------|
| `contextPath` | string | *required* | Node to move |
| `insertBefore` | string | | Move before this sibling |
| `insertAfter` | string | | Move after this sibling |
| `newParentPath` | string | | Move into this parent (as last child) |
| `workspace` | string | `mcp` | Target workspace |

Provide exactly one of `insertBefore`, `insertAfter`, or `newParentPath`.

### POST /neos/mcp/deleteNode

Marks a node as removed in the workspace.

**Parameters:**
| Name | Type | Default | Description |
|------|------|---------|-------------|
| `contextPath` | string | *required* | Node to delete |
| `workspace` | string | `mcp` | Target workspace |

### POST /neos/mcp/listPendingChanges

Lists all unpublished changes in a workspace.

**Parameters:**
| Name | Type | Default | Description |
|------|------|---------|-------------|
| `workspace` | string | `mcp` | Workspace to check |

**Response:**
```json
{
  "workspace": "mcp",
  "count": 2,
  "pendingChanges": [
    {
      "identifier": "abc-123",
      "contextPath": "/sites/example/main/text-1@mcp",
      "nodeType": "Vendor.Site:Content.Text",
      "changeType": "modified"
    }
  ]
}
```

### POST /neos/mcp/getPreviewUrl

Generates a time-limited preview URL that renders the site from the workspace without requiring a Neos login.

**Parameters:**
| Name | Type | Default | Description |
|------|------|---------|-------------|
| `nodePath` | string | *required* | Path to the document node |
| `workspace` | string | `mcp` | Workspace to preview |

**Response:**
```json
{
  "previewUrl": "https://example.com/page?_mcpPreview=abc123...",
  "token": "abc123...",
  "expiresAt": "2026-03-26T12:00:00+00:00"
}
```

Preview tokens expire after 24 hours. The preview shows the page as rendered from the workspace (including unpublished changes) but does **not** show hidden content.

### GET /neos/mcp/listAssets

Lists assets from the Neos Media Manager with filtering and pagination.

**Parameters:**
| Name | Type | Default | Description |
|------|------|---------|-------------|
| `mediaType` | string | `image` | Media type prefix filter (e.g. `image`, `video`, `application`). Empty for all. |
| `tag` | string | | Filter by tag label (exact match) |
| `limit` | int | `50` | Max results per page |
| `offset` | int | `0` | Pagination offset |

**Response:**
```json
{
  "assets": [
    {
      "identifier": "abc-123",
      "title": "Solar Panel Photo",
      "caption": "",
      "filename": "solar-panel.jpg",
      "mediaType": "image/jpeg",
      "fileSize": 245000,
      "tags": ["hero", "zonnepanelen"],
      "collections": ["Site Assets"],
      "lastModified": "2026-03-20T10:00:00+01:00"
    }
  ],
  "total": 42,
  "limit": 50,
  "offset": 0,
  "mediaTypeFilter": "image",
  "tagFilter": ""
}
```

### GET /neos/mcp/listAssetTags

Lists all available tags in the Media Manager.

**Response:**
```json
{
  "tags": [
    { "identifier": "abc-123", "label": "hero" },
    { "identifier": "def-456", "label": "zonnepanelen" }
  ]
}
```

### POST /neos/mcp/publishChanges

Publishes all pending changes from the workspace to `live`.

**Parameters:**
| Name | Type | Default | Description |
|------|------|---------|-------------|
| `workspace` | string | `mcp` | Workspace to publish from |

**Response:**
```json
{
  "success": true,
  "publishedNodes": 3,
  "workspace": "mcp",
  "targetWorkspace": "live"
}
```

## Review Status (opt-in)

The package includes an optional review workflow that tracks content changes made via MCP and provides visual indicators for editors.

### Enabling

Add the mixin to your Document node types:

```yaml
'Your.Site:Mixin.Document':
  superTypes:
    'UpAssist.Neos.Mcp:Mixin.ReviewStatus': true
```

### How it works

1. **Automatic change tracking**: When content is modified via MCP, the closest Document node is marked as "needs review" and a changelog entry is created
2. **Readable changelog**: Property changes are logged with their translated XLIFF label (e.g. "Eigenschap 'Trefwoorden' gewijzigd" instead of "Property 'metaKeywords' changed"). The changelog is displayed in the user's interface language via a custom inspector editor
3. **Approval clears changelog**: When an editor sets the status to "Approved" in the inspector, the changelog is automatically cleared
4. **Visual indicators**: Orange dots appear in the document tree and on the Review inspector tab for pages needing review. The dots disappear when the page is approved

### Components

| Component | Purpose |
|-----------|---------|
| `Mixin.ReviewStatus` | NodeType mixin adding `reviewStatus`, `reviewChangelog`, `reviewLastChangedAt` properties |
| `ReviewStatusService` | Listens to `nodeMutated` signals, creates changelog entries; listens to `nodePropertyChanged` to clear changelog on approval |
| `ReviewStatusNodeInfoAspect` | AOP aspect that injects `reviewStatus` into tree node data for the Neos UI |
| `ChangelogEditor` (JS) | Custom Neos UI inspector editor that renders changelog entries with i18n-translated labels |
| `reviewIndicator` (JS) | Neos UI plugin that adds orange dot indicators to tree nodes and the inspector tab |

### Inspector tabs

The Review tab appears in the inspector with:
- **Status**: Select box (Approved / Needs Review)
- **Last changed**: DateTime of the last MCP modification
- **Changelog**: Read-only list of changes since last approval, translated to the user's interface language

## Architecture

```
MCP Client (Claude Code, etc.)
    │
    │  MCP protocol (stdio)
    │
MCP Server (neos-mcp-client)
    │
    │  HTTP + Bearer token
    │
UpAssist.Neos.Mcp Bridge  ◄── this package
    │
    │  Neos ContentRepository API
    │
Neos CMS (mcp workspace → live)
```

### Components

| Component | Purpose |
|-----------|---------|
| `McpBridgeController` | REST API endpoints for all content operations |
| `ApiTokenProvider` | Flow security authentication provider (Bearer token → Neos roles) |
| `PreviewTokenMiddleware` | HTTP middleware that activates workspace preview from `?_mcpPreview=` token |
| `PreviewTokenService` | Singleton that holds the active preview workspace for the current request |
| `WorkspacePreviewAspect` | AOP aspect that switches ContentRepository context to the preview workspace |

### Preview mechanism

When a preview URL is visited:

1. `PreviewTokenMiddleware` reads the `_mcpPreview` query parameter
2. Validates the token against the cache (token → workspace mapping, 24h TTL)
3. Activates the preview workspace via `PreviewTokenService`
4. `WorkspacePreviewAspect` intercepts `ContextFactory->create()` and switches to the preview workspace
5. The same aspect fakes `ContentContext->isLive()` so `NodeController::showAction()` renders the page normally

Hidden content is **not** shown in previews. The API itself can read hidden content (for editing purposes).

## MCP Client

This bridge is designed to work with [neos-mcp-client](https://github.com/UpAssist/neos-mcp-client), a Node.js MCP server that translates MCP tool calls into HTTP requests to this bridge.

### Claude Code setup

Add a `.mcp.json` file to your project root:

```json
{
  "mcpServers": {
    "neos-local": {
      "command": "node",
      "args": ["/path/to/neos-mcp-client/dist/index.js"],
      "env": {
        "NEOS_MCP_URL": "http://localhost:8081",
        "NEOS_MCP_TOKEN": "your-token"
      }
    }
  }
}
```

> **Important:** Add `.mcp.json` to `.gitignore` — it contains tokens.

For global configuration (all projects), add the same `mcpServers` block to `~/.claude.json` instead.

See the [neos-mcp-client README](https://github.com/UpAssist/neos-mcp-client) for full setup instructions including Cursor and multi-environment configuration.

## Typical workflow

1. MCP client calls `getSiteContext` to understand the site
2. AI reads page content via `getPageContent`
3. AI makes changes via `updateNodeProperty`, `createContentNode`, etc.
4. AI generates a preview URL via `getPreviewUrl` for human review
5. Human reviews the preview and confirms
6. AI publishes via `publishChanges`

## License

Proprietary
