<?php

namespace App\Console\Commands;

use App\Ai\Agents\RepositoryAssistant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\Agent;
use Throwable;

use function Laravel\Prompts\spin;
use function Laravel\Prompts\text;

class RepositoryAssistantChat extends Command
{
    protected $signature = 'repository-assistant:chat
        {--conversation= : Continue a specific conversation UUID}
        {--latest : Continue the most recent conversation}
        {--provider= : AI provider name, e.g. openai or gemini}
        {--model= : Explicit model name}
        {--timeout=90 : Request timeout in seconds}
        {--show-usage : Show token usage after each response}';

    protected $description = 'Chat with a repository-scoped AI assistant.';

    public function handle(): int
    {
        $agent = new RepositoryAssistant;

        $user = User::firstOrCreate(
            ['email' => 'cli@system.local'],
            ['name' => 'CLI Admin', 'password' => bcrypt(str()->random(32))]
        );

        $conversationId = $this->option('conversation');
        $provider = $this->stringOption('provider');
        $model = $this->stringOption('model');
        $timeout = $this->intOption('timeout', 90);
        $showUsage = (bool) $this->option('show-usage');
        $continueLatest = (bool) $this->option('latest');

        $agentClass = RepositoryAssistant::class;

        if ((! is_string($conversationId) || $conversationId === '') && $continueLatest) {
            $conversationId = $this->latestConversationIdForAgent($user->id, $agentClass);
        }

        if (is_string($conversationId) && $conversationId !== '') {
            $agent->continue($conversationId, as: $user);
            $this->info("Continuing conversation: {$conversationId}");
        } else {
            $agent->forUser($user);
            $this->info('Started new conversation.');
        }

        $this->line("Agent: {$agentClass}");
        $this->line('Type `/help` for commands. Type `/exit` to stop.');

        while (true) {
            $input = trim(text('You'));

            if ($input === '') {
                continue;
            }

            if (in_array(strtolower($input), ['exit', 'quit'], true)) {
                break;
            }

            if (str_starts_with($input, '/')) {
                $shouldContinue = $this->handleSlashCommand(
                    $input,
                    $agent,
                    $user,
                    $conversationId,
                    $provider,
                    $model,
                    $showUsage,
                    $agentClass,
                );

                if (! $shouldContinue) {
                    break;
                }

                continue;
            }

            try {
                $response = spin(
                    fn () => $this->promptWithProviderModelFallback(
                        agent: $agent,
                        prompt: $input,
                        requestedProvider: $provider,
                        requestedModel: $model,
                        timeout: $timeout,
                    ),
                    'Thinking...'
                );
            } catch (Throwable $e) {
                $this->error("AI request failed: {$e->getMessage()}");
                $this->line('Try: set provider with `/provider gemini` (or `/provider openai`) and confirm API key env vars.');

                continue;
            }

            if (is_string($response->conversationId) && $response->conversationId !== '') {
                $conversationId = $response->conversationId;
                $agent->continue($conversationId, as: $user);
            }

            $assistantText = $this->extractResponseText($response);

            if ($assistantText === '') {
                $this->renderAssistantMessage(
                    'No text content was returned by the AI provider. Try a different provider/model with `/provider` or `/model`.',
                    'Assistant'
                );

                continue;
            }

            $this->renderAssistantMessage($assistantText);

            if ($showUsage) {
                $promptTokens = $response->usage->promptTokens;
                $completionTokens = $response->usage->completionTokens;
                $reasoningTokens = $response->usage->reasoningTokens;
                $this->line("Usage: prompt={$promptTokens}, completion={$completionTokens}, reasoning={$reasoningTokens}");
            }
        }

        if (is_string($conversationId) && $conversationId !== '') {
            $this->line("Conversation ID: {$conversationId}");
        }

        return Command::SUCCESS;
    }

    protected function handleSlashCommand(
        string $input,
        object $agent,
        User $user,
        ?string &$conversationId,
        string &$provider,
        string &$model,
        bool &$showUsage,
        string $agentClass,
    ): bool {
        $parts = preg_split('/\s+/', $input, 2);
        $command = strtolower($parts[0] ?? '');
        $argument = trim($parts[1] ?? '');

        if (in_array($command, ['/exit', '/quit'], true)) {
            return false;
        }

        if ($command === '/help') {
            $this->line('/help');
            $this->line('/exit or /quit');
            $this->line('/status');
            $this->line('/id');
            $this->line('/new');
            $this->line('/conversations');
            $this->line('/continue <conversation-id>');
            $this->line('/latest');
            $this->line('/provider <name|none>');
            $this->line('/model <name|none>');
            $this->line('/usage <on|off>');
            $this->line('/clear or /cls');

            return true;
        }

        if ($command === '/status') {
            $activeConversation = $conversationId !== '' ? $conversationId : 'none';
            $activeProvider = $provider !== '' ? $provider : 'default';
            $activeModel = $model !== '' ? $model : 'default';
            $usageState = $showUsage ? 'on' : 'off';

            $this->line('Agent: '.RepositoryAssistant::class);
            $this->line("Conversation: {$activeConversation}");
            $this->line("Provider: {$activeProvider}");
            $this->line("Model: {$activeModel}");
            $this->line("Usage display: {$usageState}");

            return true;
        }

        if ($command === '/id') {
            $this->line($conversationId !== '' ? "Conversation ID: {$conversationId}" : 'Conversation ID: none');

            return true;
        }

        if ($command === '/new') {
            $conversationId = null;
            $agent->forUser($user);
            $this->info('Started a new conversation.');

            return true;
        }

        if ($command === '/conversations') {
            $conversations = DB::table('agent_conversations')
                ->where('user_id', $user->id)
                ->orderByDesc('updated_at')
                ->limit(20)
                ->get(['id', 'title', 'updated_at']);

            if ($conversations->isEmpty()) {
                $this->info('No conversations found.');

                return true;
            }

            $this->newLine();
            $this->line('<fg=cyan;options=bold>Recent Conversations</>');
            $this->line(str_repeat('-', 72));

            foreach ($conversations as $conversation) {
                $id = (string) $conversation->id;
                $title = (string) $conversation->title;
                $time = (string) $conversation->updated_at;
                $this->line(sprintf('<fg=green>%-36s</> <fg=yellow>%s</>', $id, $time));
                $this->line('  '.wordwrap($title, 68, "\n  "));
                $this->line('');
            }

            $this->line('Use <fg=cyan>/continue &lt;conversation-id&gt;</> to resume a conversation.');

            return true;
        }

        if ($command === '/continue') {
            if ($argument === '') {
                $this->error('Usage: /continue <conversation-id>');

                return true;
            }

            $conversationId = $argument;
            $agent->continue($conversationId, as: $user);
            $this->info("Continuing conversation: {$conversationId}");

            return true;
        }

        if ($command === '/latest') {
            $lastConversationId = $this->latestConversationIdForAgent($user->id, $agentClass);

            if ($lastConversationId === null) {
                $this->error('No previous conversation found for this assistant.');

                return true;
            }

            $conversationId = $lastConversationId;
            $agent->continue($conversationId, as: $user);
            $this->info("Continuing last conversation: {$conversationId}");

            return true;
        }

        if ($command === '/provider') {
            if ($argument === '' || strtolower($argument) === 'none') {
                $provider = '';
                $this->info('Provider reset to default.');

                return true;
            }

            $provider = $argument;
            $this->info("Provider set to: {$provider}");

            return true;
        }

        if ($command === '/model') {
            if ($argument === '' || strtolower($argument) === 'none') {
                $model = '';
                $this->info('Model reset to provider default.');

                return true;
            }

            $model = $argument;
            $this->info("Model set to: {$model}");

            return true;
        }

        if ($command === '/usage') {
            if (in_array(strtolower($argument), ['on', 'true', '1'], true)) {
                $showUsage = true;
                $this->info('Usage display enabled.');

                return true;
            }

            if (in_array(strtolower($argument), ['off', 'false', '0'], true)) {
                $showUsage = false;
                $this->info('Usage display disabled.');

                return true;
            }

            $this->error('Usage: /usage <on|off>');

            return true;
        }

        if (in_array($command, ['/files', '/file'], true)) {
            $this->error('No repository has been set. Ask the agent to work with a repository first (e.g., \'look at https://github.com/owner/repo\').');

            return true;
        }

        if (in_array($command, ['/clear', '/cls'], true)) {
            $this->clearTerminal();

            return true;
        }

        $this->error("Unknown command: {$command}. Use /help.");

        return true;
    }

    protected function stringOption(string $name): string
    {
        $value = $this->option($name);

        return is_string($value) ? trim($value) : '';
    }

    protected function intOption(string $name, int $default): int
    {
        $value = $this->option($name);

        if (is_numeric($value)) {
            return max(1, (int) $value);
        }

        return $default;
    }

    protected function resolveEffectiveProvider(string $requestedProvider): string
    {
        if ($requestedProvider !== '') {
            return $requestedProvider;
        }

        $defaultProvider = config('ai.default');

        return is_string($defaultProvider) ? trim($defaultProvider) : '';
    }

    /**
     * @return array<int, string>
     */
    protected function resolveFallbackModels(string $provider, string $requestedModel): array
    {
        if ($requestedModel !== '') {
            return [$requestedModel];
        }

        $configured = config('ai.provider_failover.'.$provider);

        if (! is_array($configured)) {
            return [];
        }

        $models = [];

        foreach ($configured as $model) {
            if (is_string($model) && trim($model) !== '') {
                $models[] = trim($model);
            }
        }

        return array_values(array_unique($models));
    }

    protected function promptWithProviderModelFallback(
        Agent $agent,
        string $prompt,
        string $requestedProvider,
        string $requestedModel,
        int $timeout,
    ): mixed {
        $provider = $this->resolveEffectiveProvider($requestedProvider);

        if ($provider === '') {
            return $agent->prompt(
                $prompt,
                provider: null,
                model: $requestedModel !== '' ? $requestedModel : null,
                timeout: $timeout,
            );
        }

        $modelCandidates = $this->resolveFallbackModels($provider, $requestedModel);

        if ($modelCandidates === []) {
            return $agent->prompt(
                $prompt,
                provider: $provider,
                model: $requestedModel !== '' ? $requestedModel : null,
                timeout: $timeout,
            );
        }

        $lastError = null;

        foreach ($modelCandidates as $modelCandidate) {
            try {
                return $agent->prompt(
                    $prompt,
                    provider: $provider,
                    model: $modelCandidate,
                    timeout: $timeout,
                );
            } catch (Throwable $e) {
                if (! $this->isRetryableAiFailure($e)) {
                    throw $e;
                }

                $lastError = $e;
            }
        }

        if ($lastError instanceof Throwable) {
            throw $lastError;
        }

        return $agent->prompt(
            $prompt,
            provider: $provider,
            model: null,
            timeout: $timeout,
        );
    }

    protected function isRetryableAiFailure(Throwable $error): bool
    {
        $code = $error->getCode();
        $message = strtolower($error->getMessage());

        if (is_int($code) && ($code === 429 || $code >= 500)) {
            return true;
        }

        return str_contains($message, 'rate limit')
            || str_contains($message, 'too many requests')
            || str_contains($message, 'resource exhausted')
            || str_contains($message, 'temporarily unavailable')
            || str_contains($message, 'overloaded')
            || str_contains($message, 'timeout')
            || str_contains($message, 'deadline exceeded');
    }

    protected function renderAssistantMessage(string $message, string $title = 'Assistant'): void
    {
        $this->newLine();
        $this->line("<fg=cyan;options=bold>[$title]</>");

        $inCodeBlock = false;

        foreach (preg_split('/\R/', $message) ?: [] as $line) {
            $trimmed = trim($line);

            if ($trimmed === '```') {
                $inCodeBlock = ! $inCodeBlock;
                $this->line($inCodeBlock ? '<fg=gray>┌─ code</>' : '<fg=gray>└─ end</>');

                continue;
            }

            if ($inCodeBlock) {
                $this->line('<fg=gray>  '.$this->escapeForConsole($line).'</>');

                continue;
            }

            if (str_starts_with($trimmed, '### ')) {
                $this->line('<fg=magenta;options=bold>'.$this->escapeForConsole(substr($trimmed, 4)).'</>');

                continue;
            }

            if (str_starts_with($trimmed, '## ')) {
                $this->line('<fg=blue;options=bold>'.$this->escapeForConsole(substr($trimmed, 3)).'</>');

                continue;
            }

            if (str_starts_with($trimmed, '# ')) {
                $this->line('<fg=yellow;options=bold>'.$this->escapeForConsole(substr($trimmed, 2)).'</>');

                continue;
            }

            if (preg_match('/^\\*\\s+(.+)$/', $trimmed, $matches) === 1 || preg_match('/^-\\s+(.+)$/', $trimmed, $matches) === 1) {
                $this->line('<fg=green>•</> '.$this->escapeForConsole($matches[1]));

                continue;
            }

            if (preg_match('/^(\\d+)\\.\\s+(.+)$/', $trimmed, $matches) === 1) {
                $this->line('<fg=yellow>'.$matches[1].'.</> '.$this->escapeForConsole($matches[2]));

                continue;
            }

            $this->line($this->escapeForConsole($line));
        }

        $this->newLine();
    }

    protected function escapeForConsole(string $text): string
    {
        return str_replace(['<', '>'], ['\\<', '\\>'], $text);
    }

    protected function clearTerminal(): void
    {
        $this->output->write("\033[2J\033[H\033[3J");
    }

    protected function extractResponseText(mixed $response): string
    {
        $stringResponse = trim((string) $response);

        if ($stringResponse !== '') {
            return $stringResponse;
        }

        $text = data_get($response, 'text');

        if (is_string($text) && trim($text) !== '') {
            return trim($text);
        }

        $content = data_get($response, 'content');

        if (is_string($content) && trim($content) !== '') {
            return trim($content);
        }

        $stepTexts = [];
        $steps = data_get($response, 'steps');

        if (is_iterable($steps)) {
            foreach ($steps as $step) {
                $stepText = trim((string) data_get($step, 'text', ''));

                if ($stepText !== '') {
                    $stepTexts[] = $stepText;
                }
            }
        }

        if ($stepTexts !== []) {
            return implode("\n\n", array_values(array_unique($stepTexts)));
        }

        $toolSummaries = [];
        $toolResults = data_get($response, 'toolResults');

        if (is_iterable($toolResults)) {
            foreach ($toolResults as $toolResult) {
                $name = trim((string) data_get($toolResult, 'name', 'tool'));
                $rawResult = data_get($toolResult, 'result');
                $resultText = is_scalar($rawResult)
                    ? trim((string) $rawResult)
                    : (is_object($rawResult) && method_exists($rawResult, '__toString') ? trim((string) $rawResult) : '');

                if ($name === 'GithubRepositoryAccessor' && $resultText !== '') {
                    $decoded = json_decode($resultText, true);
                    $files = is_array($decoded) ? ($decoded['files'] ?? null) : null;

                    if (is_array($files)) {
                        $sampleFiles = [];

                        foreach ($files as $file) {
                            if (is_string($file) && trim($file) !== '') {
                                $sampleFiles[] = trim($file);
                            }
                        }

                        $sampleFiles = array_slice($sampleFiles, 0, 8);
                        $toolSummaries[] = $sampleFiles === []
                            ? 'I inspected the repository file tree.'
                            : 'I inspected the repository file tree. Example files: '.implode(', ', $sampleFiles).'.';

                        continue;
                    }

                    if ($resultText !== '') {
                        $toolSummaries[] = 'I read repository content via GitHub and can now answer your question.';

                        continue;
                    }
                }

                if ($name !== '') {
                    $toolSummaries[] = "I executed tool {$name} to gather repository context.";
                }
            }
        }

        if ($toolSummaries !== []) {
            return implode("\n", array_values(array_unique($toolSummaries)));
        }

        return '';
    }

    protected function latestConversationIdForAgent(int $userId, string $agentClass): ?string
    {
        $conversationId = DB::table('agent_conversation_messages')
            ->where('user_id', $userId)
            ->where('agent', $agentClass)
            ->orderByDesc('updated_at')
            ->value('conversation_id');

        return is_string($conversationId) && $conversationId !== '' ? $conversationId : null;
    }
}
