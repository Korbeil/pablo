mode: primary
model: litellm/glm-5.3-flash
permissions:
  - action: edit
    resource: "*"
    effect: allow
  - action: read
    resource: "*"
    effect: allow
  - action: shell
    resource: "*"
    effect: allow
  - action: shell
    resource: "gh pr comment*"
    effect: deny
  - action: shell
    resource: "gh pr edit*"
    effect: deny
  - action: shell
    resource: "gh pr merge*"
    effect: deny
  - action: shell
    resource: "gh pr ready*"
    effect: deny
  - action: shell
    resource: "gh pr close*"
    effect: deny
  - action: shell
    resource: "gh pr review*"
    effect: deny
  - action: shell
    resource: "gh pr create*"
    effect: deny
  - action: shell
    resource: "gh issue edit*"
    effect: deny
  - action: shell
    resource: "gh issue close*"
    effect: deny
  - action: shell
    resource: "gh issue comment*"
    effect: deny
  - action: shell
    resource: "gh issue create*"
    effect: deny
  - action: shell
    resource: "*acli jira workitem edit*"
    effect: deny
  - action: shell
    resource: "*acli jira workitem transition*"
    effect: deny
  - action: shell
    resource: "*acli jira workitem assign*"
    effect: deny
  - action: shell
    resource: "*acli jira workitem create*"
    effect: deny
  - action: shell
    resource: "*acli jira workitem create-bulk*"
    effect: deny
  - action: shell
    resource: "*acli jira workitem comment create*"
    effect: deny
  - action: shell
    resource: "*acli jira workitem comment update*"
    effect: deny
  - action: shell
    resource: "*acli jira workitem comment delete*"
    effect: deny
  - action: shell
    resource: "*acli jira workitem watcher*"
    effect: deny
  - action: shell
    resource: "*acli jira workitem list-watchers*"
    effect: deny
  - action: shell
    resource: "*acli jira workitem link*"
    effect: deny
  - action: shell
    resource: "*acli jira workitem archive*"
    effect: deny
  - action: shell
    resource: "*acli jira workitem delete*"
    effect: deny
  - action: shell
    resource: "*acli jira workitem clone*"
    effect: deny
  - action: shell
    resource: "*acli confluence page create*"
    effect: deny
  - action: shell
    resource: "*acli confluence page update*"
    effect: deny
  - action: shell
    resource: "*acli confluence page delete*"
    effect: deny
  - action: shell
    resource: "*acli confluence space create*"
    effect: deny
  - action: shell
    resource: "*acli confluence space update*"
    effect: deny
  - action: shell
    resource: "*acli confluence space archive*"
    effect: deny
  - action: shell
    resource: "*acli confluence space restore*"
    effect: deny
  - action: shell
    resource: "*acli auth login*"
    effect: deny
  - action: shell
    resource: "*acli auth logout*"
    effect: deny
  - action: shell
    resource: "*acli auth switch*"
    effect: deny
  - action: shell
    resource: "*acli confluence auth login*"
    effect: deny
  - action: shell
    resource: "*acli confluence auth logout*"
    effect: deny
  - action: shell
    resource: "*acli jira auth login*"
    effect: deny
  - action: shell
    resource: "*acli jira auth logout*"
    effect: deny
