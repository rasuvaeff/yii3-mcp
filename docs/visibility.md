---
title: "Visibility"
description: "Per-session and declarative MCP tool, prompt and resource visibility in Yii3 — fail-closed filtering for tools/list, tools/call and completions."
---

# Visibility

`ConditionalToolInterface` (see [Capabilities](/capabilities#conditional-registration))
gates registration globally, once, at build time. Visibility is the
**per-session** filter on top of that: which of the registered tools,
prompts, and resources a given session may see and call.

## Declarative tool visibility

The typical case — no code, just name patterns:

```php
'rasuvaeff/yii3-mcp' => [
    'visibility' => [
        'deny' => ['admin.*'],   // hide matches
        'allow' => [],           // non-empty = hide everything it does not match
    ],
],
```

`*` matches any run of characters. A `tag:` prefix matches the tool's
**tags** instead of its name — the [OpenAPI bridge](/openapi-bridge)
propagates OpenAPI `tags` into the tool's `_meta`, so `'deny' =>
['tag:admin']` hides every bridged operation tagged `admin` regardless of
its served name. A tool with no tags never matches a `tag:` pattern. Deny
wins over allow; both lists empty (the default) means every tool is
visible.

> **Trust boundary on `tag:` deny rules.** Tags come from the OpenAPI
> document. Over a URL spec, a document that drops the `admin` tag disarms
> a `deny: ['tag:admin']` rule — exposure stays bounded by the `operations`
> allow-list, but the deny rule itself is only as trustworthy as the spec
> source. Prefer name patterns for deny rules over a remote spec; keep
> `tag:` for allow-listing and for local spec files.

## Per-session visibility

When the decision depends on the **session** (admin vs. public client,
tenant plan), implement `Visibility\ToolVisibilityInterface` instead — it
runs per session, against the handshake data:

```php
use Mcp\Schema\Tool;
use Mcp\Server\Session\SessionInterface;
use Rasuvaeff\Yii3Mcp\Visibility\ToolVisibilityInterface;

final readonly class PlanBasedVisibility implements ToolVisibilityInterface
{
    public function isVisible(Tool $tool, ?SessionInterface $session): bool
    {
        // decide from $session->get('client_info'), tenant data, …
        return !str_starts_with($tool->name, 'admin.') || $this->isAdmin($session);
    }
}
```

```php
'rasuvaeff/yii3-mcp' => [
    'tool_visibility' => PlanBasedVisibility::class,   // DI-resolved
],
```

Declarative `visibility` and a `tool_visibility` class are mutually
exclusive — configuring both is a build-time error (`LogicException`).

Either kind applies in **two places, consistently**: `tools/list` omits
invisible tools, and `tools/call` **fail-closed** rejects them — a client
that guesses a hidden name still gets a tool error, and the call never
reaches the interceptor chain or the tool itself. This is an early filter,
not a replacement for application-level ACL — see the
[RBAC bridge](/bridges/rbac) for authorization that also runs *inside* the
chain, per user.

## Prompts and resources

The same seam exists for the other two capabilities, with their own
interfaces so a tool policy never accidentally applies to a prompt:
`Visibility\PromptVisibilityInterface` (`prompt_visibility`) and
`Visibility\ResourceVisibilityInterface` (`resource_visibility`, covers
both static resources and templates). Hiding either reports the capability
as **not found** — indistinguishable from a missing one — for both the
list method and a direct `prompts/get` / `resources/read`, so listing and
fetching can never disagree.

## Completions obey the same filters

`completion/complete` (argument autocompletion, see
[Capabilities](/capabilities#argument-autocompletion-completion-complete)) is
served by the SDK straight off the registry — bypassing the reference
handler entirely, and with it every interceptor chain. Before
`Visibility\FilteredCompletionCompleteHandler` existed, a prompt or resource
hidden by visibility still answered completions for its arguments — verified
end-to-end, not inferred — leaking both the suggested **values** and the
capability's **existence** (a hidden ref answered, a missing one errored).
The decorator wraps the SDK's own `CompletionCompleteHandler` and reports a
hidden ref exactly like a missing one, using the SDK's own
`PromptNotFoundException` / `ResourceNotFoundException` message so the two
are byte-identical. It is installed automatically whenever `prompt_visibility`
or `resource_visibility` is configured — no separate params key.

## What visibility is not

Visibility is a per-session **filter**, evaluated against handshake/session
data such as `client_info`. It is not:

- an authorization decision tied to an authenticated application user (that
  belongs to the [RBAC bridge](/bridges/rbac), or your own
  [interceptor](/interceptors));
- an audit trail (the [audit-log bridge](/bridges/audit-log) records what
  actually ran, independent of what was hidden);
- a substitute for `ConditionalToolInterface` when a capability should never
  exist on a given deployment at all, regardless of session.
