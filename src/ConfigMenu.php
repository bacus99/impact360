<?php

/**
 * Impact360 - Setup menu entry for the configuration page.
 *
 * Adds "Impact360" under Setup → the config page (front/config.php), where the
 * Computer Dashboard health checks are selected. Registered via
 * $PLUGIN_HOOKS['menu_toadd']['impact360'] = ['config' => ConfigMenu::class].
 * (The board lives under Assets via Menu::class.)
 *
 * @license   GPL-3.0-or-later
 */

namespace GlpiPlugin\Impact360;

use CommonGLPI;
use Session;

class ConfigMenu extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return __('Impact360', 'impact360');
    }

    public static function getMenuName(): string
    {
        return self::getTypeName();
    }

    public static function getIcon(): string
    {
        return 'ti ti-affiliate';
    }

    /** Settings audience: super-admins (config UPDATE). The page re-checks. */
    public static function canView(): bool
    {
        return (bool) Session::haveRight('config', UPDATE);
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
            'page'  => '/plugins/impact360/front/config.php',
            'icon'  => self::getIcon(),
        ];
    }
}
