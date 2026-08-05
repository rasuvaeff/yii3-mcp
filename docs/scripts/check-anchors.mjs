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

if (errors.length > 0) {
    console.error(`anchor check found ${errors.length} problem(s):\n`)
    for (const error of errors) {
        console.error(`  - ${error}`)
    }
    process.exit(1)
}

console.log('anchor check passed: every #fragment link resolves to a real heading id.')
