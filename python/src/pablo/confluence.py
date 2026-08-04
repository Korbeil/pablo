"""Confluence documentation access, backed by the ``acli`` CLI.

PABLO's documentation capability goes through `acli confluence page view …`
(OAuth owned and cached by acli itself — ``acli auth login``; PABLO stores
no tokens). Used by the ``pablo docs`` subcommand (``/pablo-docs``) and
directly by interactive agents asked to read a Confluence page referenced by
an issue.

Storage-format body (XHTML with Confluence macros) is returned verbatim;
rendering it to Markdown is left to the caller (agents tolerate raw XHTML;
the ``pablo docs`` CLI prints it as-is).
"""

from __future__ import annotations

import json
import re
from dataclasses import dataclass

from pablo import PabloError
from pablo.config import ProjectConfig
from pablo.providers import run_cli

CONFLUENCE_CALL_TIMEOUT_S = 30

# Accept either a cloud wiki URL (with or without a trailing title slug)…
_WIKI_PAGES_RE = re.compile(
    r"https?://[^/]+/wiki(?:/spaces/[^/]+)?/pages/(?P<id>\d+)"
)
# …or a ?pageId=<id> query form.
_PAGEID_QUERY_RE = re.compile(r"[?&]pageId=(?P<id>\d+)")


@dataclass(frozen=True)
class ConfluencePage:
    id: str
    title: str
    url: str
    body: str  # storage-format XHTML (see module docstring)


def match_url(url: str) -> str | None:
    """Extract a Confluence page id from a pasted URL, or None.

    Accepts both ``…/wiki/spaces/<KEY>/pages/<id>[/Title]`` and
    ``…/wiki?pageId=<id>`` forms. A bare numeric string is also accepted by
    the caller (``fetch``) as an id directly.
    """
    for pattern in (_WIKI_PAGES_RE, _PAGEID_QUERY_RE):
        match = pattern.search(url)
        if match:
            return match.group("id")
    return None


def fetch(page_id_or_url: str, cfg: ProjectConfig) -> ConfluencePage:
    """Fetch a Confluence page by id or URL.

    A bare numeric id is passed straight through; otherwise the id is parsed
    out of a Confluence wiki URL. When ``cfg.confluence_space`` is set and the
    page is fetched by URL, the URL's space (when present) is not enforced
    here — acli's own OAuth scope is the gatekeeper; the config space is
    advisory and surfaced to agents for discovery.
    """
    if not page_id_or_url.strip():
        raise PabloError("confluence: no page id or url given")
    page_id = page_id_or_url
    if not page_id.isdigit():
        parsed = match_url(page_id_or_url)
        if parsed is None:
            raise PabloError(
                f"confluence: not a page id or Confluence URL: {page_id_or_url!r}"
            )
        page_id = parsed

    out = run_cli(
        ["acli", "confluence", "page", "view", "--id", page_id,
         "--json", "--body-format", "storage"],
        timeout=CONFLUENCE_CALL_TIMEOUT_S,
    )
    try:
        data = json.loads(out)
    except json.JSONDecodeError as exc:
        raise PabloError(f"confluence: acli returned non-JSON: {exc}") from exc
    if not isinstance(data, dict) or "id" not in data:
        raise PabloError(f"confluence: unexpected acli response: {out[:200]}")

    links = data.get("_links") or {}
    base = links.get("base") or ""
    webui = links.get("webui") or ""
    url = f"{base}{webui}" if base or webui else ""
    body = (data.get("body") or {}).get("storage", {}).get("value", "") or ""
    return ConfluencePage(
        id=str(data["id"]),
        title=data.get("title", "") or "",
        url=url,
        body=body,
    )


def cli_name() -> str:
    return "acli"


def auth_check_cmd() -> list[str]:
    return ["acli", "confluence", "auth", "status"]