<script setup lang="ts">
import { withBase } from 'vitepress'

const year = new Date().getFullYear()

// Plain <a href="/..."> is NOT rewritten with `base` by VitePress (only
// markdown links and router-aware components get that) — an absolute path
// here would 404 under the '/yii3-mcp/' base. External links pass through.
function href(link: string): string {
    return /^https?:\/\//.test(link) ? link : withBase(link)
}

const columns = [
    {
        title: 'Guide',
        links: [
            { text: 'What is MCP', link: '/intro/what-is-mcp' },
            { text: 'Getting started', link: '/intro/getting-started' },
            { text: 'Architecture', link: '/intro/architecture' },
            { text: 'Capabilities', link: '/capabilities' },
        ],
    },
    {
        title: 'Extension points',
        links: [
            { text: 'Interceptors', link: '/interceptors' },
            { text: 'Visibility', link: '/visibility' },
            { text: 'OpenAPI bridge', link: '/openapi-bridge' },
            { text: 'MCP Apps', link: '/apps' },
        ],
    },
    {
        title: 'Bridges',
        links: [
            { text: 'Overview', link: '/bridges/overview' },
            { text: 'Audit log', link: '/bridges/audit-log' },
            { text: 'RBAC', link: '/bridges/rbac' },
            { text: 'Telemetry', link: '/bridges/telemetry' },
        ],
    },
    {
        title: 'Community',
        links: [
            { text: 'GitHub', link: 'https://github.com/rasuvaeff/yii3-mcp' },
            { text: 'Report an issue', link: 'https://github.com/rasuvaeff/yii3-mcp/issues/new' },
            { text: 'Packagist', link: 'https://packagist.org/packages/rasuvaeff/yii3-mcp' },
            { text: 'Roadmap', link: '/roadmap' },
        ],
    },
]
</script>

<template>
  <footer class="site-footer">
    <div class="site-footer-inner">
      <div class="site-footer-grid">
        <div class="site-footer-brand">
          <a :href="withBase('/')" class="brand-mark">Yii3 MCP</a>
          <p>MCP server integration for Yii3 — built on the official mcp/sdk.</p>
        </div>
        <div v-for="col in columns" :key="col.title" class="site-footer-col">
          <h3>{{ col.title }}</h3>
          <ul>
            <li v-for="l in col.links" :key="l.text">
              <a :href="href(l.link)">{{ l.text }}</a>
            </li>
          </ul>
        </div>
      </div>
      <div class="site-footer-bottom">
        <span>© {{ year }} <a href="https://github.com/rasuvaeff">Victor Razuvaev</a> · BSD-3-Clause</span>
        <span>Built with <a href="https://vitepress.dev/" target="_blank" rel="noreferrer">VitePress</a></span>
      </div>
    </div>
  </footer>
</template>

<style scoped>
.site-footer {
  /* VPSidebar is `position: fixed` and pinned to the viewport for the
     entire scroll height (--vp-z-index-sidebar: 25), not just alongside
     the doc content — CSS paints ANY positioned element above in-flow
     static content regardless of DOM order, so a plain static footer
     renders BEHIND it once scrolled this far, not just visually "behind
     in stacking" but literally covered/invisible on its left side.
     Opting into the positioned layer with a higher z-index is what makes
     the footer paint on top, the way it visually should. */
  position: relative;
  z-index: 30;
  border-top: 1px solid var(--vp-c-divider);
  background: var(--vp-c-bg-alt);
  margin-top: 64px;
}

.site-footer-inner {
  max-width: 1152px;
  margin: 0 auto;
  padding: 48px 24px 32px;
}

.site-footer-grid {
  display: grid;
  /* `1fr` alone won't shrink below its content's min-content width, so a
     fixed 5-track template can force this wider than its container at
     in-between (laptop, non-home-page-sidebar) widths — auto-fit/minmax
     reflows to fewer columns instead, so it physically cannot overflow. */
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: 32px 24px;
}

.site-footer-brand {
  min-width: 0;
  grid-column: span 2;
}

.site-footer-brand .brand-mark {
  font-weight: 700;
  font-size: 15px;
  color: var(--vp-c-text-1);
}

.site-footer-brand p {
  margin: 8px 0 0;
  font-size: 13px;
  line-height: 1.6;
  color: var(--vp-c-text-2);
  max-width: 32ch;
}

.site-footer-col {
  min-width: 0;
}

.site-footer-col h3 {
  margin: 0 0 12px;
  font-size: 12px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--vp-c-text-2);
  border: none;
  padding: 0;
}

.site-footer-col ul {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.site-footer-col a {
  font-size: 13px;
  color: var(--vp-c-text-1);
}

.site-footer-col a:hover {
  color: var(--vp-c-brand-1);
}

.site-footer-bottom {
  margin-top: 40px;
  padding-top: 20px;
  border-top: 1px solid var(--vp-c-divider);
  display: flex;
  flex-wrap: wrap;
  gap: 8px 24px;
  justify-content: space-between;
  font-size: 12px;
  color: var(--vp-c-text-3);
}

.site-footer-bottom a {
  color: var(--vp-c-text-2);
}

.site-footer-bottom a:hover {
  color: var(--vp-c-brand-1);
}
</style>
