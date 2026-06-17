<?php

namespace Tests\Feature\RepositoryAssistants;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PruneAgentConversationsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_supports_dry_run_without_deleting_rows(): void
    {
        $oldConversationId = '11111111-1111-1111-1111-111111111111';

        DB::table('agent_conversations')->insert([
            'id' => $oldConversationId,
            'user_id' => null,
            'title' => 'Old conversation',
            'created_at' => now()->subDays(40),
            'updated_at' => now()->subDays(40),
        ]);

        DB::table('agent_conversation_messages')->insert([
            'id' => '22222222-2222-2222-2222-222222222222',
            'conversation_id' => $oldConversationId,
            'user_id' => null,
            'agent' => 'App\\Ai\\Agents\\RepositoryAssistant',
            'role' => 'assistant',
            'content' => 'hello',
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '{}',
            'meta' => '{}',
            'created_at' => now()->subDays(40),
            'updated_at' => now()->subDays(40),
        ]);

        $this->artisan('agent-conversations:prune', ['--days' => 30, '--dry-run' => true])
            ->expectsOutputToContain('Dry run: would delete 1 conversations and 1 messages older than 30 days.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('agent_conversations', ['id' => $oldConversationId]);
        $this->assertDatabaseHas('agent_conversation_messages', ['conversation_id' => $oldConversationId]);
    }

    public function test_it_prunes_old_conversations_and_messages(): void
    {
        $oldConversationId = '33333333-3333-3333-3333-333333333333';
        $newConversationId = '44444444-4444-4444-4444-444444444444';

        DB::table('agent_conversations')->insert([
            [
                'id' => $oldConversationId,
                'user_id' => null,
                'title' => 'Old conversation',
                'created_at' => now()->subDays(40),
                'updated_at' => now()->subDays(40),
            ],
            [
                'id' => $newConversationId,
                'user_id' => null,
                'title' => 'Recent conversation',
                'created_at' => now()->subDays(1),
                'updated_at' => now()->subDays(1),
            ],
        ]);

        DB::table('agent_conversation_messages')->insert([
            [
                'id' => '55555555-5555-5555-5555-555555555555',
                'conversation_id' => $oldConversationId,
                'user_id' => null,
                'agent' => 'App\\Ai\\Agents\\RepositoryAssistant',
                'role' => 'assistant',
                'content' => 'old',
                'attachments' => '[]',
                'tool_calls' => '[]',
                'tool_results' => '[]',
                'usage' => '{}',
                'meta' => '{}',
                'created_at' => now()->subDays(40),
                'updated_at' => now()->subDays(40),
            ],
            [
                'id' => '66666666-6666-6666-6666-666666666666',
                'conversation_id' => $newConversationId,
                'user_id' => null,
                'agent' => 'App\\Ai\\Agents\\RepositoryAssistant',
                'role' => 'assistant',
                'content' => 'new',
                'attachments' => '[]',
                'tool_calls' => '[]',
                'tool_results' => '[]',
                'usage' => '{}',
                'meta' => '{}',
                'created_at' => now()->subDays(1),
                'updated_at' => now()->subDays(1),
            ],
        ]);

        $this->artisan('agent-conversations:prune', ['--days' => 30])
            ->expectsOutputToContain('Pruned 1 conversations and 1 messages older than 30 days.')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('agent_conversations', ['id' => $oldConversationId]);
        $this->assertDatabaseMissing('agent_conversation_messages', ['conversation_id' => $oldConversationId]);
        $this->assertDatabaseHas('agent_conversations', ['id' => $newConversationId]);
        $this->assertDatabaseHas('agent_conversation_messages', ['conversation_id' => $newConversationId]);
    }
}
