<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;

class PaperController extends Controller
{
    private const USER_AGENT = 'dragorapanel/1.0 (https://github.com/dragorapanel)';
    private const BASE = 'https://fill.papermc.io/v3/projects/paper';

    private static function client()
    {
        return Http::withHeaders(['User-Agent' => self::USER_AGENT])
            ->timeout(15)
            ->withoutVerifying();
    }

    public function versions(): JsonResponse
    {
        try {
            $resp = self::client()->get(self::BASE . '/versions');
            if (!$resp->successful()) {
                return response()->json(['success' => false, 'error' => 'Paper API returned ' . $resp->status()], 502);
            }
            $data = $resp->json();
            $versions = collect($data['versions'] ?? [])->map(fn ($v) => [
                'id' => $v['version']['id'] ?? null,
                'support' => $v['version']['support']['status'] ?? null,
                'java_minimum' => $v['version']['java']['version']['minimum'] ?? null,
            ])->filter(fn ($v) => $v['id'])->values();
            return response()->json(['success' => true, 'versions' => $versions]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => 'Failed to reach Paper API'], 502);
        }
    }

    public function builds(string $version): JsonResponse
    {
        try {
            $resp = self::client()->get(self::BASE . '/versions/' . urlencode($version) . '/builds');
            if ($resp->status() === 404) {
                return response()->json(['success' => false, 'error' => 'Version not found'], 404);
            }
            if (!$resp->successful()) {
                return response()->json(['success' => false, 'error' => 'Paper API returned ' . $resp->status()], 502);
            }
            $data = $resp->json();
            $builds = collect($data['builds'] ?? [])->map(fn ($b) => [
                'id' => $b['id'] ?? null,
                'channel' => $b['channel'] ?? null,
                'url' => $b['downloads']['server:default']['url'] ?? ($b['downloads']['application']['url'] ?? null),
                'sha256' => $b['downloads']['server:default']['checksums']['sha256'] ?? null,
            ])->filter(fn ($b) => $b['id'])->values();
            return response()->json(['success' => true, 'builds' => $builds]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => 'Failed to reach Paper API'], 502);
        }
    }

    public function vanillaVersions(): JsonResponse
    {
        try {
            $resp = self::client()->get('https://piston-meta.mojang.com/mc/game/version_manifest_v2.json');
            if (!$resp->successful()) {
                return response()->json(['success' => false, 'error' => 'Mojang API returned ' . $resp->status()], 502);
            }
            $data = $resp->json();
            $versions = collect($data['versions'] ?? [])
                ->filter(fn ($v) => ($v['type'] ?? '') === 'release')
                ->map(fn ($v) => ['id' => $v['id'] ?? null])
                ->filter(fn ($v) => $v['id'])
                ->values();
            return response()->json(['success' => true, 'versions' => $versions]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => 'Failed to reach Mojang API'], 502);
        }
    }
}
