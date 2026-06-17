# github-agents

An AI-powered GitHub repository assistant built with Laravel 13 and the Laravel AI SDK. Chat with an AI agent that can read, list, and modify files in your GitHub repositories — either from the command line or via an OpenAI-compatible REST API.

## Features

- **AI chat over any GitHub repository** — Ask questions, explore code, request changes
- **CLI interface** — Interactive chat with slash commands (`/files`, `/file`, `/provider`, etc.)
- **OpenAI-compatible API** — Drop-in replacement for `POST /chat/completions` and `GET /models`
- **Multi-provider** — Works with OpenAI, Gemini, Anthropic, and any provider supported by `laravel/ai`
- **Provider/model failover** — Automatically retries with fallback models on rate limits or errors
- **Conversation memory** — Continues context across turns; resume previous conversations by ID
- **GitHub tool** — The agent can list repositories, browse files, read file contents, and create pull requests with changes
- **Conversation pruning** — Built-in Artisan command to clean up old conversation records

## Architecture

The application is built around two core components:

### RepositoryAssistant Agent (`app/Ai/Agents/RepositoryAssistant.php`)

A Laravel AI agent (max 15 steps) with a system prompt that instructs it to explore repositories via the GitHub tool before answering, confirm intent before making changes, and only create PRs when explicitly asked.

### GithubRepositoryAccessor Tool (`app/Ai/Tools/GithubRepositoryAccessor.php`)

A tool the agent can invoke with these actions:

| Action | Description |
|---|---|
| `list_repositories` | List all configured and accessible GitHub repositories |
| `list_files` | List files in a repository (with optional path prefix filter) |
| `read_file` | Read the contents of a specific file |
| `create_pull_request` | Create a branch, commit file changes, and open a PR |

Repository credentials are resolved from config (`config/github.php`) — each repo can have its own token/owner, or fall back to a default connection.

## Requirements

- PHP 8.3+
- Composer
- SQLite (default) or MySQL/PostgreSQL
- A GitHub access token
- An AI provider API key (Gemini, OpenAI, Anthropic, etc.)

## Setup

```bash
# 1. Install PHP dependencies
composer install

# 2. Configure environment
cp .env.example .env

# 3. Set your AI provider API key (at least one)
#    DEFAULT_AI_PROVIDER=gemini
#    GEMINI_API_KEY=...
#    or OPENAI_API_KEY=..., ANTHROPIC_API_KEY=...

# 4. Set your GitHub access token
#    GITHUB_ACCESS_TOKEN=github_pat_...

# 5. Generate app key and run migrations
php artisan key:generate
php artisan migrate

# 6. Install and build frontend assets (required for `composer run dev`)
npm install && npm run build
```

### Configuration

**`.env` variables:**

| Variable | Description |
|---|---|
| `DEFAULT_AI_PROVIDER` | Default AI provider (`gemini`, `openai`, `anthropic`, etc.) |
| `GEMINI_API_KEY` | Gemini API key |
| `OPENAI_API_KEY` | OpenAI API key |
| `ANTHROPIC_API_KEY` | Anthropic API key |
| `GITHUB_ACCESS_TOKEN` | GitHub personal access token |
| `DB_CONNECTION` | Database driver (defaults to `sqlite`) |

**Per-repository tokens** can be configured in `config/github.php` under the `repositories` array if you need different tokens for different repos.

## Usage

### CLI Chat

```bash
php artisan repository-assistant:chat owner/repo-name
```

This starts an interactive chat session. Type your questions or requests and the agent will use the GitHub tool to explore the repository and respond.

**Slash commands** available during the session:

| Command | Description |
|---|---|
| `/files [prefix]` | List files in the repository (optionally filtered by path) |
| `/file <path>` | Read a specific file |
| `/provider <name>` | Switch AI provider (`openai`, `gemini`, etc.) |
| `/model <name>` | Set a specific model |
| `/new` | Start a new conversation |
| `/conversations` | List recent conversations with ID and title |
| `/continue <id>` | Resume a previous conversation |
| `/latest` | Resume the most recent conversation |
| `/usage` <on\|off> | Toggle token usage display |
| `/status` | Show current session state |
| `/id` | Show current conversation ID |
| `/clear` or `/cls` | Clear the terminal |
| `/help` | List all commands |
| `/exit` or `/quit` | End the session |

**Options:**

```bash
php artisan repository-assistant:chat owner/repo-name \
  --provider=gemini \
  --model=gemini-2.5-flash \
  --conversation=uuid \
  --latest \
  --show-usage \
  --timeout=120
```

### API

The API is compatible with the OpenAI chat completions format.

```bash
# List available models
GET /api/v1/{owner/repo}/models

# Chat with the repository assistant
POST /api/v1/{owner/repo}/chat/completions
Content-Type: application/json

{
  "messages": [
    {"role": "user", "content": "What does this project do?"}
  ],
  "model": "gemini-2.5-flash",
  "provider": "gemini",
  "conversation_id": null,
  "timeout": 90
}
```

**Response:**

```json
{
  "id": "chatcmpl-...",
  "object": "chat.completion",
  "created": 1700000000,
  "model": "gemini:gemini-2.5-flash",
  "choices": [
    {
      "index": 0,
      "message": {
        "role": "assistant",
        "content": "..."
      },
      "finish_reason": "stop"
    }
  ],
  "usage": {
    "prompt_tokens": 100,
    "completion_tokens": 50,
    "total_tokens": 150
  },
  "conversation_id": "uuid"
}
```

Pass `conversation_id` in subsequent requests to continue the same conversation.

```bash
# List recent conversations (paginated, custom extension)
GET /api/v1/{owner/repo}/conversations?page=1&per_page=20
```

**Response:**

```json
{
  "data": [
    {
      "id": "uuid",
      "title": "Conversation title",
      "updated_at": "2026-06-17T12:00:00.000000Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 1,
    "last_page": 1
  }
}
```

<Note>
The `/conversations` endpoint is a custom extension and is not part of the OpenAI API specification.
</Note>

### Maintenance

```bash
# Prune old conversations (default: older than 30 days)
php artisan agent-conversations:prune

# Dry run to see what would be deleted
php artisan agent-conversations:prune --dry-run

# Custom age threshold
php artisan agent-conversations:prune --days=7
```

### Dev Server

```bash
composer run dev
```

This starts the Laravel dev server, queue listener, log tailer, and Vite HMR concurrently.

## Tests

```bash
composer test
```

The test suite uses PHPUnit. Run specific tests with:

```bash
php artisan test --compact tests/Feature/...
php artisan test --compact --filter=test_name
```

## How it Works

1. You make a request (CLI or API) with a repository identifier like `owner/repo`
2. The `RepositoryAssistant` agent begins a conversation with the Laravel AI SDK
3. On the first turn, the agent receives context about the target repository (discovered starter files)
4. The agent decides when to invoke the `GithubRepositoryAccessor` tool to list files, read files, or prepare a PR
5. The agent reasons from the actual file contents and provides you a human-readable answer
6. The conversation is persisted so you can resume later
7. Provider/model failover handles rate limits and transient errors gracefully
