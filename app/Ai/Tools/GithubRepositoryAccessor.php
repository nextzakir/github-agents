<?php

namespace App\Ai\Tools;

use Github\AuthMethod;
use Github\Client;
use Github\ResultPager;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

class GithubRepositoryAccessor implements Tool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'Access configured GitHub repositories. Supported actions: list repositories, list files, read file contents, and create pull requests.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $action = $request->string('action')->toString();

        if (! in_array($action, ['list_repositories', 'list_files', 'read_file', 'create_pull_request'], true)) {
            return sprintf(
                'Validation error: unknown action "%s". Allowed actions: list_repositories, list_files, read_file, create_pull_request.',
                $action
            );
        }

        try {
            if ($action === 'list_repositories') {
                return $this->listRepositoriesResponse(
                    $this->resolveAllAccessibleRepositories()
                );
            }

            $repository = trim($request->string('repository')->toString());

            if ($repository === '') {
                return 'Validation error: "repository" is required for list_files, read_file, and create_pull_request.';
            }

            [$accessToken, $owner] = $this->resolveRepositoryCredentials($repository);

            if ($accessToken === '' || $owner === '') {
                return sprintf(
                    'Validation error: missing configured GitHub credentials for repository "%s". Set github.repositories.%s.owner and github.repositories.%s.token.',
                    $repository,
                    $repository,
                    $repository,
                );
            }

            $client = $this->createAuthenticatedClient($accessToken);
            $path = $request->string('path')->toString();

            if ($action === 'list_files') {
                return $this->listFilesResponse(
                    $this->fetchRepositoryFiles($client, $owner, $repository, $path, $request->string('branch')->toString())
                );
            }

            if ($path === '') {
                return 'Validation error: "path" is required for read_file and create_pull_request.';
            }

            if ($action === 'read_file') {
                $repositoryInfo = $client->repo()->show($owner, $repository);
                $defaultBranch = (string) ($repositoryInfo['default_branch'] ?? 'main');
                $branch = $request->string('branch')->toString();
                $branch = $branch !== '' ? $branch : $defaultBranch;
                $file = $client->repo()->contents()->show($owner, $repository, $path, $branch);

                if (! isset($file['content']) || ! is_string($file['content'])) {
                    return 'GitHub API error: file content was missing from the response.';
                }

                $decoded = base64_decode($file['content'], true);

                if ($decoded === false) {
                    return 'GitHub API error: failed to decode file content.';
                }

                return $decoded;
            }

            $content = $request->string('content')->toString();

            if ($content === '') {
                return 'Validation error: "content" is required for create_pull_request.';
            }

            $changeSummary = $request->string('change_summary')->toString();
            $repositoryInfo = $client->repo()->show($owner, $repository);
            $defaultBranch = (string) ($repositoryInfo['default_branch'] ?? 'main');
            $baseBranch = $request->string('base_branch')->toString();
            $baseBranch = $baseBranch !== '' ? $baseBranch : $defaultBranch;
            $baseBranchInfo = $client->repo()->branches($owner, $repository, $baseBranch);
            $baseSha = $baseBranchInfo['commit']['sha'] ?? null;

            if (! is_string($baseSha) || $baseSha === '') {
                return sprintf('GitHub API error: could not resolve commit SHA for base branch "%s".', $baseBranch);
            }

            $branchName = sprintf('ai/update-%s', now()->format('YmdHis'));

            $client->git()->references()->create($owner, $repository, [
                'ref' => sprintf('refs/heads/%s', $branchName),
                'sha' => $baseSha,
            ]);

            $currentFile = $client->repo()->contents()->show($owner, $repository, $path, $baseBranch);
            $currentSha = $currentFile['sha'] ?? null;

            if (! is_string($currentSha) || $currentSha === '') {
                return 'GitHub API error: could not resolve current file SHA.';
            }

            $commitMessage = $request->string('commit_message')->toString();
            $commitMessage = $commitMessage !== '' ? $commitMessage : sprintf('Update %s', $path);
            $client->repo()->contents()->update($owner, $repository, $path, $content, $commitMessage, $currentSha, $branchName);

            $title = $request->string('pr_title')->toString();
            $title = $title !== '' ? $title : sprintf('Update %s', $path);
            $body = $changeSummary !== ''
                ? sprintf("Requested change summary:\n\n%s", $changeSummary)
                : 'Changes requested through the GitHub repository accessor tool.';

            $pullRequest = $client->pullRequest()->create($owner, $repository, [
                'base' => $baseBranch,
                'head' => $branchName,
                'title' => $title,
                'body' => $body,
            ]);

            $url = $pullRequest['html_url'] ?? null;

            if (! is_string($url) || $url === '') {
                return 'GitHub API error: PR was created but no URL was returned.';
            }

            return sprintf(
                'Pull request created successfully: %s (base: %s, branch: %s)',
                $url,
                $baseBranch,
                $branchName
            );
        } catch (Throwable $e) {
            return sprintf('GitHub API error: %s', $e->getMessage());
        }
    }

    /**
     * Get the tool's schema definition.
     *
     * @return array|Type[]
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema
                ->string()
                ->enum(['list_repositories', 'list_files', 'read_file', 'create_pull_request'])
                ->description('Action to perform: "list_repositories", "list_files", "read_file", or "create_pull_request".')
                ->required(),
            'repository' => $schema
                ->string()
                ->description('Repository key from github.repositories. Required for list_files, read_file, and create_pull_request.'),
            'path' => $schema
                ->string()
                ->description('Path in the repository. For list_files: optional prefix filter. For read_file/create_pull_request: required file path.'),
            'branch' => $schema
                ->string()
                ->description('Branch used by list_files/read_file. Defaults to repository default branch.'),
            'content' => $schema
                ->string()
                ->description('Full replacement file content. Required for create_pull_request.'),
            'change_summary' => $schema
                ->string()
                ->description('Short summary of requested change, used in pull request body.'),
            'base_branch' => $schema
                ->string()
                ->description('Optional base branch for the pull request. Defaults to repository default branch.'),
            'commit_message' => $schema
                ->string()
                ->description('Optional commit message.'),
            'pr_title' => $schema
                ->string()
                ->description('Optional pull request title.'),
        ];
    }

    /**
     * Create an authenticated client for GitHub API access.
     */
    protected function createAuthenticatedClient(string $accessToken): Client
    {
        $client = new Client;
        $client->authenticate($accessToken, null, AuthMethod::ACCESS_TOKEN);

        return $client;
    }

    /**
     * Fetch all accessible repositories with the access token.
     *
     * @return array<int, string>
     */
    protected function fetchAccessibleRepositories(Client $client, string $ownerFilter = ''): array
    {
        $pager = $this->createResultPager($client);

        $repositories = $pager->fetchAll(
            $client->currentUser(),
            'repositories',
            ['owner', 'full_name', 'asc', null, 'owner,collaborator,organization_member']
        );

        $normalizedOwnerFilter = trim($ownerFilter);
        $fullNames = [];

        foreach ($repositories as $repository) {
            $fullName = is_array($repository) ? ($repository['full_name'] ?? null) : null;

            if (! is_string($fullName) || $fullName === '') {
                continue;
            }

            if ($normalizedOwnerFilter !== '' && ! str_starts_with($fullName, $normalizedOwnerFilter.'/')) {
                continue;
            }

            $fullNames[] = $fullName;
        }

        $fullNames = array_values(array_unique($fullNames));
        sort($fullNames);

        return $fullNames;
    }

    /**
     * Pagination helper.
     */
    protected function createResultPager(Client $client): ResultPager
    {
        return new ResultPager($client);
    }

    /**
     * Fetch repository files.
     *
     * @return array<int, string>
     */
    protected function fetchRepositoryFiles(
        Client $client,
        string $owner,
        string $repository,
        string $pathPrefix = '',
        string $branch = ''
    ): array {
        $repositoryInfo = $client->repo()->show($owner, $repository);
        $defaultBranch = (string) ($repositoryInfo['default_branch'] ?? 'main');
        $selectedBranch = $branch !== '' ? $branch : $defaultBranch;
        $branchInfo = $client->repo()->branches($owner, $repository, $selectedBranch);
        $treeSha = $branchInfo['commit']['sha'] ?? null;

        if (! is_string($treeSha) || $treeSha === '') {
            return [];
        }

        $treeResponse = $client->git()->trees()->show($owner, $repository, $treeSha, true);
        $treeEntries = $treeResponse['tree'] ?? [];

        if (! is_array($treeEntries)) {
            return [];
        }

        $normalizedPrefix = trim($pathPrefix);
        $normalizedPrefix = $normalizedPrefix !== '' ? trim($normalizedPrefix, '/').'/' : '';
        $files = [];

        foreach ($treeEntries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $entryType = $entry['type'] ?? null;
            $entryPath = $entry['path'] ?? null;

            if ($entryType !== 'blob' || ! is_string($entryPath) || $entryPath === '') {
                continue;
            }

            if ($normalizedPrefix !== '' && ! str_starts_with($entryPath, $normalizedPrefix)) {
                continue;
            }

            $files[] = $entryPath;
        }

        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }

    /**
     * Format listed repositories response.
     *
     * @param  array<int, string>  $repositories
     */
    protected function listRepositoriesResponse(array $repositories): string
    {
        return json_encode([
            'repositories' => $repositories,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{"repositories":[]}';
    }

    /**
     * Format listed files response.
     *
     * @param  array<int, string>  $files
     */
    protected function listFilesResponse(array $files): string
    {
        return json_encode([
            'files' => $files,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{"files":[]}';
    }

    /**
     * @return array<int, string>
     */
    protected function resolveAllAccessibleRepositories(): array
    {
        $accessToken = $this->resolveDefaultAccessToken();

        if ($accessToken !== '') {
            try {
                $client = $this->createAuthenticatedClient($accessToken);

                return $this->fetchAccessibleRepositories($client);
            } catch (Throwable) {
                return [];
            }
        }

        $configured = $this->fetchConfiguredRepositories();

        return $configured !== [] ? $configured : [];
    }

    /**
     * @return array<int, string>
     */
    protected function fetchConfiguredRepositories(): array
    {
        try {
            $repositories = config('github.repositories', []);
        } catch (Throwable) {
            return [];
        }

        if (! is_array($repositories)) {
            return [];
        }

        $fullNames = [];

        foreach ($repositories as $repositoryKey => $repositoryConfig) {
            if (! is_string($repositoryKey) || trim($repositoryKey) === '') {
                continue;
            }

            $normalizedRepositoryKey = trim($repositoryKey);

            if (str_contains($normalizedRepositoryKey, '/')) {
                $fullNames[] = $normalizedRepositoryKey;

                continue;
            }

            $configuredOwner = is_array($repositoryConfig) ? ($repositoryConfig['owner'] ?? null) : null;
            $owner = is_string($configuredOwner) ? trim($configuredOwner) : '';

            if ($owner === '') {
                continue;
            }

            $fullNames[] = $owner.'/'.$normalizedRepositoryKey;
        }

        $fullNames = array_values(array_unique($fullNames));
        sort($fullNames);

        return $fullNames;
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function resolveRepositoryCredentials(string $repository): array
    {
        if ($repository === '') {
            return ['', ''];
        }

        $repositories = [];

        try {
            $configuredRepositories = config('github.repositories', []);

            if (is_array($configuredRepositories)) {
                $repositories = $configuredRepositories;
            }
        } catch (Throwable) {
            return ['', ''];
        }

        $repositoryConfig = $this->findRepositoryConfig($repositories, $repository);

        $accessToken = '';
        $owner = '';

        if (is_array($repositoryConfig)) {
            $configuredToken = $repositoryConfig['token'] ?? null;

            if (is_string($configuredToken) && trim($configuredToken) !== '') {
                $accessToken = trim($configuredToken);
            } else {
                $accessToken = $this->resolveDefaultAccessToken();
            }

            $configuredOwner = $repositoryConfig['owner'] ?? null;

            if (is_string($configuredOwner) && trim($configuredOwner) !== '') {
                $owner = trim($configuredOwner);
            }
        }

        if ($accessToken === '') {
            $accessToken = $this->resolveDefaultAccessToken();
        }

        if ($owner === '' && str_contains($repository, '/')) {
            $segments = explode('/', $repository);
            $candidateOwner = trim((string) ($segments[0] ?? ''));

            if ($candidateOwner !== '') {
                $owner = $candidateOwner;
            }
        }

        return [$accessToken, $owner];
    }

    protected function resolveDefaultAccessToken(): string
    {
        try {
            $defaultConnection = config('github.default');
        } catch (Throwable) {
            return '';
        }

        if (! is_string($defaultConnection) || trim($defaultConnection) === '') {
            return '';
        }

        $token = config('github.connections.'.trim($defaultConnection).'.token');

        if (! is_string($token) || trim($token) === '') {
            return '';
        }

        return trim($token);
    }

    /**
     * @param  array<string, mixed>  $repositories
     * @return array<string, mixed>|null
     */
    protected function findRepositoryConfig(array $repositories, string $repository): ?array
    {
        $direct = $repositories[$repository] ?? null;

        if (is_array($direct)) {
            return $direct;
        }

        if (str_contains($repository, '/')) {
            $segments = explode('/', $repository);
            $shortName = trim((string) end($segments));
            $shortMatch = $shortName !== '' ? ($repositories[$shortName] ?? null) : null;

            if (is_array($shortMatch)) {
                return $shortMatch;
            }
        }

        foreach ($repositories as $repositoryKey => $repositoryConfig) {
            if (! is_array($repositoryConfig) || ! is_string($repositoryKey)) {
                continue;
            }

            $configuredOwner = $repositoryConfig['owner'] ?? null;
            $fullName = is_string($configuredOwner) && trim($configuredOwner) !== ''
                ? trim($configuredOwner).'/'.trim($repositoryKey)
                : trim($repositoryKey);

            if ($fullName === $repository) {
                return $repositoryConfig;
            }
        }

        return null;
    }
}
