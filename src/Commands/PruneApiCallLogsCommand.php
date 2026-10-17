<?php

namespace Oluokunkabiru\ApiCallLogger\Commands;

use Illuminate\Console\Command;

class PruneApiCallLogsCommand extends Command
{
    protected $signature = 'api-call-logger:prune
                            {--days= : Delete records older than this many days (defaults to api-call-logger.prune_after_days)}
                            {--provider= : Only prune records of this provider}';

    protected $description = 'Delete old API call log records';

    public function handle(): int
    {
        $days = $this->option('days') ?? config('api-call-logger.prune_after_days');

        if ($days === null) {
            $this->warn('Pruning is disabled (api-call-logger.prune_after_days is null). Pass --days to prune anyway.');

            return self::SUCCESS;
        }

        $model = config('api-call-logger.model');
        $total = 0;

        do {
            $deleted = $model::query()
                ->where('created_at', '<', now()->subDays((int) $days))
                ->when($this->option('provider'), fn ($query, $provider) => $query->where('provider', $provider))
                ->limit(1000)
                ->delete();

            $total += $deleted;
        } while ($deleted > 0);

        $this->info("Pruned {$total} API call log record(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
