<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class ResetCommand extends Command
{
    protected $signature = 'panel:reset {--force : Skip confirmation prompt}';
    protected $description = 'Reset the panel to default state (drops all data, re-migrates, and re-seeds)';

    public function handle()
    {
        if (!file_exists(base_path('.env'))) {
            if (!file_exists(base_path('.env.example'))) {
                $this->error('.env file missing and no .env.example found.');
                return 1;
            }
            copy(base_path('.env.example'), base_path('.env'));
            $this->info('.env created from .env.example — edit it with your credentials before starting the panel.');
        }

        if (!$this->option('force') && !$this->confirm('This will DROP ALL TABLES and delete all data. Are you sure?')) {
            $this->info('Reset cancelled.');
            return 0;
        }

        $this->warn('Dropping all tables and re-running migrations...');
        Artisan::call('migrate:fresh', ['--force' => true, '--seed' => false]);
        $this->line(Artisan::output());

        $this->warn('Seeding default data...');

        $defaults = ['panel:name' => 'DragoraPanel', 'panel:locale' => 'en', 'theme:default' => 'dark'];
        foreach ($defaults as $key => $value) {
            Setting::set($key, $value);
        }
        $this->info('Default settings applied.');

        $this->warn('Clearing cached files...');
        foreach (['config:clear', 'view:clear', 'route:clear', 'cache:clear'] as $cmd) {
            try { Artisan::call($cmd); } catch (\Throwable $e) {}
        }

        if (!file_exists(public_path('storage'))) {
            $this->warn('Creating storage symlink...');
            Artisan::call('storage:link');
            $this->line(Artisan::output());
        }

        $this->warn('Regenerating web server setup configs...');
        $root = str_replace('\\', '/', base_path());
        $target = storage_path('app/setup');
        if (!is_dir($target)) mkdir($target, 0755, true);

        $stubs = [
            'nginx/nossl.conf.stub'   => ['nginx-nossl.conf',      ['your-domain.com', $root, '', '', '']],
            'nginx/ssl.conf.stub'     => ['nginx-ssl.conf',        ['your-domain.com', $root, '/etc/letsencrypt/live/your-domain.com/fullchain.pem', '/etc/letsencrypt/live/your-domain.com/privkey.pem', '']],
            'apache/nossl.conf.stub'  => ['apache-nossl.conf',     ['your-domain.com', $root, '', '', '']],
            'apache/ssl.conf.stub'    => ['apache-ssl.conf',       ['your-domain.com', $root, '/etc/letsencrypt/live/your-domain.com/fullchain.pem', '/etc/letsencrypt/live/your-domain.com/privkey.pem', '']],
            'caddy/nossl.Caddyfile.stub' => ['caddy-nossl.Caddyfile', ['your-domain.com:80', $root, '', '', '']],
            'caddy/ssl.Caddyfile.stub'   => ['caddy-ssl.Caddyfile',   ['your-domain.com', $root, '', '', 'admin@your-domain.com']],
        ];
        $search = ['{{domain}}', '{{root}}', '{{ssl_cert}}', '{{ssl_key}}', '{{ssl_email}}'];

        foreach ($stubs as $stub => [$out, $vals]) {
            $path = storage_path('app/webserver/' . $stub);
            if (!file_exists($path)) continue;
            $c = str_replace($search, $vals, file_get_contents($path));
            file_put_contents("$target/$out", $c);
            $this->line("  $out");
        }

        $this->line('');
        $this->info('Panel has been reset successfully.');
        $this->line('  Panel URL:   ' . config('app.url', 'http://localhost'));
        $this->line('');

        return 0;
    }
}
