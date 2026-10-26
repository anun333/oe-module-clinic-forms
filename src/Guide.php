<?php

/**
 * Where the staff guide is: GUIDE.md at the installed version's tag, so a clinic on a given release
 * reads the guide written for that version. The pages link to it as Help, opening a new window,
 * since GitHub won't load inside OpenEMR's frame.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms;

final class Guide
{
    private const REPOSITORY = 'https://github.com/anun333/oe-module-clinic-forms';

    /** The guide at this module's version tag, from info.txt; the main branch's when it can't be read. */
    public static function url(string $infoFile = __DIR__ . '/../info.txt'): string
    {
        $info = is_file($infoFile) ? (string)file_get_contents($infoFile) : '';
        return preg_match('/ v(\d+\.\d+\.\d+)\b/', $info, $m) === 1
            ? self::REPOSITORY . '/blob/v' . $m[1] . '/GUIDE.md'
            : self::REPOSITORY . '/blob/main/GUIDE.md';
    }
}
