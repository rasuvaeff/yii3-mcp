---
title: "MCP Apps"
---

# MCP Apps

[MCP Apps](https://github.com/modelcontextprotocol/ext-apps)
(`io.modelcontextprotocol/ui`) are HTML documents served as `ui://`
resources and rendered by the client in a sandboxed iframe, inside the
conversation. The extension must be **announced** during the handshake — a
`ui://` resource on a server that does not announce it is just text to the
client.

## Declarative apps

```php
'rasuvaeff/yii3-mcp' => [
    'apps' => [
        'enable' => true,   // announce the extension (enough for attribute-based apps)
        'definitions' => [
            [
                'uri' => 'ui://dashboard',        // required, must start with ui://
                'name' => 'dashboard',            // required, unique
                'html' => '<!DOCTYPE html>…',     // string, or Closure(): string
                'title' => 'Dashboard',
                'description' => 'Sales overview',
                'csp' => ['connect_domains' => ['api.example.com']],
                'permissions' => ['geolocation' => true],
                'prefers_border' => true,
            ],
        ],
    ],
],
```

A non-empty `definitions` list enables the extension on its own — `enable`
is only needed to announce it with no declarative apps present. `html` as a
`Closure(): string` is re-evaluated on **every** `resources/read` — the hook
for templating and DI-provided data, and the reason an expensive render
costs that much per read.

## Attribute-based apps

For an app with logic behind it, declare a `ui://` resource the usual way
and return the content yourself:

```php
#[McpResource(
    uri: 'ui://report',
    name: 'report',
    mimeType: McpApps::MIME_TYPE,
    meta: ['ui' => new \stdClass()],        // descriptor marker
)]
public function report(): TextResourceContents
{
    return new TextResourceContents(
        uri: 'ui://report',
        mimeType: McpApps::MIME_TYPE,
        text: '<!DOCTYPE html><h1>Report</h1>',
        meta: ['ui' => new UiResourceContentMeta(  // sandbox contract
            csp: new UiResourceCsp(connectDomains: ['api.example.com']),
            prefersBorder: true,
        )],
    );
}
```

This path still needs `'apps' => ['enable' => true]` to announce the
extension. Returning a plain string works too, but only a returned
`TextResourceContents` can carry `_meta.ui`.

## Where `_meta.ui` goes

The single easy mistake with MCP Apps is mixing these two up — the wrong
one is silently ignored:

| Level | Value | Carries |
|---|---|---|
| Descriptor (`resources/list`) | `McpApps::resourceMarker()` — an empty `{}` | "this resource is an app," nothing else. |
| Content (`resources/read`) | `UiResourceContentMeta` | `csp`, `permissions`, `domain`, `prefersBorder`. |

A sandbox policy placed on the descriptor is ignored; a marker placed on the
content tells the host nothing.

## Sandbox: CSP and permissions

`UiResourceCsp` allow-lists what the iframe may reach: `connect_domains` for
fetch/XHR/WebSocket, `resource_domains` for images/scripts/styles,
`frame_domains`, `base_uri_domains`. Omitting the CSP entirely leaves the
host's own restrictive default in place. `UiResourcePermissions` requests
sandbox capabilities (`camera`, `microphone`, `geolocation`,
`clipboard_write`) — in params these are plain booleans, and only `true`
ones are sent.

Domains are passed to the client verbatim: the policy is enforced by the
host, and `definitions` is application-owned configuration, not client
input. The app HTML itself is served as-is — it is your responsibility not
to interpolate untrusted data into it.

## Linking a tool to an app

```php
#[McpTool(
    name: 'refresh_report',
    meta: ['ui' => new UiToolMeta(resourceUri: 'ui://report')],
)]
public function refresh(): string { /* … */ }
```

`UiToolMeta::$visibility` (`ToolVisibility::Model` / `ToolVisibility::App`)
declares who may call it — an app-only tool is hidden from the model's
`tools/list` **by the host**; the server only states the intent, so treat
it as a UX hint, not an access-control boundary. For a server-side
guarantee use [`Visibility\ToolVisibilityInterface`](/visibility), which
fail-closed rejects the call itself.

## App resources are ordinary resources otherwise

`ResourceVisibilityInterface` filters them, `resource_interceptors` wrap
their reads, and a `ui://` URI colliding with an attribute resource fails
the build like any other duplicate capability (see
[Security: capability name collisions](/security#capability-name-collisions)).
`McpAppsConfigurator` is the **single enabler** of the extension — a second
`enableExtension(new McpApps())` from an application configurator fails the
build, since the SDK rejects a duplicate extension id.
