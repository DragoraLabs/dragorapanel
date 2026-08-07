<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\Session;
use App\Models\User;
use App\Services\ApiAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebServerController extends Controller
{
    private function getUser(Request $request): ?User
    {
        return ApiAuth::user($request);
    }

    private function requireAdmin(Request $request): ?JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user || $user->role !== 'admin') {
            return response()->json(['success' => false, 'error' => 'Unauthorized'], 401);
        }
        return null;
    }

    private const CONFIG_TYPES = [
        [
            'id' => 'nginx:nossl',
            'label' => 'Nginx Without SSL',
            'server' => 'nginx',
            'ssl' => false,
            'file' => 'nginx/nossl.conf.stub',
            'filename' => 'nginx.conf',
        ],
        [
            'id' => 'nginx:ssl',
            'label' => 'Nginx With SSL',
            'server' => 'nginx',
            'ssl' => true,
            'file' => 'nginx/ssl.conf.stub',
            'filename' => 'nginx.conf',
        ],
        [
            'id' => 'apache:nossl',
            'label' => 'Apache Without SSL',
            'server' => 'apache',
            'ssl' => false,
            'file' => 'apache/nossl.conf.stub',
            'filename' => '.htaccess',
        ],
        [
            'id' => 'apache:ssl',
            'label' => 'Apache With SSL',
            'server' => 'apache',
            'ssl' => true,
            'file' => 'apache/ssl.conf.stub',
            'filename' => '.htaccess',
        ],
        [
            'id' => 'caddy:nossl',
            'label' => 'Caddy Without SSL',
            'server' => 'caddy',
            'ssl' => false,
            'file' => 'caddy/nossl.Caddyfile.stub',
            'filename' => 'Caddyfile',
        ],
        [
            'id' => 'caddy:ssl',
            'label' => 'Caddy With Automatic SSL',
            'server' => 'caddy',
            'ssl' => true,
            'file' => 'caddy/ssl.Caddyfile.stub',
            'filename' => 'Caddyfile',
        ],
    ];

    public function configs(): JsonResponse
    {
        return response()->json(['success' => true, 'configs' => self::CONFIG_TYPES]);
    }

    public function generate(Request $request): JsonResponse
    {
        $block = $this->requireAdmin($request); if ($block) return $block;

        $data = $request->validate([
            'config_id' => 'required|string',
            'domain' => 'required|string',
            'ssl_cert' => 'nullable|string',
            'ssl_key' => 'nullable|string',
            'ssl_email' => 'nullable|email',
        ]);

        $config = collect(self::CONFIG_TYPES)->firstWhere('id', $data['config_id']);
        if (!$config) {
            return response()->json(['success' => false, 'error' => 'Invalid config type'], 400);
        }

        $path = storage_path('app/webserver/' . $config['file']);
        if (!file_exists($path)) {
            return response()->json(['success' => false, 'error' => 'Config template not found'], 500);
        }

        $root = base_path();
        $content = file_get_contents($path);

        $replacements = [
            '{{domain}}' => $data['domain'],
            '{{root}}' => str_replace('\\', '/', $root),
            '{{ssl_cert}}' => $data['ssl_cert'] ?? '/etc/ssl/certs/' . $data['domain'] . '.pem',
            '{{ssl_key}}' => $data['ssl_key'] ?? '/etc/ssl/private/' . $data['domain'] . '-key.pem',
            '{{ssl_email}}' => $data['ssl_email'] ?? 'admin@' . $data['domain'],
        ];

        $content = str_replace(array_keys($replacements), array_values($replacements), $content);

        return response()->json([
            'success' => true,
            'content' => $content,
            'filename' => $config['filename'],
            'label' => $config['label'],
        ]);
    }

    private const WINGET_PACKAGES = [
        'nginx' => 'nginxinc.nginx',
        'apache' => 'ApacheLounge.httpd',
        'caddy' => 'CaddyServer.Caddy',
    ];

    private const CHOCO_PACKAGES = [
        'nginx' => 'nginx',
        'apache' => 'apache-httpd',
        'caddy' => 'caddy',
    ];

    public function install(Request $request): JsonResponse
    {
        $block = $this->requireAdmin($request); if ($block) return $block;

        $data = $request->validate(['server' => 'required|in:nginx,apache,caddy']);
        $server = $data['server'];

        $cmd = null;
        $winget = $this->which('winget');
        $choco = $this->which('choco');
        if ($winget) {
            $pkg = self::WINGET_PACKAGES[$server] ?? $server;
            $cmd = 'winget install --accept-source-agreements --accept-package-agreements ' . $pkg;
        } elseif ($choco) {
            $pkg = self::CHOCO_PACKAGES[$server] ?? $server;
            $cmd = 'choco install -y ' . $pkg;
        }

        if ($cmd === null) {
            return response()->json([
                'success' => false,
                'error' => 'No package manager found (winget or chocolatey required).',
            ], 400);
        }

        $which = $this->which($server === 'apache' ? 'httpd' : $server);
        if ($which) {
            return response()->json([
                'success' => false,
                'error' => ucfirst($server) . ' is already installed at: ' . $which,
            ], 400);
        }

        $output = [];
        $exitCode = 0;

        try {
            exec($cmd . ' 2>&1', $output, $exitCode);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => 'Installation failed: ' . $e->getMessage(),
            ], 500);
        }

        if ($exitCode !== 0) {
            return response()->json([
                'success' => false,
                'error' => 'Installation failed (exit code ' . $exitCode . '). Output: ' . implode("\n", $output),
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => ucfirst($server) . ' installed successfully.',
            'output' => implode("\n", $output),
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        $block = $this->requireAdmin($request); if ($block) return $block;

        $servers = ['nginx', 'apache' => 'httpd', 'caddy'];
        $result = [];

        foreach ($servers as $label => $binary) {
            if (is_int($label)) $label = $binary;
            $path = $this->which($binary);
            $service = $this->getServiceStatus($label);
            $result[$label] = [
                'installed' => $path !== null,
                'path' => $path,
                'service' => $service,
            ];
        }

        return response()->json(['success' => true, 'servers' => $result]);
    }

    private function which(string $name): ?string
    {
        $output = [];
        exec('where ' . $name . ' 2>NUL', $output, $code);
        if ($code === 0 && !empty($output)) return $output[0];

        $localAppData = getenv('LOCALAPPDATA') ?: 'C:\\Users\\' . getenv('USERNAME') . '\\AppData\\Local';
        $wingetPkgDir = $localAppData . '\\Microsoft\\WinGet\\Packages';

        $commonPaths = [
            'nginx' => [
                'C:\\Program Files\\nginx\\nginx.exe',
                'C:\\nginx\\nginx.exe',
                $wingetPkgDir . '\\nginxinc.nginx_Microsoft.Winget.Source_8wekyb3d8bbwe\\nginx-1.31.3\\nginx.exe',
            ],
            'httpd' => [
                'C:\\Program Files\\Apache24\\bin\\httpd.exe',
                'C:\\Apache24\\bin\\httpd.exe',
            ],
            'caddy' => [
                'C:\\Program Files\\Caddy\\caddy.exe',
                'C:\\caddy\\caddy.exe',
            ],
            'winget' => [],
            'choco' => [],
        ];

        if (isset($commonPaths[$name])) {
            foreach ($commonPaths[$name] as $path) {
                if (file_exists($path)) return $path;
            }
        }

        return null;
    }

    private function getServiceStatus(string $name): ?string
    {
        $output = [];
        exec('sc query ' . $name . ' 2>NUL', $output, $code);
        if ($code !== 0) return null;
        foreach ($output as $line) {
            if (str_contains($line, 'STATE')) {
                if (str_contains($line, 'RUNNING')) return 'running';
                if (str_contains($line, 'STOPPED')) return 'stopped';
                return 'unknown';
            }
        }
        return null;
    }

}
