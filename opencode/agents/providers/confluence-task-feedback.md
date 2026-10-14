**Confluence documentation** (when QA or the ticket links a wiki page — a
   `*.atlassian.net/wiki/...pages/<id>` URL, a `?pageId=<id>` link, or a
   bare page id), same `acli` prefix as Jira:
   `acli confluence page view --id <id> --json --body-format storage | jq -r '.title, .body.storage.value' | sed 's/<[^>]*>/ /g'`
   (strips the XHTML markup; use it for context, don't echo it).
