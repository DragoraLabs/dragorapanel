<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;

class PageController extends Controller
{
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

    public function index()
    {
        if (Setting::get('panel:maintenance', '0') === '1') {
            return redirect('/maintenance?back=/');
        }
        $defaults = [
            'topbar_type' => 'default',
            'nav_home_text' => 'home',
            'nav_features_text' => 'features',
            'nav_testimonials_text' => 'testimonials',
            'features_label' => 'features',
            'features_icon' => 'fa-cube',
            'testimonials_label' => 'testimonials',
            'testimonial_icon' => 'fa-quote-right',
            'hero_title' => 'server management,<br><span class="highlight">simplified<svg viewBox="0 0 80 12" preserveAspectRatio="none"><path d="M0,8 Q10,4 20,8 T40,8 T60,8 T80,8" stroke="var(--accent)" fill="none" stroke-width="2.5" opacity=".4"/></svg></span>',
            'hero_subtitle' => 'Full-featured game server management panel with real-time console, file manager, database administration, and automated backups — all from your browser.',
            'login_text' => 'log in',
            'hero_btn1_text' => 'get started',
            'hero_btn2_text' => 'explore features',
            'stat1_num' => '15', 'stat1_label' => 'servers online', 'stat1_icon' => 'fa-server',
            'stat2_num' => '2.5K', 'stat2_label' => 'active users', 'stat2_icon' => 'fa-users',
            'stat3_num' => '99.9%', 'stat3_label' => 'uptime SLA', 'stat3_icon' => 'fa-chart-line',
            'card1_label' => 'servers running', 'card1_value' => '3 active', 'card1_icon' => 'fa-desktop',
            'card2_label' => 'last backup', 'card2_value' => '2m ago', 'card2_icon' => 'fa-database',
            'card3_label' => 'players online', 'card3_value' => '23 connected', 'card3_icon' => 'fa-gamepad',
            'card4_label' => 'system status', 'card4_value' => 'operational', 'card4_icon' => 'fa-check-circle',
            'features_header' => 'everything you need to manage<br>your game servers',
            'features_subtitle' => 'A comprehensive panel designed for server administrators who need reliability and control.',
            'features' => [
                ['icon'=>'fa-terminal','title'=>'live console','text'=>'Real-time terminal access to your server. Execute commands, monitor output, and manage your server directly from the browser.','note'=>'full TTY support with command history','tall'=>true,'accent'=>false],
                ['icon'=>'fa-folder-open','title'=>'file manager','text'=>'Full file system access with drag-and-drop upload, inline editing, and directory management.','note'=>'edit config files directly in the browser','tall'=>false,'accent'=>true],
                ['icon'=>'fa-database','title'=>'databases','text'=>'Provision MySQL databases with one click. Includes phpMyAdmin for advanced management.','note'=>'automatic user and permission setup','tall'=>false,'accent'=>false],
                ['icon'=>'fa-cloud-upload-alt','title'=>'automated backups','text'=>'Schedule automatic backups with configurable retention. Manual snapshots available at any time.','note'=>'daily, weekly, and manual backup modes','tall'=>true,'accent'=>false],
                ['icon'=>'fa-puzzle-piece','title'=>'plugin manager','text'=>'Upload, enable, disable, and configure plugins through an intuitive interface.','note'=>'compatible with Bukkit, Spigot, and Paper','tall'=>false,'accent'=>false],
                ['icon'=>'fa-shield-alt','title'=>'authentication','text'=>'Multi-provider authentication with Google, Discord, and email/password login. Role-based access control included.','note'=>'supports OAuth2, SSO, and 2FA','tall'=>true,'accent'=>true],
            ],
            'testimonial_quote' => 'We evaluated several management panels before deploying DragoraPanel across our infrastructure. The intuitive interface and reliable console access made it the clear choice for our community.',
            'testimonial_author' => 'Alex Chen',
            'testimonial_handle' => 'Server Administrator · MC Network',
            'cta_title' => 'ready to get started?',
            'cta_text' => 'Deploy your first server in minutes. No credit card required.',
            'cta_btn_text' => 'start free trial',
            'footer_text' => 'DragoraPanel &copy; 2026 &mdash; Game Server Management Platform',
        ];
        $raw = Setting::get('design:page', '{}');
        $saved = json_decode($raw, true) ?? [];
        $design = array_merge($defaults, $saved);
        return view('index', [
            'panelName' => Setting::get('panel:name', 'DragoraPanel'),
            'panelLogo' => $this->logoUrl(),
            'design' => $design,
        ]);
    }

    public function login()
    {
        return view('auth.login', [
            'panelName' => Setting::get('panel:name', 'DragoraPanel'),
            'panelLogo' => $this->logoUrl(),
            'googleEnabled' => Setting::get('auth:google', '1') === '1',
            'discordEnabled' => Setting::get('auth:discord', '1') === '1',
            'adsEnabled' => Setting::get('ads:enabled', '0') === '1',
            'adsHtml' => Setting::get('ads:html', ''),
        ]);
    }

    public function panel()
    {
        return response()
            ->view('panel', [
                'panelName' => Setting::get('panel:name', 'DragoraPanel'),
                'panelLogo' => $this->logoUrl(),
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }
}
