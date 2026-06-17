<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PruneAgentConversations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'agent-conversations:prune
        {--days= : Prune conversations/messages older than this many days}
        {--dry-run : Show how many rows would be deleted without deleting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prune old Laravel AI conversation records.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $configuredDays = config('ai.conversations.prune_after_days', 30);
        $days = is_numeric($this->option('days'))
            ? max(1, (int) $this->option('days'))
            : (is_numeric($configuredDays) ? max(1, (int) $configuredDays) : 30);
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = Carbon::now()->subDays($days);

        $conversationIds = DB::table('agent_conversations')
            ->where('updated_at', '<', $cutoff)
            ->pluck('id')
            ->filter(fn ($id) => is_string($id) && trim($id) !== '')
            ->values();

        $conversationCount = $conversationIds->count();
        $messageCount = $conversationCount > 0
            ? DB::table('agent_conversation_messages')
                ->whereIn('conversation_id', $conversationIds->all())
                ->count()
            : 0;

        if ($conversationCount === 0) {
            $this->info("No conversations older than {$days} days were found.");

            return Command::SUCCESS;
        }

        if ($dryRun) {
            $this->line("Dry run: would delete {$conversationCount} conversations and {$messageCount} messages older than {$days} days.");

            return Command::SUCCESS;
        }

        DB::transaction(function () use ($conversationIds): void {
            DB::table('agent_conversation_messages')
                ->whereIn('conversation_id', $conversationIds->all())
                ->delete();

            DB::table('agent_conversations')
                ->whereIn('id', $conversationIds->all())
                ->delete();
        });

        $this->info("Pruned {$conversationCount} conversations and {$messageCount} messages older than {$days} days.");

        return Command::SUCCESS;
    }
}
