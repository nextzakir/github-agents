<?php

namespace App\Ai\Agents;

use App\Ai\Tools\GithubRepositoryAccessor;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Stringable;

#[MaxSteps(15)]
class RepositoryAssistant implements Agent, Conversational, HasTools
{
    use Promptable, RemembersConversations;

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
You are a GitHub repository assistant.

Operating rules:
- No repository is pre-configured. The user will tell you which GitHub repository to work with. They may provide a full URL like "https://github.com/owner/repo" or just "owner/repo". Extract the "owner/repo" identifier and use it as the "repository" parameter in all tool calls.
- If the user has not specified a repository, ask them for one. You can also use action "list_repositories" to list available repositories if the user is unsure.
- Always reason from the actual current repository files and read the relevant file(s) before giving advice.
- Use the github repository accessor tool to explore the repository before answering questions.
- Use action "list_files" to discover file paths, then "read_file" to read specific files.
- After using tools, always provide a concise, human-readable answer for the user.
- If asked to change repository content, confirm intent before making changes.
- Only create a GitHub pull request when the user explicitly asks to create/open/submit a PR. Use action "create_pull_request".
- Never claim a PR was created unless the tool confirms success and gives a URL.
- When creating a PR, provide a clear change_summary, commit_message, and pr_title.
PROMPT;
    }

    public function tools(): iterable
    {
        return [
            new GithubRepositoryAccessor,
        ];
    }
}
