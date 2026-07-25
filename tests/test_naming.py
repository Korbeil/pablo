from pablo import naming


def test_branch_name_lowercases():
    assert naming.branch_name("XXX", "123") == "xxx-123"
    assert naming.branch_name("WK", "45") == "wk-45"


def test_slug_branch_truncates_and_sanitizes():
    assert (
        naming.slug_branch("WK", "Fix the callback verification bug!")
        == "wk-fix-the-callback-verification"
    )


def test_slug_branch_short_prompt():
    assert naming.slug_branch("XXX", "Refactor auth") == "oms-refactor-auth"


def test_slug_branch_strips_symbols():
    assert naming.slug_branch("A", "Émit weird  ---   chars?!") == "a-emit-weird-chars"


def test_dedupe_returns_base_when_free():
    assert naming.dedupe("xxx-123", set()) == "xxx-123"


def test_dedupe_appends_suffixes():
    assert naming.dedupe("xxx-123", {"xxx-123"}) == "xxx-123-2"
    assert naming.dedupe("xxx-123", {"xxx-123", "xxx-123-2"}) == "xxx-123-3"
