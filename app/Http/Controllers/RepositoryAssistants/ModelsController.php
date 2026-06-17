<?php

namespace App\Http\Controllers\RepositoryAssistants;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class ModelsController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(string $repository = ''): JsonResponse
    {
        $providerConfigs = config('ai.providers', []);
        $providerFailover = config('ai.provider_failover', []);
        $models = [];

        foreach ($providerConfigs as $providerName => $providerConfig) {
            $hasApiKey = is_array($providerConfig)
                && is_string($providerConfig['key'] ?? null)
                && trim($providerConfig['key']) !== '';

            $isLocalProvider = is_array($providerConfig) && ($providerConfig['driver'] ?? null) === 'ollama';

            if (! $hasApiKey && ! $isLocalProvider) {
                continue;
            }

            $providerName = (string) $providerName;
            $providerDriver = is_array($providerConfig) && is_string($providerConfig['driver'] ?? null) && trim((string) $providerConfig['driver']) !== ''
                ? trim((string) $providerConfig['driver'])
                : $providerName;
            $modelIds = $this->resolveModelIdsForProvider($providerName, $providerConfig, $providerFailover);

            if ($modelIds === []) {
                $models[] = $this->toModelRecord($providerDriver, $providerName);

                continue;
            }

            foreach ($modelIds as $modelId) {
                $models[] = $this->toModelRecord($providerDriver, $modelId);
            }
        }

        $defaultProvider = config('ai.default');

        if (is_string($defaultProvider) && $defaultProvider !== '' && ! collect($models)->contains('id', $defaultProvider)) {
            $defaultProviderConfig = is_array($providerConfigs[$defaultProvider] ?? null) ? $providerConfigs[$defaultProvider] : [];
            $defaultProviderDriver = is_string($defaultProviderConfig['driver'] ?? null) && trim((string) $defaultProviderConfig['driver']) !== ''
                ? trim((string) $defaultProviderConfig['driver'])
                : $defaultProvider;
            $models[] = $this->toModelRecord($defaultProviderDriver, $defaultProvider);
        }

        return response()->json([
            'object' => 'list',
            'data' => array_values(array_unique($models, SORT_REGULAR)),
        ]);
    }

    /**
     * @param  array<string, mixed>  $providerConfig
     * @param  array<string, mixed>  $providerFailover
     * @return array<int, string>
     */
    protected function resolveModelIdsForProvider(string $providerName, array $providerConfig, array $providerFailover): array
    {
        $modelIds = [$providerName];

        $configuredFailover = $providerFailover[$providerName] ?? null;

        if (is_array($configuredFailover)) {
            foreach ($configuredFailover as $modelId) {
                if (is_string($modelId) && trim($modelId) !== '') {
                    $modelIds[] = trim($modelId);
                }
            }
        }

        $textModels = is_array($providerConfig['models']['text'] ?? null) ? $providerConfig['models']['text'] : [];

        foreach (['default', 'cheapest', 'smartest'] as $textModelKey) {
            $modelId = $textModels[$textModelKey] ?? null;

            if (is_string($modelId) && trim($modelId) !== '') {
                $modelIds[] = trim($modelId);
            }
        }

        return array_values(array_unique($modelIds));
    }

    protected function toModelRecord(string $providerDriver, string $modelId): array
    {
        return [
            'id' => $modelId,
            'object' => 'model',
            'created' => 0,
            'owned_by' => $providerDriver,
        ];
    }
}
