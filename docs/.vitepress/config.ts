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

export default defineConfig({
    title: 'Yii3 MCP',
    description: 'MCP server integration for Yii3 — core + bridges (audit log, RBAC, telemetry)',
    base: '/yii3-mcp/',
    cleanUrls: true,
    lastUpdated: true,
    // docs/siblings/ is where CI checks out the three bridge repos (read-only,
    // for the API reflection pass — see docs/scripts/reflect-api.php) and
    // where their OWN vendor/ trees land after `composer install`; without
    // this, VitePress compiles every .md under docs/ as a page, including
    // third-party CHANGELOGs inside vendor/, and a malformed one fails the
    // whole build (verified: justinrainbow/json-schema's did, in CI).
    srcExclude: ['siblings/**'],
    themeConfig: {
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
