<?php

/**
 * Impact360 - CVE Exposure menu entry
 *
 * Adds a "CVE Exposure" entry under the top-level Plugins menu (its own root
 * entry, alongside Menu::class's "Application Health" — there's no single CI
 * to hang a fleet-wide board off, so it's not a tab) that opens
 * front/cve.php. Registered via
 * $PLUGIN_HOOKS['menu_toadd']['impact360'] = ['plugins' => [Menu::class, CveMenu::class], ...].
 *
 * @license   GPL-3.0-or-later
 */

namespace GlpiPlugin\Impact360;

use CommonGLPI;

class CveMenu extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return __('CVE Exposure', 'impact360');
    }

    public static function getMenuName(): string
    {
        return self::getTypeName();
    }

    public static function getIcon(): string
    {
        return 'ti ti-bug';
    }

    /** Gate on at least one readable scanner source — otherwise there's nothing to show. */
    public static function canView(): bool
    {
        return CveExposure::usableSources() !== [];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }

        return [
            'title' => self::getMenuName(),
            'page'  => '/plugins/impact360/front/cve.php',
            'icon'  => self::getIcon(),
        ];
    }
}
