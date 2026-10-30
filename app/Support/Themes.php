<?php

namespace App\Support;

/** Colour themes a library can pick. Each one replaces the `brand` colour scale used across the panel. */
class Themes
{
    public const DEFAULT = 'default';

    /** shades: the brand scale (50-900); swatch: the preview gradient; sb: sidebar gradient (top, middle, bottom). */
    public const ALL = [
        'default' => ['label' => 'Default', 'shades' => ['#eff6ff', '#dbeafe', '#bfdbfe', '#93c5fd', '#60a5fa', '#3b82f6', '#2563eb', '#1d4ed8', '#1e40af', '#1e3a8a'],
            'swatch' => ['#0f172a', '#2563eb'], 'sb' => ['#0b1226', '#111c3a', '#0f172a'], 'accent' => ['#4338ca', '#7c3aed']],
        'classic' => ['label' => 'Classic', 'shades' => ['#effaf3', '#d8f3e2', '#b4e6c9', '#82d3a8', '#52bb83', '#34a96b', '#2f9e5f', '#237a49', '#1f613c', '#1b5033'],
            'swatch' => ['#1f613c', '#52bb83'], 'sb' => ['#0b3d24', '#1f613c', '#134e4a'], 'accent' => ['#16a34a', '#0d9488']],
        'ocean' => ['label' => 'Ocean', 'shades' => ['#ecfeff', '#cffafe', '#a5f3fc', '#67e8f9', '#22d3ee', '#06b6d4', '#0891b2', '#0e7490', '#155e75', '#164e63'],
            'swatch' => ['#0e7490', '#1d9bf0'], 'sb' => ['#083344', '#155e75', '#312e81']],
        'sunset' => ['label' => 'Sunset', 'shades' => ['#fff7ed', '#ffedd5', '#fed7aa', '#fdba74', '#fb923c', '#f97316', '#ea580c', '#c2410c', '#9a3412', '#7c2d12'],
            'swatch' => ['#ea580c', '#e11d48'], 'sb' => ['#7c2d12', '#9a3412', '#581c87']],
        'forest' => ['label' => 'Forest', 'shades' => ['#f1f8f2', '#dcefdf', '#b9dfc0', '#8ac79a', '#55a96d', '#2f8a4d', '#1f7340', '#185c34', '#144a2b', '#103c24'],
            'swatch' => ['#14532d', '#84cc16'], 'sb' => ['#052e16', '#166534', '#134e4a']],
        'royal' => ['label' => 'Royal', 'shades' => ['#f5f3ff', '#ede9fe', '#ddd6fe', '#c4b5fd', '#a78bfa', '#8b5cf6', '#7c3aed', '#6d28d9', '#5b21b6', '#4c1d95'],
            'swatch' => ['#7c3aed', '#3b5bdb'], 'sb' => ['#2e1065', '#4c1d95', '#831843']],
    ];
    private const STEPS = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900];

    /** Themes libraries may choose from (the platform can switch some off; none ticked means all). */
    public static function enabled(): array
    {
        $on = array_values(array_intersect(array_keys(self::ALL), (array) BillingSettings::get('themes_enabled')));

        return $on ?: array_keys(self::ALL);
    }

    /** The theme a library without its own choice gets. */
    public static function platformDefault(): string
    {
        $d = (string) BillingSettings::get('default_theme');

        return in_array($d, self::enabled(), true) ? $d : self::enabled()[0];
    }

    /** Theme keys to offer a library: the enabled ones, plus the one it already uses. */
    public static function pickable(string $current): array
    {
        return array_values(array_unique(array_merge(self::enabled(), isset(self::ALL[$current]) ? [$current] : [])));
    }

    /** The theme in force for the current library (or the one given). */
    public static function key(?string $key = null): string
    {
        $key ??= (string) LibrarySettings::get('theme');

        return $key !== '' && isset(self::ALL[$key]) ? $key : self::platformDefault();
    }
    /** The theme a platform admin sees in the admin panel: their own choice, else the platform default. */
    public static function adminKey(?\App\Models\User $user): string
    {
        $key = (string) $user?->admin_theme;

        return $key !== '' && isset(self::ALL[$key]) ? $key : self::platformDefault();
    }

    /** Every theme as `:root[data-theme="x"]{...}`, so switching is just changing the attribute on <html>. */
    public static function cssAll(): string
    {
        $css = '';
        foreach (self::ALL as $key => $t) {
            $vars = '';
            foreach (self::STEPS as $i => $step) {
                $vars .= "--color-brand-{$step}:{$t['shades'][$i]};";
            }
            $acc = $t['accent'] ?? $t['swatch'];
            $vars .= "--sb-from:{$t['sb'][0]};--sb-mid:{$t['sb'][1]};--sb-to:{$t['sb'][2]};--sb-a:{$acc[0]};--sb-b:{$acc[1]};";
            $css .= ":root[data-theme=\"{$key}\"]{{$vars}}";
        }

        return $css;
    }
    /** CSS gradient used for the swatch preview. */
    public static function swatch(string $key): string
    {
        $t = self::ALL[$key]['swatch'];

        return "linear-gradient(135deg,{$t[0]},{$t[1]})";
    }
}
