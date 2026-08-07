<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;

class ErrorController extends Controller
{
    private const MESSAGES = [
        'maintenance' => 'site under maintenance',
        '404' => 'page not found',
        '403' => 'forbidden',
        '500' => 'internal server error',
        '502' => 'bad gateway',
        '503' => 'service unavailable',
        '505' => 'server connection failed',
    ];

    public function show(Request $request, string $code = '404')
    {
        $code = in_array($code, array_keys(self::MESSAGES)) ? $code : '404';
        $httpCode = $code === 'maintenance' ? 503 : (int) $code;

        $file = public_path('error.html');
        if (!file_exists($file)) {
            return response("Error page not found", $httpCode);
        }

        $html = file_get_contents($file);
        $inject = '<script>window.__errorCode=' . $httpCode . ';</script>';
        $html = str_replace('<script type="module">', $inject . '<script type="module">', $html);

        return response($html, $httpCode)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Retry-After', $code === 'maintenance' ? '3600' : '');
    }

    private function logoUrl(): string
    {
        $file = Setting::get('panel:logo', '');
        if ($file) {
            $filename = basename($file);
            if ($filename) return route('panel.logo', ['file' => $filename]);
        }
        $default = storage_path('app/images/logo.png');
        return file_exists($default) ? route('panel.logo.default') : '';
    }
}