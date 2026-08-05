import { readFileSync, readdirSync } from 'node:fs'
import { dirname, join, extname } from 'node:path'
import { fileURLToPath } from 'node:url'

// Runs AFTER `vitepress build` (unlike check-integrity.mjs, which runs
// before it): a markdown link's PAGE half can be validated against the
// source tree, but the FRAGMENT half only exists once VitePress has
// generated real heading ids — hand-guessing a slug from a heading with
// backticks/colons/punctuation is exactly how these drift silently.

const scriptsDir = dirname(fileURLToPath(import.meta.url))
const docsDir = join(scriptsDir, '..')
const distDir = join(docsDir, '.vitepress', 'dist')

function collectMarkdownFiles(dir) {
    const results = []
    for (const entry of readdirSync(dir, { withFileTypes: true })) {
        if (['siblings', 'node_modules', '.vitepress'].includes(entry.name)) continue
        const path = join(dir, entry.name)
        if (entry.isDirectory()) {
            results.push(...collectMarkdownFiles(path))
        } else if (extname(entry.name) === '.md') {
            results.push(path)
        }
    }
    return results
}

function distPathFor(pagePath) {
    // pagePath is absolute, e.g. /api/index or /security (cleanUrls, no .md)
    const withoutLeadingSlash = pagePath.replace(/^\//, '')
    const candidate = withoutLeadingSlash === '' ? 'index' : withoutLeadingSlash
    return join(distDir, candidate + '.html')
}

const errors = []
const idCache = new Map()

function idsFor(pagePath) {
    if (idCache.has(pagePath)) return idCache.get(pagePath)

    let ids = null
    try {
        const html = readFileSync(distPathFor(pagePath), 'utf8')
        ids = new Set([...html.matchAll(/\sid="([^"]+)"/g)].map((m) => m[1]))
    } catch {
        ids = null // page itself doesn't exist — check-integrity.mjs already reports this
    }

    idCache.set(pagePath, ids)

    return ids
}

const linkPattern = /\]\((\/[^)#\s]+)#([^)\s]+)\)/g

for (const file of collectMarkdownFiles(docsDir)) {
    const content = readFileSync(file, 'utf8')

    for (const match of content.matchAll(linkPattern)) {
        const [, target, fragment] = match
        const ids = idsFor(target)

        if (ids !== null && !ids.has(fragment)) {
            errors.push(`${file.replace(docsDir + '/', '')} links to "${target}#${fragment}", but that page has no heading with id "${fragment}".`)
        }
    }
}

// A raw HTML <a href="/..."> (in markdown OR a theme .vue component) does not
// get VitePress's `base` prefix — only markdown links and router-aware
// components do (see check-integrity.mjs's markdown-only version of this
// check). A theme component's mistake only shows up here, in the RENDERED
// output, because a static grep on a .vue file can't tell a raw string
// literal apart from a `withBase()`-wrapped one. Caught live once already:
// the footer's nav links pointed at "/security" instead of "/yii3-mcp/security".
function collectDistHtmlFiles(dir) {
    const results = []
    for (const entry of readdirSync(dir, { withFileTypes: true })) {
        const path = join(dir, entry.name)
        if (entry.isDirectory()) {
            results.push(...collectDistHtmlFiles(path))
        } else if (extname(entry.name) === '.html') {
            results.push(path)
        }
    }
    return results
}

const BASE = '/yii3-mcp/'
const hrefPattern = /\shref="(\/[^"]*)"/g

for (const file of collectDistHtmlFiles(distDir)) {
    const content = readFileSync(file, 'utf8')

    for (const match of content.matchAll(hrefPattern)) {
        const href = match[1]
        if (href.startsWith('//')) continue // protocol-relative external URL
        if (href.startsWith(BASE) || href === BASE.slice(0, -1)) continue

        errors.push(`${file.replace(distDir + '/', '')} renders an internal link "${href}" missing the "${BASE}" base prefix — a raw <a href> (markdown or a theme .vue component) bypassed VitePress's base rewriting.`)
    }
}

if (errors.length > 0) {
    console.error(`anchor check found ${errors.length} problem(s):\n`)
    for (const error of errors) {
        console.error(`  - ${error}`)
    }
    process.exit(1)
}

console.log('anchor check passed: every #fragment link resolves to a real heading id, and every internal href carries the site base.')
