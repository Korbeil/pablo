<?php

declare(strict_types=1);

namespace Pablo\Provider\Forge;

/**
 * The review verdicts a forge's reviewVerdict() answers (null: no verdict
 * yet). Each forge evaluates them by its own rules: GitHub through
 * Github\GithubReviewVerdict, GitLab through Gitlab\GitlabReviewVerdict.
 */
final class ReviewVerdict
{
    public const APPROVED = 'approved';
    public const CHANGES = 'changes';
}
