import { readFileSync, readdirSync, statSync, existsSync } from 'node:fs'
import { dirname, join, extname } from 'node:path'
import { fileURLToPath } from 'node:url'

const scriptsDir = dirname(fileURLToPath(import.meta.url))
const docsDir = join(scriptsDir, '..')
const pkgDir = join(docsDir, '..')

const errors = []
const fail = (message) => errors.push(message)

// 1. README.md and llms.txt must exist and be non-trivial for the core package.
for (const file of ['README.md', 'llms.txt', 'ROADMAP.md']) {
    const path = join(pkgDir, file)
    if (!existsSync(path)) {
        fail(`${file} is missing.`)
        continue
    }
    if (readFileSync(path, 'utf8').trim().length < 200) {
        fail(`${file} is suspiciously short (< 200 chars) — looks empty or truncated.`)
    }
}

// 2. Every @api class from the reflection snapshot must have a generated
//    api/classes page, and every public method/property name must actually
//    appear on that page (catches a generator regression that silently drops
//    members, not just a missing file).
const snapshot = JSON.parse(readFileSync(join(scriptsDir, 'api-snapshot.json'), 'utf8'))
const apiClasses = snapshot.filter((entry) => entry.isApi)

function shortName(className) {
    const parts = className.split('\\')
    return parts[parts.length - 1]
}

for (const entry of apiClasses) {
    const name = shortName(entry.class)
    const pagePath = join(docsDir, 'api', 'classes', `${name}.md`)
    if (!existsSync(pagePath)) {
        fail(`Missing generated API page for @api class "${entry.class}": api/classes/${name}.md`)
        continue
    }
    const content = readFileSync(pagePath, 'utf8')
    for (const method of entry.publicMethods) {
        if (!content.includes(method.name)) {
            fail(`API page api/classes/${name}.md is missing method "${method.name}" from the reflection snapshot.`)
        }
    }
    for (const prop of entry.publicProperties) {
        if (!content.includes(prop.name)) {
            fail(`API page api/classes/${name}.md is missing property "${prop.name}" from the reflection snapshot.`)
        }
    }
}

// 3. No generated page for a public-but-non-@api class — the reference must
//    filter by the @api tag, never leak internals.
const apiShortNames = new Set(apiClasses.map((entry) => shortName(entry.class)))
const classesDir = join(docsDir, 'api', 'classes')
if (existsSync(classesDir)) {
    for (const file of readdirSync(classesDir)) {
        const name = file.replace(/\.md$/, '')
        if (!apiShortNames.has(name)) {
            fail(`api/classes/${file} has no corresponding @api entry in the reflection snapshot — a non-@api or removed class leaked into the reference.`)
        }
    }
}

// 4. Every internal link in the sidebar/nav config, and every internal link
//    inside a page's own markdown, must resolve to a file on disk.
function collectMarkdownFiles(dir) {
    const results = []
    for (const entry of readdirSync(dir, { withFileTypes: true })) {
        const path = join(dir, entry.name)
        if (entry.name === 'siblings' || entry.name === 'node_modules' || entry.name === '.vitepress') continue // not site pages
        if (entry.isDirectory()) {
            results.push(...collectMarkdownFiles(path))
        } else if (extname(entry.name) === '.md') {
            results.push(path)
        }
    }
    return results
}

function resolveLink(link) {
    // Strip a query/hash suffix; VitePress `cleanUrls` links omit `.md`.
    const clean = link.split('#')[0].split('?')[0]
    if (clean === '' || clean === '/') return true
    const withoutLeadingSlash = clean.replace(/^\//, '')
    const candidates = [join(docsDir, withoutLeadingSlash + '.md'), join(docsDir, withoutLeadingSlash, 'index.md')]
    return candidates.some((path) => existsSync(path) && statSync(path).isFile())
}

const configPath = join(docsDir, '.vitepress', 'config.ts')
const configSource = readFileSync(configPath, 'utf8')
const linkPattern = /link:\s*'([^']+)'/g
for (const match of configSource.matchAll(linkPattern)) {
    const link = match[1]
    if (/^https?:\/\//.test(link)) continue
    if (!resolveLink(link)) {
        fail(`config.ts references a link that does not resolve to a file: "${link}"`)
    }
}

const markdownLinkPattern = /\]\((\/[^)#\s]+)(#[^)\s]*)?\)/g
// Raw HTML anchors like <a href="/..."> bypass VitePress's `base` rewriting
// (only markdown links and Vue Router links get the base prefix), so they'd
// point at the domain root on a project-Pages site. Internal links must be markdown.
const rawHtmlAnchorPattern = /<a\s[^>]*href="\/(?!\/)[^"]*"/i
for (const file of collectMarkdownFiles(docsDir)) {
    const content = readFileSync(file, 'utf8')
    if (rawHtmlAnchorPattern.test(content)) {
        const lineNum = content.split('\n').findIndex((l) => rawHtmlAnchorPattern.test(l)) + 1
        fail(`${file.replace(docsDir + '/', '')}:${lineNum} uses a raw HTML <a href="/..."> internal link — VitePress does not apply \`base\` to it. Use a markdown link instead.`)
    }
    for (const match of content.matchAll(markdownLinkPattern)) {
        const link = match[1]
        if (!resolveLink(link)) {
            fail(`${file.replace(docsDir + '/', '')} links to "${link}", which does not resolve to a file.`)
        }
    }
}

// 5. Every @api class name should be mentioned somewhere in its own package's
//    llms.txt — a weak but cheap proxy for "the compact LLM reference wasn't
//    left behind when the public API grew."
const llmsByPackage = {
    'Rasuvaeff\\Yii3Mcp': join(pkgDir, 'llms.txt'),
    'Rasuvaeff\\Yii3McpAuditLogBridge': join(pkgDir, 'docs', 'siblings', 'yii3-mcp-audit-log-bridge', 'llms.txt'),
    'Rasuvaeff\\Yii3McpRbacBridge': join(pkgDir, 'docs', 'siblings', 'yii3-mcp-rbac-bridge', 'llms.txt'),
    'Rasuvaeff\\Yii3McpTelemetryBridge': join(pkgDir, 'docs', 'siblings', 'yii3-mcp-telemetry-bridge', 'llms.txt'),
}
// Monorepo-local fallback when siblings/ was not populated (local dev).
const monorepoFallback = {
    'Rasuvaeff\\Yii3McpAuditLogBridge': join(dirname(pkgDir), 'yii3-mcp-audit-log-bridge', 'llms.txt'),
    'Rasuvaeff\\Yii3McpRbacBridge': join(dirname(pkgDir), 'yii3-mcp-rbac-bridge', 'llms.txt'),
    'Rasuvaeff\\Yii3McpTelemetryBridge': join(dirname(pkgDir), 'yii3-mcp-telemetry-bridge', 'llms.txt'),
}

for (const entry of apiClasses) {
    const name = shortName(entry.class)
    let llmsPath = llmsByPackage[entry.package]
    if (!existsSync(llmsPath) && monorepoFallback[entry.package]) {
        llmsPath = monorepoFallback[entry.package]
    }
    if (!existsSync(llmsPath)) continue // sibling not checked out — nothing to check against
    const llms = readFileSync(llmsPath, 'utf8')
    if (!llms.includes(name)) {
        fail(`${entry.package}'s llms.txt does not mention "@api" class "${name}" — update llms.txt when the public API changes.`)
    }
}

if (errors.length > 0) {
    console.error(`docs integrity check found ${errors.length} problem(s):\n`)
    for (const error of errors) {
        console.error(`  - ${error}`)
    }
    process.exit(1)
}

console.log(`docs integrity check passed: ${apiClasses.length} @api classes, all links resolve.`)
