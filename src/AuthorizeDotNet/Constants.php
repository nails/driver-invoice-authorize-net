<?php

/**
 * Authorize.net payment Driver constants
 *
 * @package     Nails
 * @subpackage  driver-invoice-authorize-net
 * @category    Driver
 * @author      Nails Dev Team
 * @link
 */

namespace Nails\Invoice\Driver\Payment\AuthorizeDotNet;

class Constants
{
    /**
     * The value of a successful response from the Authorize.Net API
     *
     * @type string
     */
    const API_RESPONSE_OK = 'Ok';

    /**
     * The transaction was approved
     *
     * @var int
     * @link https://developer.authorize.net/api/reference/responseCodes.html?code=1
     */
    const TRANSACTION_CODE_APPROVED = 1;

    /**
     * The transaction was declined
     *
     * @var int
     * @link https://developer.authorize.net/api/reference/responseCodes.html?code=2
     */
    const TRANSACTION_CODE_DECLINED = 2;

    /**
     * The transaction was declined due to referral to voice authorisation center
     *
     * @var int
     * @link https://developer.authorize.net/api/reference/responseCodes.html?code=3
     */
    const TRANSACTION_CODE_DECLINED_VOICE = 3;

    /**
     * The transaction was declined due to card require pickup
     *
     * @var int
     * @link https://developer.authorize.net/api/reference/responseCodes.html?code=4
     */
    const TRANSACTION_CODE_DECLINED_CARD_PICKUP = 4;

    /**
     * The transaction was declined due to an invalid amount being specified
     *
     * @var int
     * @link https://developer.authorize.net/api/reference/responseCodes.html?code=5
     */
    const TRANSACTION_CODE_DECLINED_INVALID_AMOUNT = 5;

    /**
     * The transaction was declined due to an invalid card number
     *
     * @var int
     * @link https://developer.authorize.net/api/reference/responseCodes.html?code=6
     */
    const TRANSACTION_CODE_DECLINED_INVALID_CARD_NUMBER = 6;

    /**
     * The transaction was declined due to an invalid expiritation date
     *
     * @var int
     * @link https://developer.authorize.net/api/reference/responseCodes.html?code=7
     */
    const TRANSACTION_CODE_DECLINED_INVALID_EXPIRY = 7;

    /**
     * The transaction was declined due to an expired card
     *
     * @var int
     * @link https://developer.authorize.net/api/reference/responseCodes.html?code=8
     */
    const TRANSACTION_CODE_DECLINED_CARD_EXPIRED = 8;
}
