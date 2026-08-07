<?php

namespace App\Http\Controllers;

use App\Models\MarketplacePlugin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Public JSON API of the standalone marketplace site (store mode).
 * Consumed by the DragoraPanel to list and install approved plugins.
 */
class StoreApiController extends Controller
{
    private function approved(int $id): ?MarketplacePlugin
    {
        return MarketplacePlugin::where('id', $id)->where('status', 'approved')->first();
    }

    private function payload(MarketplacePlugin $p): array
    {
        return [
            'id' => $p->id,
            'unique_id' => $p->unique_id,
            'name' => $p->name,
            'version' => $p->version,
            'description' => $p->description,
            'license' => $p->license,
            'author' => $p->author ? trim($p->author->first_name . ' ' . $p->author->last_name) : '',
            'author_email' => $p->author->email ?? '',
            'icon' => $p->iconUrl(),
            'icon_url' => $p->iconUrl(),
            'downloads' => $p->downloads,
            'zip_url' => route('api.plugins.zip', ['id' => $p->id], true),
            'size' => $p->zipPath() && is_file($p->zipPath()) ? filesize($p->zipPath()) : 0,
            'approved_at' => $p->reviewed_at ? $p->reviewed_at->toIso8601String() : null,
        ];
    }

    /** Approved plugin list, optional search. */
    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $plugins = MarketplacePlugin::with('author:id,first_name,last_name')
            ->where('status', 'approved')
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w->where('name', 'like', "%$q%")->orWhere('description', 'like', "%$q%")))
            ->orderByDesc('downloads')->limit(200)->get()
            ->map(fn ($p) => $this->payload($p));
        return response()->json(['success' => true, 'plugins' => $plugins, 'count' => $plugins->count()]);
    }

    /** Single approved plugin. */
    public function show(Request $request, int $id): JsonResponse
    {
        $p = $this->approved($id);
        if (!$p) return response()->json(['success' => false, 'error' => 'Plugin not found.'], 404);
        return response()->json(['success' => true, 'plugin' => $this->payload($p)]);
    }

    /** Download the plugin zip (+1 download count). */
    public function zip(Request $request, int $id): BinaryFileResponse|JsonResponse
    {
        $p = $this->approved($id);
        if (!$p) return response()->json(['success' => false, 'error' => 'Plugin not found.'], 404);
        $zip = $p->zipPath();
        if (!$zip || !is_file($zip)) {
            return response()->json(['success' => false, 'error' => 'Plugin archive missing on disk.'], 500);
        }
        $p->increment('downloads');
        return response()->file($zip, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="' . $p->unique_id . '.zip"',
        ]);
    }

    /** Icon image of an approved plugin (or a 1x1 transparent pixel). */
    public function icon(Request $request, int $id)
    {
        $p = $this->approved($id);
        if (!$p) return abort(404);
        $glob = glob($p->assetDir() . '/icon.*');
        if (!$glob) return abort(404);
        $file = $glob[0];
        if (!is_file($file)) return abort(404);
        $ext = pathinfo($file, PATHINFO_EXTENSION);
        $mimes = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'svg' => 'image/svg+xml', 'webp' => 'image/webp'];
        return response()->file($file, ['Content-Type' => $mimes[$ext] ?? 'application/octet-stream']);
    }
}
