<?php

/**
 * This model interfaces with AuthorizeDotNet Customer Payment source objects
 *
 * @package     Nails
 * @subpackage  driver-invoice-authorizedotnet
 * @category    Model
 * @author      Nails Dev Team
 */

namespace Nails\Invoice\Driver\Payment\AuthorizeDotNet\Model;

use Nails\Common\Model\Base;

class Source extends Base
{
    /**
     * Construct Source
     */
    public function __construct()
    {
        parent::__construct();
        $this->table = NAILS_DB_PREFIX . 'driver_invoice_authorizedotnet_source';
    }

    // --------------------------------------------------------------------------

    /**
     * Return a payment source by its AuthorizeDotNet ID
     *
     * @param  string $sAuthorizeDotNetId The AuthorizeDotNet source ID to look up
     *
     * @return \stdClass|false
     */
    public function getByAuthorizeDotNetId($sAuthorizeDotNetId)
    {
        $aResults = $this->getAll(
            0,
            1,
            [
                'where' => [
                    [$this->getTableAlias(true) . 'authorizedotnet_id', $sAuthorizeDotNetId]
                ],
            ]
        );

        return count($aResults) === 1 ? $aResults[0] : false;
    }
}
