**Confluence documentation** (when the ticket references a wiki page —
   a `*.atlassian.net/wiki/...pages/<id>` URL, a `?pageId=<id>` link, or a
   bare page id): `acli confluence page view --id <id> --json --body-format storage`.
   The response's `body.storage.value` is XHTML (Confluence storage
   format); read it for context but do not echo it verbatim into the plan.
   `acli confluence space list --json` lists accessible spaces.