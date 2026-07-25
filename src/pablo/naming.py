"""Branch naming convention: [project-key]-[issue-id], lowercased.

Spec: docs/specification.md "Branch naming convention". Duplicates get a
``-2``, ``-3``, ... suffix, trying each in order until a free name is found.
"""

from __future__ import annotations

import re
import unicodedata

SLUG_MAX_WORDS = 4


def branch_name(project_key: str, issue_id: str) -> str:
    return f"{project_key.lower()}-{issue_id.lower()}"


def slug_branch(project_key: str, prompt: str) -> str:
    """Branch name for a prompt-started task: key + short slug of the prompt."""
    normalized = unicodedata.normalize("NFKD", prompt).encode("ascii", "ignore").decode()
    words = [w for w in re.split(r"[^a-z0-9]+", normalized.lower()) if w]
    slug = "-".join(words[:SLUG_MAX_WORDS]) or "task"
    return f"{project_key.lower()}-{slug}"


def dedupe(base: str, taken: set[str]) -> str:
    if base not in taken:
        return base
    n = 2
    while f"{base}-{n}" in taken:
        n += 1
    return f"{base}-{n}"
