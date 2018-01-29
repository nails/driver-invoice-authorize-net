<?php

/**
 * Authorize.net payment Driver
 *
 * @package     Nails
 * @subpackage  driver-invoice-authorize-net
 * @category    Driver
 * @author      Nails Dev Team
 * @link
 */

namespace Nails\Invoice\Driver\Payment;

use Nails\Environment;
use Nails\Factory;
use Nails\Invoice\Driver\PaymentBase;
use Nails\Invoice\Exception\DriverException;
use net\authorize\api\constants\ANetEnvironment as AuthNetConstants;
use net\authorize\api\contract\v1 as AuthNetAPI;
use net\authorize\api\controller as AuthNetController;

class AuthorizeDotNet extends PaymentBase
{
    const AUTH_NET_RESPONSE_OK = 'Ok';

    // --------------------------------------------------------------------------

    /**
     * The mode the API should be run in
     * @var string
     */
    protected $sApiMode;

    /**
     * The Authorize.Net Authentication object
     * @var AuthNetAPI\MerchantAuthenticationType
     */
    protected $oAuthentication;

    // --------------------------------------------------------------------------

    /**
     * AuthorizeDotNet constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->sApiMode = Environment::is('PRODUCTION') ? AuthNetConstants::PRODUCTION : AuthNetConstants::SANDBOX;

        $this->oAuthentication = new AuthNetAPI\MerchantAuthenticationType();
        $this->oAuthentication->setName($this->getSetting('sLoginId'));
        $this->oAuthentication->setTransactionKey($this->getSetting('sTransactionKey'));
    }

    // --------------------------------------------------------------------------

    /**
     * Returns whether the driver is available to be used against the selected invoice
     * @return boolean
     */
    public function isAvailable($oInvoice)
    {
        return true;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns whether the driver uses a redirect payment flow or not.
     * @return boolean
     */
    public function isRedirect()
    {
        return false;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the payment fields the driver requires, use static::PAYMENT_FIELDS_CARD for basic credit
     * card details.
     * @return mixed
     */
    public function getPaymentFields()
    {
        return static::PAYMENT_FIELDS_CARD;
    }

    // --------------------------------------------------------------------------

    /**
     * Initiate a payment
     *
     * @param  integer   $iAmount      The payment amount
     * @param  string    $sCurrency    The payment currency
     * @param  \stdClass $oData        The driver data object
     * @param  \stdClass $oCustomData  The custom data object
     * @param  string    $sDescription The charge description
     * @param  \stdClass $oPayment     The payment object
     * @param  \stdClass $oInvoice     The invoice object
     * @param  string    $sSuccessUrl  The URL to go to after successful payment
     * @param  string    $sFailUrl     The URL to go to after failed payment
     * @param  string    $sContinueUrl The URL to go to after payment is completed
     *
     * @return \Nails\Invoice\Model\ChargeResponse
     */
    public function charge(
        $iAmount,
        $sCurrency,
        $oData,
        $oCustomData,
        $sDescription,
        $oPayment,
        $oInvoice,
        $sSuccessUrl,
        $sFailUrl,
        $sContinueUrl
    ) {

        $oChargeResponse = Factory::factory('ChargeResponse', 'nailsapp/module-invoice');

        try {

            /**
             * If a payment_profile_id or customer_profile_id has been supplied then use these over
             * any supplied card details.
             */

            $sPaymentProfileId  = property_exists($oCustomData, 'payment_profile_id') ? $oCustomData->payment_profile_id : null;
            $sCustomerProfileId = property_exists($oCustomData, 'customer_profile_id') ? $oCustomData->customer_profile_id : null;

            if ($sPaymentProfileId || $sCustomerProfileId) {
                if (empty($oCustomData->payment_profile_id)) {
                    throw new DriverException('A Payment Profile ID must be supplied.');
                } elseif (empty($oCustomData->customer_profile_id)) {
                    throw new DriverException('A Customer Profile ID must be supplied.');
                }

                $oPaymentProfile = new AuthNetAPI\PaymentProfileType();
                $oPaymentProfile->setPaymentProfileId($oCustomData->payment_profile_id);

                $oCustomerProfile = new AuthNetAPI\CustomerProfilePaymentType();
                $oCustomerProfile->setCustomerProfileId($oCustomData->customer_profile_id);
                $oCustomerProfile->setPaymentProfile($oPaymentProfile);

            } else {

            }

            dumpanddie($oData, $oCustomData);

            $oCharge = new AuthNetAPI\TransactionRequestType();
            $oCharge->setTransactionType('authCaptureTransaction');
            $oCharge->setCurrencyCode($sCurrency);
            $oCharge->setAmount($iAmount / 100);
            $oCharge->setProfile($oCustomerProfile);

            $oApiRequest = new AuthNetAPI\CreateTransactionRequest();
            $oApiRequest->setMerchantAuthentication($this->oAuthentication);
            $oApiRequest->setTransactionRequest($oCharge);

            $oApiController = new AuthNetController\CreateTransactionController($oApiRequest);
            $oResponse      = $oApiController->executeWithApiResponse($this->sApiMode);

            if ($oResponse->getMessages()->getResultCode() === static::AUTH_NET_RESPONSE_OK) {

                $oChargeResponse->setStatusComplete();
                $oChargeResponse->setTxnId($oResponse->getTransactionResponse()->getTransId());

            } else {

                $oChargeResponse->setStatusFailed(
                    implode("\n\t-", array_map(
                        function ($oMessage) {
                            return $oMessage->getCode() . ': ' . $oMessage->getText() . "\n";
                        },
                        $oResponse->getMessages()->getMessage()
                    )),
                    0,
                    'The gateway rejected the request, you may wish to try again.'
                );
            }

        } catch (\Exception $e) {

            $oChargeResponse->setStatusFailed(
                $e->getMessage(),
                $e->getCode(),
                'An error occurred while executing the request.'
            );
        }

        return $oChargeResponse;
    }

    // --------------------------------------------------------------------------

    /**
     * Complete the payment
     *
     * @param  \stdClass $oPayment  The Payment object
     * @param  \stdClass $oInvoice  The Invoice object
     * @param  array     $aGetVars  Any $_GET variables passed from the redirect flow
     * @param  array     $aPostVars Any $_POST variables passed from the redirect flow
     *
     * @return \Nails\Invoice\Model\CompleteResponse
     */
    public function complete($oPayment, $oInvoice, $aGetVars, $aPostVars)
    {
        $oCompleteResponse = Factory::factory('CompleteResponse', 'nailsapp/module-invoice');
        $oCompleteResponse->setStatusComplete();
        return $oCompleteResponse;
    }

    // --------------------------------------------------------------------------

    /**
     * Issue a refund for a payment
     *
     * @param  string    $sTxnId      The original transaction's ID
     * @param  integer   $iAmount     The amount to refund
     * @param  string    $sCurrency   The currency in which to refund
     * @param  \stdClass $oCustomData The custom data object
     * @param  string    $sReason     The refund's reason
     * @param  \stdClass $oPayment    The payment object
     * @param  \stdClass $oInvoice    The invoice object
     *
     * @return \Nails\Invoice\Model\RefundResponse
     */
    public function refund($sTxnId, $iAmount, $sCurrency, $oCustomData, $sReason, $oPayment, $oInvoice)
    {
        $oRefundResponse = Factory::factory('RefundResponse', 'nailsapp/module-invoice');

        try {

            if (!isset($oCustomData->payment_profile_id)) {
                throw new DriverException('A Payment Profile ID must be supplied.');
            }

            if (!isset($oCustomData->customer_profile_id)) {
                throw new DriverException('The supplied Payment Profile ID cannot be used without an accompanying Customer Profile ID');
            }

            $oPaymentProfile = new AuthNetAPI\PaymentProfileType();
            $oPaymentProfile->setPaymentProfileId($oCustomData->payment_profile_id);

            $oCustomerProfile = new AuthNetAPI\CustomerProfilePaymentType();
            $oCustomerProfile->setCustomerProfileId($oCustomData->customer_profile_id);
            $oCustomerProfile->setPaymentProfile($oPaymentProfile);

            $oCharge = new AuthNetAPI\TransactionRequestType();
            $oCharge->setTransactionType('refundTransaction');
            $oCharge->setRefTransId();
            $oCharge->setCurrencyCode($sCurrency);
            $oCharge->setAmount($iAmount / 100);
            $oCharge->setProfile($oCustomerProfile);

            $oApiRequest = new AuthNetAPI\CreateTransactionRequest();
            $oApiRequest->setMerchantAuthentication($this->oAuthentication);
            $oApiRequest->setTransactionRequest($oCharge);

            $oApiController = new AuthNetController\CreateTransactionController($oApiRequest);
            $oResponse      = $oApiController->executeWithApiResponse($this->sApiMode);

            if ($oResponse->getMessages()->getResultCode() === static::AUTH_NET_RESPONSE_OK) {

                $oRefundResponse->setStatusComplete();
                $oRefundResponse->setTxnId($oResponse->getTransactionResponse()->getTransId());

            } else {

                //  @todo: handle errors returned by the Stripe Client/API
                $oRefundResponse->setStatusFailed(
                    null,
                    0,
                    'The gateway rejected the request, you may wish to try again.'
                );
            }

        } catch (\Exception $e) {

            $oRefundResponse->setStatusFailed(
                $e->getMessage(),
                $e->getCode(),
                'An error occurred while executing the request.'
            );
        }

        return $oRefundResponse;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the mode to be used when running an Authorize.Net SDK controller.
     * @return string
     */
    public function getApiMode()
    {
        return $this->sApiMode;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns a pre-configured authentication object to be used with Authorize.Net SDK requests.
     * @return \net\authorize\api\contract\v1\MerchantAuthenticationType
     */
    public function getAuthentication()
    {
        return clone $this->oAuthentication;
    }

    // --------------------------------------------------------------------------

    /**
     * Adds a payment source to a customer
     *
     * @param  $iCustomerId  integer      The customer ID to associate the payment source with
     * @param  $mSourceData  string|array The payment source data to pass to Stripe; a token or an associative array
     * @param  $sSourceLabel string       The label (or nickname) to give the card
     *
     * @return \stdClass                  The source object
     * @throws DriverException
     */
    public function addPaymentSource($iCustomerId, $mSourceData, $sSourceLabel = '')
    {
        //  @todo (Pablo - 2018-01-29) - Create source with Authorize.Net

        $oAuthNetSourceModel = Factory::model('Source', 'nailsapp/driver-invoice-authorize-net');
        $oSource             = $oAuthNetSourceModel->create(
            [
                'label'       => $sSourceLabel ?: $oSource->brand . ' card ending in ' . $oSource->last4,
                'customer_id' => $iCustomerId,
                'stripe_id'   => $oSource->id,
                'last4'       => $oSource->last4,
                'brand'       => $oSource->brand,
                'exp_month'   => $oSource->exp_month,
                'exp_year'    => $oSource->exp_year,
                'name'        => $oSource->name,
            ],
            true
        );

        if (!$oSource) {
            throw new DriverException(
                'Failed to save payment source. ' . $oAuthNetSourceModel->lastError()
            );
        }

        return $oSource;
    }

    // --------------------------------------------------------------------------

    /**
     * Deletes a customer payment source
     *
     * @param integer $iCustomerId The customer ID to associate the payment source with
     * @param integer $iSourceId   The payment source ID
     *
     * @return bool
     * @throws DriverException
     */
    public function removePaymentSource($iCustomerId, $iSourceId)
    {
        $oAuthNetSourceModel = Factory::model('Source', 'nailsapp/driver-invoice-authorize-net');
        $aSources            = $oAuthNetSourceModel->getAll([
            'where' => [
                ['id', $iSourceId],
                ['customer_id', $iCustomerId],
            ],
        ]);

        if (count($aSources) !== 1) {
            throw new DriverException('Not a valid payment source for customer #' . $iCustomerId);
        }

        $oSource = reset($aSources);

        //  @todo (Pablo - 2018-01-29) - Delete from Authorze.Net

        return $oAuthNetSourceModel->delete($oSource->id);
    }

    // --------------------------------------------------------------------------

    /**
     * Returns an array of Authorize.Net payment sources for a particular customer ID
     *
     * @param $iCustomerId integer The customer ID to retrieve for
     *
     * @return array
     */
    public function getPaymentSources($iCustomerId)
    {
        $oAuthNetSourceModel = Factory::model('Source', 'nailsapp/driver-invoice-authorize-net');
        return $oAuthNetSourceModel->getAll([
            'where' => [
                ['customer_id', $iCustomerId],
            ],
        ]);
    }
}
