<?php

namespace App\Console\Commands;

use App\Services\ProductionReadiness;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('lifeflow:readiness {--json : Emit fixed check identifiers and statuses without configured values}')]
#[Description('Offline production configuration lint; no database, cache, file or provider probes')]
class ProductionReadinessCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ProductionReadiness $readiness): int
    {
        try {
            $result = $readiness->inspect();
            if ($this->option('json')) {
                $this->line(json_encode($result, JSON_THROW_ON_ERROR));
            } else {
                foreach ($result['checks'] as $check) {
                    $this->line($check['status'].' '.$check['code'].': '.$check['requirement']);
                }
                $this->line('Configuration lint only. Production services and deployment have not been verified.');
            }

            return $result['configuration_valid'] ? self::SUCCESS : self::FAILURE;
        } catch (Throwable) {
            // A bad configuration or custom provider must not turn this diagnostic into a secret dump.
            $message = ['configuration_valid' => false, 'production_verified' => false, 'error' => 'configuration_inspection_failed'];
            $this->line($this->option('json') ? json_encode($message) : 'Configuration inspection failed; no values or exception details are displayed.');

            return self::FAILURE;
        }
    }
}
