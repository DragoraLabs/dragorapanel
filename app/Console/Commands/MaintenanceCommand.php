<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Console\Command;

class MaintenanceCommand extends Command
{
    protected $signature = 'panel:maintenance {mode? : on or off}';
    protected $description = 'Toggle maintenance mode for the panel';

    public function handle()
    {
        $mode = $this->argument('mode');

        if (!$mode) {
            $current = Setting::get('panel:maintenance', '0');
            $this->info('Maintenance mode is currently ' . ($current === '1' ? 'ON' : 'OFF'));
            return 0;
        }

        if ($mode === 'on') {
            Setting::set('panel:maintenance', '1');
            $this->info('Maintenance mode enabled. Panel access is blocked.');
        } elseif ($mode === 'off') {
            Setting::set('panel:maintenance', '0');
            $this->info('Maintenance mode disabled. Panel is accessible.');
        } else {
            $this->error('Usage: php artisan panel:maintenance [on|off]');
            return 1;
        }

        return 0;
    }
}
