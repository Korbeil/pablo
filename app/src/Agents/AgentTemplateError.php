<?php

declare(strict_types=1);

namespace Pablo\Agents;

/**
 * A template, provider section or shared frontmatter profile needed to
 * render an opencode agent .md is missing or unreadable.
 */
final class AgentTemplateError extends \RuntimeException
{
}
