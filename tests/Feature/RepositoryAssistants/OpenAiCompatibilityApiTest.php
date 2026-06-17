<?php

namespace Tests\Feature\RepositoryAssistants;

use App\Ai\Agents\RepositoryAssistant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\TestCase;

class OpenAiCompatibilityApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_models_endpoint_returns_openai_compatible_list_shape(): void
    {
        $response = $this->getJson('/api/v1/nextzakir/tailscale-policy/models');

        $response->assertOk();
        $response->assertJsonPath('object', 'list');
        $response->assertJsonStructure([
            'object',
            'data',
        ]);
    }

    public function test_models_endpoint_lists_configured_provider_name(): void
    {
        config()->set('ai.providers.gemini', [
            'driver' => 'gemini',
            'key' => 'test-key',
        ]);

        $response = $this->getJson('/api/v1/nextzakir/tailscale-policy/models');

        $response->assertOk();
        $response->assertJsonFragment(['id' => 'gemini']);
    }

    public function test_models_endpoint_lists_concrete_provider_models_when_configured(): void
    {
        config()->set('ai.providers.gemini', [
            'driver' => 'gemini',
            'key' => 'test-key',
            'models' => [
                'text' => [
                    'default' => 'gemini-custom-default',
                ],
            ],
        ]);
        config()->set('ai.provider_failover.gemini', [
            'gemini-3-flash-preview',
            'gemini-2.5-flash-lite',
        ]);

        $response = $this->getJson('/api/v1/nextzakir/tailscale-policy/models');

        $response->assertOk();
        $response->assertJsonFragment(['id' => 'gemini']);
        $response->assertJsonFragment(['id' => 'gemini-3-flash-preview']);
        $response->assertJsonFragment(['id' => 'gemini-2.5-flash-lite']);
        $response->assertJsonFragment(['id' => 'gemini-custom-default']);
    }

    public function test_models_endpoint_uses_provider_driver_for_owned_by(): void
    {
        config()->set('ai.providers.custom_gemini_connection', [
            'driver' => 'gemini',
            'key' => 'test-key',
        ]);

        $response = $this->getJson('/api/v1/nextzakir/tailscale-policy/models');

        $response->assertOk();
        $response->assertJsonFragment([
            'id' => 'custom_gemini_connection',
            'owned_by' => 'gemini',
        ]);
    }

    public function test_chat_completions_returns_openai_compatible_response_shape(): void
    {
        RepositoryAssistant::fake(['Repo says hello.']);

        $response = $this->postJson('/api/v1/nextzakir/tailscale-policy/chat/completions', [
            'model' => 'gemini',
            'messages' => [
                [
                    'role' => 'user',
                    'content' => 'ping',
                ],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('object', 'chat.completion');
        $response->assertJsonPath('choices.0.message.role', 'assistant');
        $response->assertJsonPath('choices.0.message.content', 'Repo says hello.');
        $response->assertJsonStructure([
            'id',
            'object',
            'created',
            'model',
            'choices' => [
                [
                    'index',
                    'message' => ['role', 'content'],
                    'finish_reason',
                ],
            ],
            'usage' => ['prompt_tokens', 'completion_tokens', 'total_tokens'],
            'conversation_id',
        ]);

        RepositoryAssistant::assertPrompted(function (AgentPrompt $prompt): bool {
            return $prompt->contains('ping');
        });
    }

    public function test_chat_completions_requires_messages_array(): void
    {
        $response = $this->postJson('/api/v1/nextzakir/tailscale-policy/chat/completions', [
            'model' => 'gemini',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['messages']);
    }

    public function test_chat_completions_injects_github_context_from_repository_route_parameter(): void
    {
        RepositoryAssistant::fake(['Analyzed from repository route parameter.']);

        $response = $this->postJson('/api/v1/nextzakir/tailscale-policy/chat/completions', [
            'model' => 'gemini',
            'messages' => [
                [
                    'role' => 'user',
                    'content' => 'Can you explain my policies?',
                ],
            ],
        ]);

        $response->assertOk();

        RepositoryAssistant::assertPrompted(function (AgentPrompt $prompt): bool {
            return $prompt->contains('GitHub tool context for this request:')
                && $prompt->contains('- repository: nextzakir/tailscale-policy')
                && ! $prompt->contains('- path:')
                && $prompt->contains('Can you explain my policies?');
        });
    }

    public function test_chat_completions_works_with_any_repository_name(): void
    {
        RepositoryAssistant::fake(['Works with any repo.']);

        $response = $this->postJson('/api/v1/any-owner/any-repo/chat/completions', [
            'model' => 'gemini',
            'messages' => [
                [
                    'role' => 'user',
                    'content' => 'Hello',
                ],
            ],
        ]);

        $response->assertOk();

        RepositoryAssistant::assertPrompted(function (AgentPrompt $prompt): bool {
            return $prompt->contains('repository: any-owner/any-repo');
        });
    }

    public function test_conversations_endpoint_returns_empty_list_when_no_conversations(): void
    {
        $response = $this->getJson('/api/v1/acme/platform/conversations');

        $response->assertOk();
        $response->assertJsonPath('data', []);
        $response->assertJsonStructure([
            'data',
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
        ]);
        $response->assertJsonPath('meta.total', 0);
    }

    public function test_conversations_endpoint_lists_recent_conversations(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'api@system.local'],
            ['name' => 'API Client', 'password' => bcrypt('test')]
        );

        DB::table('agent_conversations')->insert([
            [
                'id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
                'user_id' => $user->id,
                'title' => 'First conversation about Tailscale policies',
                'created_at' => now()->subHours(2),
                'updated_at' => now()->subHours(2),
            ],
            [
                'id' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
                'user_id' => $user->id,
                'title' => 'Reviewing repository architecture',
                'created_at' => now()->subHour(),
                'updated_at' => now()->subHour(),
            ],
            [
                'id' => 'cccccccc-cccc-cccc-cccc-cccccccccccc',
                'user_id' => $user->id,
                'title' => 'Debugging queued job failure',
                'created_at' => now()->subMinutes(30),
                'updated_at' => now()->subMinutes(30),
            ],
        ]);

        $response = $this->getJson('/api/v1/acme/platform/conversations');

        $response->assertOk();
        $response->assertJsonCount(3, 'data');
        $response->assertJsonPath('meta.total', 3);
        $response->assertJsonPath('meta.per_page', 20);
        $response->assertJsonPath('meta.current_page', 1);
        $response->assertJsonPath('meta.last_page', 1);

        $data = $response->json('data');
        $this->assertSame('Debugging queued job failure', $data[0]['title']);
        $this->assertSame('Reviewing repository architecture', $data[1]['title']);
        $this->assertSame('First conversation about Tailscale policies', $data[2]['title']);
    }

    public function test_conversations_endpoint_paginates_results(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'api@system.local'],
            ['name' => 'API Client', 'password' => bcrypt('test')]
        );

        for ($i = 1; $i <= 5; $i++) {
            DB::table('agent_conversations')->insert([
                'id' => sprintf('00000000-0000-0000-0000-%012d', $i),
                'user_id' => $user->id,
                'title' => "Conversation {$i}",
                'created_at' => now()->subHours(5 - $i),
                'updated_at' => now()->subHours(5 - $i),
            ]);
        }

        $response = $this->getJson('/api/v1/acme/platform/conversations?per_page=2&page=1');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('meta.total', 5);
        $response->assertJsonPath('meta.per_page', 2);
        $response->assertJsonPath('meta.current_page', 1);
        $response->assertJsonPath('meta.last_page', 3);

        $response = $this->getJson('/api/v1/acme/platform/conversations?per_page=2&page=2');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('meta.current_page', 2);

        $response = $this->getJson('/api/v1/acme/platform/conversations?per_page=2&page=3');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('meta.current_page', 3);
    }
}
