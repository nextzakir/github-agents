<?php

namespace Tests\Unit\Ai\Tools;

use App\Ai\Tools\GithubRepositoryAccessor;
use Github\Api\GitData;
use Github\Api\GitData\References;
use Github\Api\GitData\Trees;
use Github\Api\PullRequest;
use Github\Api\Repo;
use Github\Api\Repository\Contents;
use Github\Client;
use Laravel\Ai\Tools\Request;
use Mockery;
use PHPUnit\Framework\TestCase;

class GithubRepositoryAccessorTest extends TestCase
{
    public function test_it_lists_accessible_repositories(): void
    {
        $tool = Mockery::mock(GithubRepositoryAccessor::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $tool->shouldAllowMockingProtectedMethods();
        $tool->shouldReceive('resolveAllAccessibleRepositories')
            ->once()
            ->andReturn(['acme/platform', 'acme/tailnet-config']);

        $result = $tool->handle(new Request([
            'action' => 'list_repositories',
        ]));

        $decoded = json_decode((string) $result, true);

        $this->assertIsArray($decoded);
        $this->assertSame(['acme/platform', 'acme/tailnet-config'], $decoded['repositories'] ?? null);
    }

    public function test_it_reads_a_repository_file(): void
    {
        $client = Mockery::mock(Client::class);
        $repoApi = Mockery::mock(Repo::class);
        $contentsApi = Mockery::mock(Contents::class);

        $tool = Mockery::mock(GithubRepositoryAccessor::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $tool->shouldAllowMockingProtectedMethods();
        $tool->shouldReceive('resolveRepositoryCredentials')
            ->once()
            ->with('platform')
            ->andReturn(['ghp_token_123', 'acme']);

        $tool->shouldAllowMockingProtectedMethods();
        $tool->shouldReceive('createAuthenticatedClient')
            ->once()
            ->with('ghp_token_123')
            ->andReturn($client);

        $client->shouldReceive('repo')->twice()->andReturn($repoApi);

        $repoApi->shouldReceive('show')
            ->once()
            ->with('acme', 'platform')
            ->andReturn(['default_branch' => 'main']);

        $repoApi->shouldReceive('contents')
            ->once()
            ->andReturn($contentsApi);

        $contentsApi->shouldReceive('show')
            ->once()
            ->with('acme', 'platform', 'README.md', 'main')
            ->andReturn(['content' => base64_encode('hello')]);

        $result = $tool->handle(new Request([
            'action' => 'read_file',
            'repository' => 'platform',
            'path' => 'README.md',
        ]));

        $this->assertSame('hello', $result);
    }

    public function test_it_lists_repository_files_for_a_path_prefix(): void
    {
        $client = Mockery::mock(Client::class);
        $repoApi = Mockery::mock(Repo::class);
        $gitDataApi = Mockery::mock(GitData::class);
        $treesApi = Mockery::mock(Trees::class);

        $tool = Mockery::mock(GithubRepositoryAccessor::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $tool->shouldAllowMockingProtectedMethods();
        $tool->shouldReceive('resolveRepositoryCredentials')
            ->once()
            ->with('platform')
            ->andReturn(['ghp_token_123', 'acme']);

        $tool->shouldAllowMockingProtectedMethods();
        $tool->shouldReceive('createAuthenticatedClient')
            ->once()
            ->with('ghp_token_123')
            ->andReturn($client);

        $client->shouldReceive('repo')->twice()->andReturn($repoApi);
        $client->shouldReceive('git')->once()->andReturn($gitDataApi);
        $gitDataApi->shouldReceive('trees')->once()->andReturn($treesApi);

        $repoApi->shouldReceive('show')
            ->once()
            ->with('acme', 'platform')
            ->andReturn(['default_branch' => 'main']);

        $repoApi->shouldReceive('branches')
            ->once()
            ->with('acme', 'platform', 'main')
            ->andReturn(['commit' => ['sha' => 'tree-sha-123']]);

        $treesApi->shouldReceive('show')
            ->once()
            ->with('acme', 'platform', 'tree-sha-123', true)
            ->andReturn([
                'tree' => [
                    ['type' => 'blob', 'path' => 'docs/policy.hujson'],
                    ['type' => 'blob', 'path' => 'docs/readme.md'],
                    ['type' => 'blob', 'path' => 'src/App.php'],
                    ['type' => 'tree', 'path' => 'docs'],
                ],
            ]);

        $result = $tool->handle(new Request([
            'action' => 'list_files',
            'repository' => 'platform',
            'path' => 'docs',
        ]));

        $decoded = json_decode((string) $result, true);

        $this->assertIsArray($decoded);
        $this->assertSame(['docs/policy.hujson', 'docs/readme.md'], $decoded['files'] ?? null);
    }

    public function test_it_creates_pull_request_for_a_file_update(): void
    {
        $client = Mockery::mock(Client::class);
        $repoApi = Mockery::mock(Repo::class);
        $contentsApi = Mockery::mock(Contents::class);
        $gitDataApi = Mockery::mock(GitData::class);
        $referencesApi = Mockery::mock(References::class);
        $pullRequestApi = Mockery::mock(PullRequest::class);

        $tool = Mockery::mock(GithubRepositoryAccessor::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $tool->shouldAllowMockingProtectedMethods();
        $tool->shouldReceive('resolveRepositoryCredentials')
            ->once()
            ->with('platform')
            ->andReturn(['ghp_token_123', 'acme']);

        $tool->shouldAllowMockingProtectedMethods();
        $tool->shouldReceive('createAuthenticatedClient')
            ->once()
            ->with('ghp_token_123')
            ->andReturn($client);

        $client->shouldReceive('repo')->times(4)->andReturn($repoApi);
        $client->shouldReceive('git')->once()->andReturn($gitDataApi);
        $client->shouldReceive('pullRequest')->once()->andReturn($pullRequestApi);

        $repoApi->shouldReceive('show')
            ->once()
            ->with('acme', 'platform')
            ->andReturn(['default_branch' => 'main']);

        $repoApi->shouldReceive('branches')
            ->once()
            ->with('acme', 'platform', 'main')
            ->andReturn(['commit' => ['sha' => 'base-sha-123']]);

        $repoApi->shouldReceive('contents')->twice()->andReturn($contentsApi);

        $contentsApi->shouldReceive('show')
            ->once()
            ->with('acme', 'platform', 'README.md', 'main')
            ->andReturn(['sha' => 'file-sha-456']);

        $contentsApi->shouldReceive('update')
            ->once()
            ->withArgs(function (
                string $owner,
                string $repository,
                string $path,
                string $content,
                string $commitMessage,
                string $sha,
                string $branch
            ): bool {
                return $owner === 'acme'
                    && $repository === 'platform'
                    && $path === 'README.md'
                    && $content === 'updated content'
                    && $commitMessage === 'Update README.md'
                    && $sha === 'file-sha-456'
                    && str_starts_with($branch, 'ai/update-');
            })
            ->andReturn(['content' => ['sha' => 'new-sha']]);

        $gitDataApi->shouldReceive('references')->once()->andReturn($referencesApi);

        $referencesApi->shouldReceive('create')
            ->once()
            ->withArgs(function (string $owner, string $repository, array $payload): bool {
                return $owner === 'acme'
                    && $repository === 'platform'
                    && str_starts_with($payload['ref'] ?? '', 'refs/heads/ai/update-')
                    && ($payload['sha'] ?? null) === 'base-sha-123';
            })
            ->andReturn([]);

        $pullRequestApi->shouldReceive('create')
            ->once()
            ->withArgs(function (string $owner, string $repository, array $payload): bool {
                return $owner === 'acme'
                    && $repository === 'platform'
                    && ($payload['base'] ?? null) === 'main'
                    && str_starts_with($payload['head'] ?? '', 'ai/update-')
                    && ($payload['title'] ?? null) === 'Update README.md';
            })
            ->andReturn(['html_url' => 'https://github.com/acme/platform/pull/1']);

        $result = $tool->handle(new Request([
            'action' => 'create_pull_request',
            'repository' => 'platform',
            'path' => 'README.md',
            'content' => 'updated content',
            'change_summary' => 'Refresh README instructions',
        ]));

        $this->assertStringContainsString('Pull request created successfully:', (string) $result);
        $this->assertStringContainsString('https://github.com/acme/platform/pull/1', (string) $result);
    }

    public function test_it_lists_repositories_without_access_token(): void
    {
        $result = (new GithubRepositoryAccessor)->handle(new Request([
            'action' => 'list_repositories',
        ]));

        $decoded = json_decode((string) $result, true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('repositories', $decoded);
        $this->assertIsArray($decoded['repositories']);
    }

    public function test_it_rejects_unknown_action(): void
    {
        $result = (new GithubRepositoryAccessor)->handle(new Request([
            'action' => 'unknown_action',
        ]));

        $this->assertSame(
            'Validation error: unknown action "unknown_action". Allowed actions: list_repositories, list_files, read_file, create_pull_request.',
            $result
        );
    }

    public function test_it_requires_repository_for_file_actions(): void
    {
        $tool = Mockery::mock(GithubRepositoryAccessor::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $result = $tool->handle(new Request([
            'action' => 'read_file',
        ]));

        $this->assertSame(
            'Validation error: "repository" is required for list_files, read_file, and create_pull_request.',
            $result
        );
    }

    public function test_it_requires_path_for_read_file_and_create_pull_request(): void
    {
        $client = Mockery::mock(Client::class);

        $tool = Mockery::mock(GithubRepositoryAccessor::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $tool->shouldAllowMockingProtectedMethods();
        $tool->shouldReceive('resolveRepositoryCredentials')
            ->once()
            ->with('platform')
            ->andReturn(['ghp_token_123', 'acme']);

        $tool->shouldAllowMockingProtectedMethods();
        $tool->shouldReceive('createAuthenticatedClient')
            ->once()
            ->with('ghp_token_123')
            ->andReturn($client);

        $result = $tool->handle(new Request([
            'action' => 'read_file',
            'repository' => 'platform',
        ]));

        $this->assertSame('Validation error: "path" is required for read_file and create_pull_request.', $result);
    }

    public function test_it_requires_configured_credentials_for_repository_actions(): void
    {
        $tool = Mockery::mock(GithubRepositoryAccessor::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $tool->shouldAllowMockingProtectedMethods();
        $tool->shouldReceive('resolveRepositoryCredentials')
            ->once()
            ->with('platform')
            ->andReturn(['', '']);

        $result = $tool->handle(new Request([
            'action' => 'read_file',
            'repository' => 'platform',
            'path' => 'README.md',
        ]));

        $this->assertSame(
            'Validation error: missing configured GitHub credentials for repository "platform". Set github.repositories.platform.owner and github.repositories.platform.token.',
            $result
        );
    }
}
