**Confluence documentation** (when QA references a wiki page, or the
   ticket links one — a `*.atlassian.net/wiki/...pages/<id>` URL, a
   `?pageId=<id>` link, or a bare page id). Always invoke acli through
   `env DBUS_SESSION_BUS_ADDRESS=unix:path=/tmp/pablo-nokeyring acli …` —
   acli reads a secret from the Secret Service keyring on startup, and when
   the login keyring is locked it blocks on an unanswered unlock prompt and
   times out; pointing DBUS_SESSION_BUS_ADDRESS at a non-existent socket
   makes it fall back to the file-based provider instead.
   - Page:
     `env DBUS_SESSION_BUS_ADDRESS=unix:path=/tmp/pablo-nokeyring acli confluence page view --id <id> --json --body-format storage`. The
     `body.storage.value` is XHTML (Confluence storage format); use it for
     context, do not echo it into the plan.