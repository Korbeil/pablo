**Confluence documentation** (when QA references a wiki page, or the
   ticket links one — a `*.atlassian.net/wiki/...pages/<id>` URL, a
   `?pageId=<id>` link, or a bare page id):
   `acli confluence page view --id <id> --json --body-format storage`. The
   `body.storage.value` is XHTML (Confluence storage format); use it for
   context, do not echo it into the plan.