<?php

declare(strict_types=1);

namespace Pablo\Yolo;

use Pablo\Agents\AgentTemplateError;
use Pablo\Agents\AgentTemplates;
use Pablo\Agents\Runtime\AgentTranslation;
use Pablo\Support\PabloError;
use Pablo\Support\PabloPaths;

/**
 * The prompts of YOLO mode's two follow-up runs. Plain text, whatever the
 * runtime: the commit run gets /pablo-commit-and-pr's own body (read from
 * the bundled command, so the two can never drift), not a slash command —
 * `opencode run` cannot run one in a resumed session.
 */
final class YoloPrompts
{
    public const COMMIT_COMMAND = 'pablo-commit-and-pr';

    /** Said first in both prompts: nobody watches these runs. */
    private const UNATTENDED = "You run unattended (PABLO YOLO mode): nobody will answer a question or\n"
        ."approve anything. Never stop to ask: when something is ambiguous, take\n"
        ."the most reasonable decision and say which in your final message.\n";

    /**
     * Step 1: carry out the plan. $statePrompt is the state agent's own
     * prompt, for a session that holds no plan (an ADE that could not hand
     * the session over): then analyse first.
     */
    public function implement(string $statePrompt): string
    {
        return "# PABLO YOLO mode — step 1 of 2: implement\n\n"
            .self::UNATTENDED."\n"
            ."Execute the action plan from the analysis above, in this session,\n"
            ."following its \"▶️ Execution protocol\": make the code changes, add or\n"
            ."update the tests, run the project's QA (tests, static analysis, coding\n"
            ."standards) and fix what it reports.\n\n"
            ."Rules:\n"
            ."- Never `git commit`, `git push`, or create a pull request: the next step\n"
            ."  does that with /pablo-commit-and-pr. Leave the changes uncommitted.\n"
            ."- Never touch the git identity (`git config`, `-c user.*`), never switch,\n"
            ."  reset, rebase or stash the branch, never write to the issue tracker or\n"
            ."  comment on the pull request.\n"
            ."- When the plan has open questions, pick the safest reasonable answer.\n"
            ."- When there is nothing to change (the plan concludes no code change is\n"
            ."  needed), change nothing and say so.\n\n"
            ."If this session holds no action plan, first analyse the task — {$statePrompt}\n"
            ."— then implement your plan the same way.\n\n"
            ."End with a short summary of what changed and the QA results.\n";
    }

    /**
     * Step 2: /pablo-commit-and-pr, run unattended.
     *
     * @throws PabloError when the bundled command cannot be read
     */
    public function commit(): string
    {
        $path = AgentTemplates::commandsDirFor(PabloPaths::resourcesDir().'/opencode/agents').'/'.self::COMMIT_COMMAND.'.md';
        try {
            [, , $body] = AgentTranslation::readCommand($path);
        } catch (AgentTemplateError $e) {
            throw new PabloError('YOLO mode: '.$e->getMessage());
        }
        $body = AgentTranslation::withContextToGather($body, 'procedure');
        $body = str_replace(
            ['User request: $ARGUMENTS', '`$ARGUMENTS`'],
            ['User request: none (YOLO mode: commit and push the work of this session).', 'the user\'s request'],
            $body,
        );

        return "# PABLO YOLO mode — step 2 of 2: commit and pull request\n\n"
            .self::UNATTENDED."\n"
            ."Run PABLO's `/".self::COMMIT_COMMAND."` procedure below, exactly as written,\n"
            ."for the changes the previous step left in the worktree. Do not change any\n"
            ."code now: only commit what is there (never `git add -A` blindly). When the\n"
            ."procedure says to stop, stop and report why.\n\n"
            .'---'."\n\n"
            .trim($body)."\n";
    }
}
