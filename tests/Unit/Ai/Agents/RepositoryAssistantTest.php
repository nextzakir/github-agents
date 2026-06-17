<?php

namespace Tests\Unit\Ai\Agents;

use App\Ai\Agents\RepositoryAssistant;
use App\Ai\Tools\GithubRepositoryAccessor;
use PHPUnit\Framework\TestCase;

class RepositoryAssistantTest extends TestCase
{
    public function test_it_exposes_expected_instructions(): void
    {
        $instructions = (string) (new RepositoryAssistant)->instructions();

        $this->assertStringContainsString('GitHub repository assistant', $instructions);
        $this->assertStringContainsString('list_files', $instructions);
        $this->assertStringContainsString('read_file', $instructions);
        $this->assertStringContainsString('create_pull_request', $instructions);
    }

    public function test_it_registers_github_repository_accessor_tool(): void
    {
        $tools = iterator_to_array((new RepositoryAssistant)->tools());

        $this->assertCount(1, $tools);
        $this->assertInstanceOf(GithubRepositoryAccessor::class, $tools[0]);
    }
}
