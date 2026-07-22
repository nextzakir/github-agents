<?php

namespace Tests\Feature\RepositoryAssistants;

use App\Console\Commands\RepositoryAssistantChat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepositoryAssistantChatCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_has_no_repository_argument(): void
    {
        $command = new RepositoryAssistantChat;
        $definition = $command->getDefinition();

        $this->assertFalse($definition->hasArgument('repository'));
    }
}
