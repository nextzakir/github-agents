<?php

namespace App\Http\Controllers\RepositoryAssistants;

use App\Ai\Agents\RepositoryAssistant;
use App\Http\Controllers\Controller;
use App\Http\Requests\OpenAiChatCompletionsRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Agent;
use Throwable;

class ChatCompletionsController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(OpenAiChatCompletionsRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $prompt = $this->latestUserMessage($validated['messages']);

        if ($prompt === '') {
            return response()->json([
                'error' => [
                    'message' => 'A user message is required in messages.',
                    'type' => 'invalid_request_error',
                    'code' => 'missing_user_message',
                ],
            ], 422);
        }

        $user = User::firstOrCreate(
            ['email' => 'api@system.local'],
            ['name' => 'API Client', 'password' => bcrypt(Str::random(32))]
        );

        $agent = new RepositoryAssistant;

        $conversationId = (string) ($validated['conversation_id'] ?? '');

        if ($conversationId !== '') {
            $agent->continue($conversationId, as: $user);
        } else {
            $agent->forUser($user);
        }

        [$requestedProvider, $requestedModel] = $this->resolveProviderAndModel(
            model: (string) ($validated['model'] ?? ''),
            providerOverride: (string) ($validated['provider'] ?? ''),
        );

        try {
            [$response, $resolvedProvider, $resolvedModel] = $this->promptWithProviderModelFallback(
                agent: $agent,
                prompt: $prompt,
                requestedProvider: $requestedProvider,
                requestedModel: $requestedModel,
                timeout: (int) ($validated['timeout'] ?? 90),
            );
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }

        if (is_string($response->conversationId) && $response->conversationId !== '') {
            $conversationId = $response->conversationId;
        }

        $assistantText = $this->extractResponseText($response);

        return response()->json([
            'id' => 'chatcmpl-'.Str::ulid(),
            'object' => 'chat.completion',
            'created' => time(),
            'model' => $this->responseModelName($resolvedProvider, $resolvedModel),
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => $assistantText,
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => [
                'prompt_tokens' => $response->usage->promptTokens,
                'completion_tokens' => $response->usage->completionTokens,
                'total_tokens' => $response->usage->promptTokens + $response->usage->completionTokens,
            ],
            'conversation_id' => $conversationId,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     */
    protected function latestUserMessage(array $messages): string
    {
        for ($index = count($messages) - 1; $index >= 0; $index--) {
            $message = $messages[$index];
            $role = $message['role'] ?? null;
            $content = $message['content'] ?? null;

            if ($role === 'user' && is_string($content)) {
                return trim($content);
            }
        }

        return '';
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function resolveProviderAndModel(string $model, string $providerOverride): array
    {
        if ($providerOverride !== '') {
            return [$providerOverride, $model];
        }

        $providerNames = array_keys(config('ai.providers', []));

        if (in_array($model, $providerNames, true)) {
            return [$model, ''];
        }

        if (str_contains($model, ':')) {
            [$provider, $resolvedModel] = explode(':', $model, 2);
            $provider = trim($provider);
            $resolvedModel = trim($resolvedModel);

            if ($provider !== '') {
                return [$provider, $resolvedModel];
            }
        }

        return ['', $model];
    }

    protected function responseModelName(string $provider, string $model): string
    {
        if ($model !== '' && $provider !== '') {
            return $provider.':'.$model;
        }

        if ($model !== '') {
            return $model;
        }

        if ($provider !== '') {
            return $provider;
        }

        $defaultProvider = config('ai.default');

        return is_string($defaultProvider) && $defaultProvider !== '' ? $defaultProvider : 'default';
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

    /**
     * @return array{0: mixed, 1: string, 2: string}
     *
     * @throws Throwable
     */
    protected function promptWithProviderModelFallback(
        Agent $agent,
        string $prompt,
        string $requestedProvider,
        string $requestedModel,
        int $timeout,
    ): array {
        $provider = $this->resolveEffectiveProvider($requestedProvider);

        if ($provider === '') {
            $response = $agent->prompt(
                $prompt,
                provider: null,
                model: $requestedModel !== '' ? $requestedModel : null,
                timeout: $timeout,
            );

            return [$response, '', $requestedModel];
        }

        $modelCandidates = $this->resolveFallbackModels($provider, $requestedModel);

        if ($modelCandidates === []) {
            $response = $agent->prompt(
                $prompt,
                provider: $provider,
                model: $requestedModel !== '' ? $requestedModel : null,
                timeout: $timeout,
            );

            return [$response, $provider, $requestedModel];
        }

        $lastError = null;

        foreach ($modelCandidates as $modelCandidate) {
            try {
                $response = $agent->prompt(
                    $prompt,
                    provider: $provider,
                    model: $modelCandidate,
                    timeout: $timeout,
                );

                return [$response, $provider, $modelCandidate];
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

        $response = $agent->prompt(
            $prompt,
            provider: $provider,
            model: null,
            timeout: $timeout,
        );

        return [$response, $provider, ''];
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

    protected function errorResponse(Throwable $e): JsonResponse
    {
        $statusCode = is_int($e->getCode()) && $e->getCode() >= 400 && $e->getCode() < 600
            ? $e->getCode()
            : 500;

        return response()->json([
            'error' => [
                'message' => $e->getMessage(),
                'type' => 'server_error',
                'code' => (string) $statusCode,
            ],
        ], $statusCode);
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
}
