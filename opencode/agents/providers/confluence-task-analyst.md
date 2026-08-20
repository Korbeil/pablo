**Confluence documentation** (when the ticket references a wiki page —
   a `*.atlassian.net/wiki/...pages/<id>` URL, a `?pageId=<id>` link, or a
   bare page id). Always invoke acli through
   `env DBUS_SESSION_BUS_ADDRESS=unix:path=/tmp/pablo-nokeyring acli …` —
   acli reads a secret from the Secret Service keyring on startup, and when
   the login keyring is locked it blocks on an unanswered unlock prompt and
   times out; pointing DBUS_SESSION_BUS_ADDRESS at a non-existent socket
   makes it fall back to the file-based provider instead.
   - Page:
     `env DBUS_SESSION_BUS_ADDRESS=unix:path=/tmp/pablo-nokeyring acli confluence page view --id <id> --json --body-format storage`.
     The response's `body.storage.value` is XHTML (Confluence storage
     format); read it for context but do not echo it verbatim into the plan.
   - Spaces:
     `env DBUS_SESSION_BUS_ADDRESS=unix:path=/tmp/pablo-nokeyring acli confluence space list --json`
     lists accessible spaces.