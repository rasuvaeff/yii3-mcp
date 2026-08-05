import { defineConfig } from 'vitepress'

const sidebar = [
    {
        text: 'Intro',
        items: [
            { text: 'What is MCP', link: '/intro/what-is-mcp' },
            { text: 'Getting started', link: '/intro/getting-started' },
            { text: 'Architecture', link: '/intro/architecture' },
        ],
    },
    { text: 'Protocol', link: '/protocol' },
    { text: 'Capabilities', link: '/capabilities' },
    { text: 'Security', link: '/security' },
    {
        text: 'Extension points',
        items: [
            { text: 'Interceptors', link: '/interceptors' },
            { text: 'Visibility', link: '/visibility' },
            { text: 'OpenAPI bridge', link: '/openapi-bridge' },
            { text: 'MCP Apps', link: '/apps' },
        ],
    },
    { text: 'Multi-tenant serving', link: '/multi-tenant' },
    { text: 'Operations', link: '/operations' },
    { text: 'Framework-agnostic usage', link: '/framework-agnostic' },
    {
        text: 'Bridges',
        items: [
            { text: 'Overview', link: '/bridges/overview' },
            { text: 'Audit log', link: '/bridges/audit-log' },
            { text: 'RBAC', link: '/bridges/rbac' },
            { text: 'Telemetry', link: '/bridges/telemetry' },
        ],
    },
    {
        text: 'Packages',
        items: [
            { text: 'yii3-mcp (core)', link: '/packages/core' },
            { text: 'yii3-mcp-audit-log-bridge', link: '/packages/audit-log-bridge' },
            { text: 'yii3-mcp-rbac-bridge', link: '/packages/rbac-bridge' },
            { text: 'yii3-mcp-telemetry-bridge', link: '/packages/telemetry-bridge' },
        ],
    },
    {
        text: 'Cookbook',
        items: [
            { text: 'Your first MCP server', link: '/cookbook/mcp-server-first-time' },
            { text: 'Debugging with mcp:doctor', link: '/cookbook/debugging-with-doctor' },
            { text: 'Rotating the shared secret', link: '/cookbook/secret-rotation' },
            { text: 'Bridging an existing REST API', link: '/cookbook/bridging-existing-api' },
        ],
    },
    { text: 'API reference', link: '/api/index' },
    { text: 'Roadmap', link: '/roadmap' },
    { text: 'llms.txt reference', link: '/llms' },
]

const SITE_URL = 'https://rasuvaeff.github.io/yii3-mcp/'

export default defineConfig({
    title: 'Yii3 MCP',
    description:
        'MCP (Model Context Protocol) server integration for Yii3: expose Yii3 application tools, resources and prompts to AI agents like Claude Code and Claude Desktop over Streamable HTTP — with an OpenAPI bridge, session security, and audit log, RBAC and telemetry bridges.',
    base: '/yii3-mcp/',
    cleanUrls: true,
    lastUpdated: true,
    sitemap: { hostname: SITE_URL },
    // docs/siblings/ is where CI checks out the three bridge repos (read-only,
    // for the API reflection pass — see docs/scripts/reflect-api.php) and
    // where their OWN vendor/ trees land after `composer install`; without
    // this, VitePress compiles every .md under docs/ as a page, including
    // third-party CHANGELOGs inside vendor/, and a malformed one fails the
    // whole build (verified: justinrainbow/json-schema's did, in CI).
    srcExclude: ['siblings/**'],
    head: [
        ['link', { rel: 'icon', type: 'image/svg+xml', href: '/yii3-mcp/favicon.svg' }],
        ['meta', { name: 'theme-color', content: '#4B45B2' }],
        ['meta', { property: 'og:type', content: 'website' }],
        ['meta', { property: 'og:site_name', content: 'Yii3 MCP' }],
        ['meta', { name: 'twitter:card', content: 'summary' }],
    ],
    // Per-page canonical + Open Graph/Twitter title & description — VitePress's
    // static `head` array above can't vary per page, and every page otherwise
    // shares one generic <meta description>, which is worse for search than a
    // page-specific one (set via each page's own `description` frontmatter).
    transformHead: ({ pageData, title, description }) => {
        // `pageData.relativePath` is e.g. 'security.md' or 'index.md' (cleanUrls
        // strips the extension on the SERVED url, not here) — mirror cleanUrls
        // by dropping '.md' and collapsing an 'index' segment to ''.
        const clean = pageData.relativePath.replace(/\.md$/, '').replace(/(^|\/)index$/, '$1')
        const url = SITE_URL + clean

        return [
            ['link', { rel: 'canonical', href: url }],
            ['meta', { property: 'og:title', content: title }],
            ['meta', { property: 'og:description', content: description }],
            ['meta', { property: 'og:url', content: url }],
            ['meta', { name: 'twitter:title', content: title }],
            ['meta', { name: 'twitter:description', content: description }],
        ]
    },
    themeConfig: {
        logo: '/logo-mark.svg',
        nav: [
            { text: 'Guide', link: '/intro/what-is-mcp' },
            { text: 'Bridges', link: '/bridges/overview' },
            { text: 'API', link: '/api/index' },
            { text: 'GitHub', link: 'https://github.com/rasuvaeff/yii3-mcp' },
        ],
        sidebar: { '/': sidebar },
        search: { provider: 'local' },
        outlineTitle: 'On this page',
        socialLinks: [{ icon: 'github', link: 'https://github.com/rasuvaeff/yii3-mcp' }],
        editLink: {
            pattern: 'https://github.com/rasuvaeff/yii3-mcp/edit/master/docs/:path',
        },
    },
})
