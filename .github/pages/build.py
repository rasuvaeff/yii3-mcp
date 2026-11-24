#!/usr/bin/env python3
"""Build redirect pages from the old rasuvaeff.github.io/yii3-mcp/ addresses.

The documentation lives in rasuvaeff/mcp. GitHub Pages cannot answer 301, so
every page the old site served becomes a meta-refresh page with a canonical
link; 404.html sends any other old address to the same path of the new site.
"""

import html
import pathlib
import sys

NEW = "https://rasuvaeff.github.io/mcp/"
MOVED = {
    "framework-agnostic": "intro/getting-started",
    "intro/getting-started": "yii3/getting-started",
    "packages/core": "packages/yii3-mcp",
    "roadmap": "",
    "api/classes/InvalidPromptFileException": "api/",
    "api/classes/MarkdownPromptsConfigurator": "api/",
    "api/classes/SessionBudgetInterceptor": "api/classes/ToolCallBudgetInterceptor",
}


def page(target: str, script: str = "") -> str:
    url = html.escape(target, quote=True)

    return (
        '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Moved</title>'
        f'{script}<link rel="canonical" href="{url}"><meta http-equiv="refresh" content="0; url={url}">'
        f'</head><body><p>The documentation moved to <a href="{url}">{url}</a>.</p></body></html>\n'
    )


def main(out: pathlib.Path) -> None:
    here = pathlib.Path(__file__).parent

    for line in (here / "old-pages.txt").read_text().splitlines():
        path = line.removesuffix(".md")
        target = MOVED.get(path, path)
        target = target.removesuffix("index")
        file = out / f"{path}.html"
        file.parent.mkdir(parents=True, exist_ok=True)
        # keep the fragment: meta refresh drops it
        js = f'<script>location.replace("{NEW}{target}" + location.hash)</script>'
        file.write_text(page(NEW + target, js))

    js = f'<script>location.replace("{NEW}" + location.pathname.replace(/^\\/yii3-mcp\\//, "") + location.hash)</script>'
    (out / "404.html").write_text(page(NEW, js))


main(pathlib.Path(sys.argv[1]))
