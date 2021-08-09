<?php

namespace Nails\Invoice\Driver\Payment\AuthorizeDotNet\Helper;

/**
 * Class Name
 *
 * @package Nails\Invoice\Driver\Payment\AuthorizeDotNet\Helper
 */
class Name
{
    /**
     * Attempts to dissect a name into its distinct parts
     *
     * @param string $sName The name to dissect
     *
     * @return array
     */
    public static function getParts(string $sName): array
    {
        if (preg_match('/^((m(r|s|rs|x|iss))|dr|master|sir|lady|madam|dame|lord|esq|rev)\.? /i', $sName)) {

            [$sTitle, $sFirstName, $sLastName] = array_pad(explode(' ', $sName, 3), 3, null);

            if (empty($sLastName) && !empty($sFirstName)) {

                //  This supports "Mrs Test" type scenarios
                $sLastName  = $sFirstName;
                $sFirstName = null;
            }

        } else {
            $sTitle = null;
            [$sFirstName, $sLastName] = array_pad(explode(' ', $sName, 2), 2, null);
        }

        return array_map(
            function (?string $sPart) {
                return rtrim($sPart, '. ') ?: null;
            },
            [
                $sTitle,
                $sFirstName,
                $sLastName,
            ]
        );
    }
}
