<?php

/**
 * Impact360 - menu entry
 *
 * Adds an "Application Health" entry under the top-level Plugins menu (its own
 * root entry, not under Assets) that opens the portfolio board
 * (front/portfolio.php). Registered via
 * $PLUGIN_HOOKS['menu_toadd']['impact360'] = ['plugins' => Menu::class].
 *
 * @license   GPL-3.0-or-later
 */

namespace GlpiPlugin\Impact360;

use Appliance;
use CommonGLPI;

class Menu extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return __('Application Health', 'impact360');
    }

    public static function getMenuName(): string
    {
        return self::getTypeName();
    }

    public static function getIcon(): string
    {
        return 'ti ti-heartbeat';
    }

    /** The board is entity-scoped server-side; gate the entry on Appliance READ. */
    public static function canView(): bool
    {
        return (bool) Appliance::canView();
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
            'page'  => '/plugins/impact360/front/portfolio.php',
            'icon'  => self::getIcon(),
        ];
    }
}
