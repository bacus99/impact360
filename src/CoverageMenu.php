<?php

/**
 * Impact360 - Coverage Gaps menu entry (Plugins → Coverage Gaps → front/coverage.php)
 *
 * @license   GPL-3.0-or-later
 */

namespace GlpiPlugin\Impact360;

use CommonGLPI;

class CoverageMenu extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return __('Coverage Gaps', 'impact360');
    }

    public static function getMenuName(): string
    {
        return self::getTypeName();
    }

    public static function getIcon(): string
    {
        return 'ti ti-radar-2';
    }

    /** Visible when the module is on and at least one source is readable. */
    public static function canView(): bool
    {
        return Config::isModuleEnabled('coverage') && CoverageMatrix::usableSources() !== [];
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
            'page'  => '/plugins/impact360/front/coverage.php',
            'icon'  => self::getIcon(),
        ];
    }
}
