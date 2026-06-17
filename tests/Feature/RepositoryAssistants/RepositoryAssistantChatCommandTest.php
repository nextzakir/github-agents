<?php

namespace Tests\Feature\RepositoryAssistants;

use App\Console\Commands\RepositoryAssistantChat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepositoryAssistantChatCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_accepts_owner_repo_argument(): void
    {
        $command = new RepositoryAssistantChat;
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasArgument('repository'));

        $argument = $definition->getArgument('repository');
        $this->assertSame('GitHub repository (owner/repo)', $argument->getDescription());
    }
}
