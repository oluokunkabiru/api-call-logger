<?php

namespace Oluokunkabiru\ApiCallLogger\Commands;

use Illuminate\Console\Command;
use Oluokunkabiru\ApiCallLogger\ApiCallLogger;

class ForgetApiCallLogsCommand extends Command
{
    protected $signature = 'api-call-logger:forget
                            {--user= : Delete every record of this user id}
                            {--tenant= : Delete every record of this tenant}
                            {--force : Do not ask for confirmation}';

    protected $description = 'Erase the API call logs of a user or tenant (data-subject erasure requests)';

    public function handle(ApiCallLogger $logger): int
    {
        $user = $this->option('user');
        $tenant = $this->option('tenant');

        if ($user === null && $tenant === null) {
            $this->error('Pass --user and/or --tenant.');

            return self::FAILURE;
        }

        $target = trim(($user !== null ? "user {$user} " : '') . ($tenant !== null ? "tenant {$tenant}" : ''));

        if (! $this->option('force') && ! $this->confirm("Permanently delete every API call log of {$target}?")) {
            return self::SUCCESS;
        }

        $deleted = $logger->forget($user, $tenant);

        $this->info("Deleted {$deleted} API call log record(s) of {$target}.");

        return self::SUCCESS;
    }
}
