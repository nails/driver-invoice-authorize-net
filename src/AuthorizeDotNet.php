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

use Nails\Common\Exception\NailsException;
use Nails\Currency\Resource\Currency;
use Nails\Environment;
use Nails\Factory;
use Nails\Invoice;
use Nails\Invoice\Driver\Payment\AuthorizeDotNet\Constants;
use Nails\Invoice\Driver\PaymentBase;
use Nails\Invoice\Exception\ChargeRequestException;
use Nails\Invoice\Exception\DriverException;
use Nails\Invoice\Factory\ChargeRequest;
use Nails\Invoice\Factory\ChargeResponse;
use Nails\Invoice\Factory\CompleteResponse;
use Nails\Invoice\Factory\RefundResponse;
use Nails\Invoice\Factory\ScaResponse;
use Nails\Invoice\Resource;
use net\authorize\api\constants\ANetEnvironment as AuthNetConstants;
use net\authorize\api\contract\v1 as AuthNetAPI;
use net\authorize\api\controller as AuthNetController;
use stdClass;

/**
 * Class AuthorizeDotNet
 *
 * @package Nails\Invoice\Driver\Payment
 */
class AuthorizeDotNet extends PaymentBase
{
    /**
     * Returns whether the driver is available to be used against the selected invoice
     *
     * @param Resource\Invoice $oInvoice The invoice being charged
     *
     * @return bool
     */
    public function isAvailable(Resource\Invoice $oInvoice): bool
    {
        return true;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the currencies which this driver supports, it will only be presented
     * when attempting to pay an invoice in a supported currency
     *
     * @return string[]|null
     */
    public function getSupportedCurrencies(): ?array
    {
        $sCode = appSetting('sSupportedCurrency', 'nails/driver-invoice-authorize-net');
        return $sCode ? [strtoupper($sCode)] : null;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns whether the driver uses a redirect payment flow or not.
     *
     * @return bool
     */
    public function isRedirect(): bool
    {
        return false;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the payment fields the driver requires, use static::PAYMENT_FIELDS_CARD for basic credit
     * card details.
     *
     * @return array|string
     */
    public function getPaymentFields()
    {
        return static::PAYMENT_FIELDS_CARD;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns any assets to load during checkout
     *
     * @return array
     */
    public function getCheckoutAssets(): array
    {
        return array_filter([
            Environment::is(Environment::ENV_PROD) ? [
                'https://js.authorize.net/v1/Accept.js',
                null,
                'JS',
            ] : null,
            Environment::not(Environment::ENV_PROD) ? [
                'https://jstest.authorize.net/v1/Accept.js',
                null,
                'JS',
            ] : null,
            [
                'checkout.min.js?' . implode('&', [
                    'hash=' . urlencode(md5($this->getSlug())) . '',
                    'key=' . urlencode($this->getEnvSetting('sPublicKey')) . '',
                    'loginId=' . urlencode($this->getEnvSetting('sLoginId')) . '',
                ]),
                $this->getSlug(),
                'JS',
            ],
        ]);
    }

    // --------------------------------------------------------------------------

    /**
     * Prepares a ChargeRequest object
     *
     * @param ChargeRequest $oChargeRequest The ChargeRequest object to prepare
     * @param array         $aData          Any data which was requested by getPaymentFields()
     *
     * @throws ChargeRequestException
     */
    public function prepareChargeRequest(
        ChargeRequest $oChargeRequest,
        array $aData
    ): void {
        $this->setChargeRequestFields(
            $oChargeRequest,
            $aData,
            [['key' => 'token']]
        );
    }

    // --------------------------------------------------------------------------

    /**
     * Initiate a payment
     *
     * @param int                           $iAmount      The payment amount
     * @param Currency                      $oCurrency    The payment currency
     * @param stdClass                      $oData        An array of driver data
     * @param Resource\Invoice\Data\Payment $oPaymentData The payment data object
     * @param string                        $sDescription The charge description
     * @param Resource\Payment              $oPayment     The payment object
     * @param Resource\Invoice              $oInvoice     The invoice object
     * @param string                        $sSuccessUrl  The URL to go to after successful payment
     * @param string                        $sErrorUrl    The URL to go to after failed payment
     * @param Resource\Source|null          $oSource      The saved payment source to use
     *
     * @return ChargeResponse
     */
    public function charge(
        int $iAmount,
        Currency $oCurrency,
        stdClass $oData,
        Resource\Invoice\Data\Payment $oPaymentData,
        string $sDescription,
        Resource\Payment $oPayment,
        Resource\Invoice $oInvoice,
        string $sSuccessUrl,
        string $sErrorUrl,
        bool $bCustomerPresent,
        Resource\Source $oSource = null
    ): ChargeResponse {

        /** @var ChargeResponse $oChargeResponse */
        $oChargeResponse = Factory::factory('ChargeResponse', Invoice\Constants::MODULE_SLUG);

        try {

            //  Begin creating the charge request
            $oCharge = new AuthNetAPI\TransactionRequestType();
            $oCharge->setTransactionType('authCaptureTransaction');

            // Create order information
            $oOrder = new AuthNetAPI\OrderType();
            $oOrder->setInvoiceNumber($oInvoice->ref);

            /**
             * If a payment_profile_id or customer_profile_id has been supplied then use these over
             * any supplied card details.
             */

            $sPaymentProfileId  = getFromArray('payment_profile_id', (array) $oPaymentData);
            $sCustomerProfileId = getFromArray('customer_profile_id', (array) $oPaymentData);
            $sToken             = getFromArray('token', (array) $oPaymentData);

            // --------------------------------------------------------------------------

            if ($sPaymentProfileId || $sCustomerProfileId) {
                $this->payUsingProfile($oCharge, $sPaymentProfileId, $sCustomerProfileId);
            } else {
                $this->payUsingToken($oCharge, $sToken);
            }

            $oCharge->setCurrencyCode($oCurrency->code);
            $oCharge->setAmount($iAmount / 100);
            $oCharge->setOrder($oOrder);

            $oApiRequest = new AuthNetAPI\CreateTransactionRequest();
            $oApiRequest->setMerchantAuthentication($this->getAuthentication());
            $oApiRequest->setRefId($oPayment->id);
            $oApiRequest->setTransactionRequest($oCharge);

            $oApiController = new AuthNetController\CreateTransactionController($oApiRequest);
            $oResponse      = $oApiController->executeWithApiResponse($this->getApiMode());

            if ($oResponse === null) {
                throw new DriverException(
                    'Received a null response from the payment gateway.'
                );
            }

            if ($oResponse->getMessages()->getResultCode() === Constants::API_RESPONSE_OK) {

                $oTransactionResponse = $oResponse->getTransactionResponse();
                $aErrors              = $oTransactionResponse->getErrors() ?? [];

                if ($oTransactionResponse === null) {
                    throw new DriverException(
                        'Received a null transaction response from the payment gateway.'
                    );
                } elseif (count($aErrors) > 0) {

                    $oError = reset($aErrors);

                    switch ((int) $oError->getErrorCode()) {
                        case Constants::TRANSACTION_CODE_DECLINED:
                            $sError     = 'The card was declined.';
                            $sUserError = $sError;
                            break;
                        case Constants::TRANSACTION_CODE_DECLINED_VOICE:
                            $sError     = 'The card was declined due to referral to voice authorisation centre.';
                            $sUserError = 'The card was declined.';
                            break;
                        case Constants::TRANSACTION_CODE_DECLINED_CARD_PICKUP:
                            $sError     = 'The card was declined due to card requiring pick up.';
                            $sUserError = 'The card was declined.';
                            break;
                        case Constants::TRANSACTION_CODE_DECLINED_INVALID_CARD_NUMBER:
                            $sError     = 'The card was declined due to an invalid card number.';
                            $sUserError = 'The card was declined.';
                            break;
                        case Constants::TRANSACTION_CODE_DECLINED_INVALID_EXPIRY:
                            $sError     = 'The card was declined due to an invalid expiry date.';
                            $sUserError = $sError;
                            break;
                        case Constants::TRANSACTION_CODE_DECLINED_INVALID_EXPIRY:
                            $sError     = 'The card is expired.';
                            $sUserError = $sError;
                            break;
                        default :
                            $sError     = 'The gateway rejected the request';
                            $sUserError = 'The gateway rejected the request, you may wish to try again.';
                            break;
                    }

                    $oChargeResponse->setStatusFailed(
                        $sError,
                        $oError->getErrorCode(),
                        $sUserError
                    );

                } else {
                    $oChargeResponse->setStatusComplete();
                    $oChargeResponse->setTransactionId($oTransactionResponse->getTransId());
                    $oChargeResponse->setFee($this->calculateFee($iAmount));
                }

            } else {

                $aGeneralErrors = $oResponse->getMessages()->getMessage();
                $oGeneralError  = reset($aGeneralErrors);
                $sSpecificError = '';

                if (is_callable([$oResponse->getTransactionResponse(), 'getErrors'])) {
                    $aErrors = $oResponse->getTransactionResponse()->getErrors();
                    $oError  = reset($aErrors);
                    if (!empty($oError)) {
                        $sSpecificError = ' ( ' . $oError->getErrorCode() . ': ' . $oError->getErrorText() . ')';
                    }
                }

                $oChargeResponse->setStatusFailed(
                    $oGeneralError->getText() . $sSpecificError,
                    $oGeneralError->getCode(),
                    'The gateway rejected the request, you may wish to try again.'
                );
            }

        } catch (DriverException $e) {
            $oChargeResponse->setStatusFailed(
                $e->getMessage(),
                $e->getCode(),
                'The gateway rejected the request, you may wish to try again.'
            );
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
     * Handles any SCA requests
     *
     * @param ScaResponse $oScaResponse The SCA Response object
     * @param array       $aData        Any saved SCA data
     * @param string      $sSuccessUrl  The URL to redirect to after authorisation
     *
     * @return ScaResponse
     */
    public function sca(ScaResponse $oScaResponse, array $aData, string $sSuccessUrl): ScaResponse
    {
        //  SCA is not supported... yet
    }

    // --------------------------------------------------------------------------

    /**
     * Complete the payment
     *
     * @param Resource\Payment $oPayment  The Payment object
     * @param Resource\Invoice $oInvoice  The Invoice object
     * @param array            $aGetVars  Any $_GET variables passed from the redirect flow
     * @param array            $aPostVars Any $_POST variables passed from the redirect flow
     *
     * @return CompleteResponse
     */
    public function complete(
        Resource\Payment $oPayment,
        Resource\Invoice $oInvoice,
        array $aGetVars,
        array $aPostVars
    ): CompleteResponse {
        /** @var CompleteResponse $oCompleteResponse */
        $oCompleteResponse = Factory::factory('CompleteResponse', Invoice\Constants::MODULE_SLUG);
        $oCompleteResponse->setStatusComplete();
        return $oCompleteResponse;
    }

    // --------------------------------------------------------------------------

    /**
     * Issue a refund for a payment
     *
     * @param string                        $sTransactionId The original transaction's ID
     * @param int                           $iAmount        The amount to refund
     * @param Currency                      $oCurrency      The currency in which to refund
     * @param Resource\Invoice\Data\Payment $oPaymentData   The payment data object
     * @param string                        $sReason        The refund's reason
     * @param Resource\Payment              $oPayment       The payment object
     * @param Resource\Invoice              $oInvoice       The invoice object
     *
     * @return RefundResponse
     */
    public function refund(
        string $sTransactionId,
        int $iAmount,
        Currency $oCurrency,
        Resource\Invoice\Data\Payment $oPaymentData,
        string $sReason,
        Resource\Payment $oPayment,
        Resource\Invoice $oInvoice
    ): RefundResponse {

        /** @var RefundResponse $oRefundResponse */
        $oRefundResponse = Factory::factory('RefundResponse', Invoice\Constants::MODULE_SLUG);

        try {

            //  Get the transaction details
            $oTransactionDetails = $this->getTransactionDetails($sTransactionId);

            // Create the payment data for a credit card
            $oCard = new AuthNetAPI\CreditCardType();
            $oCard->setCardNumber($oTransactionDetails->card->last4);
            $oCard->setExpirationDate('XXXX'); //  This is deliberate
            $oPayment = new AuthNetAPI\PaymentType();
            $oPayment->setCreditCard($oCard);

            $oCharge = new AuthNetAPI\TransactionRequestType();
            $oCharge->setTransactionType('refundTransaction');
            $oCharge->setRefTransId($sTransactionId);
            $oCharge->setCurrencyCode($oCurrency->code);
            $oCharge->setAmount($iAmount / 100);
            $oCharge->setPayment($oPayment);

            $oApiRequest = new AuthNetAPI\CreateTransactionRequest();
            $oApiRequest->setMerchantAuthentication($this->getAuthentication());
            //  @todo (Pablo - 2018-01-31) - set this to the ID fo the refund object
            //  $oApiRequest->setRefId(null);
            $oApiRequest->setTransactionRequest($oCharge);

            $oApiController = new AuthNetController\CreateTransactionController($oApiRequest);
            $oResponse      = $oApiController->executeWithApiResponse($this->getApiMode());

            if ($oResponse->getMessages()->getResultCode() === Constants::API_RESPONSE_OK) {

                $oRefundResponse->setStatusComplete();
                $oRefundResponse->setTransactionId($oResponse->getTransactionResponse()->getTransId());
                //  @todo (Pablo - 2018-01-31) - Calculate refunded fee
                //  $oRefundResponse->setFee($oStripeResponse->balance_transaction->fee * -1);

            } else {

                $aGeneralErrors = $oResponse->getMessages()->getMessage();
                $oGeneralError  = reset($aGeneralErrors);
                $sSpecificError = '';
                if (is_callable([$oResponse->getTransactionResponse(), 'getErrors'])) {
                    $aErrors = $oResponse->getTransactionResponse()->getErrors();
                    $oError  = reset($aErrors);
                    if (!empty($oError)) {
                        $sSpecificError = ' ( ' . $oError->getErrorCode() . ': ' . $oError->getErrorText() . ')';
                    }
                }

                $oRefundResponse->setStatusFailed(
                    $oGeneralError->getText() . $sSpecificError,
                    $oGeneralError->getCode(),
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
     *
     * @return string
     */
    public function getApiMode(): string
    {
        return Environment::is(Environment::ENV_PROD) ? AuthNetConstants::PRODUCTION : AuthNetConstants::SANDBOX;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns an Authorize.Net authentication object
     *
     * @return AuthNetAPI\MerchantAuthenticationType
     */
    public function getAuthentication(): AuthNetAPI\MerchantAuthenticationType
    {
        $oAuthentication = new AuthNetAPI\MerchantAuthenticationType();
        $oAuthentication->setName($this->getEnvSetting('sLoginId'));
        $oAuthentication->setTransactionKey($this->getEnvSetting('sTransactionKey'));
        return $oAuthentication;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns an environment orientated setting
     *
     * @param string|null $sProperty the proeprty to fetch
     *
     * @return mixed
     */
    protected function getEnvSetting(string $sProperty = null)
    {
        if (Environment::is(Environment::ENV_PROD)) {
            return parent::getSetting($sProperty);
        } else {
            return parent::getSetting($sProperty . 'Test');
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Complete the charge using an existing payment profile.
     *
     * @param AuthNetAPI\TransactionRequestType $oCharge     The Charge object
     * @param int                               $iProfileId  The Payment profile ID
     * @param int                               $iCustomerId The customer profile ID
     *
     * @throws DriverException
     */
    protected function payUsingProfile($oCharge, $iProfileId, $iCustomerId): void
    {
        if (empty($iProfileId)) {
            throw new DriverException('A Payment Profile ID must be supplied.');
        } elseif (empty($iCustomerId)) {
            throw new DriverException('A Customer Profile ID must be supplied.');
        }

        $oPaymentProfile = new AuthNetAPI\PaymentProfileType();
        $oPaymentProfile->setPaymentProfileId($iProfileId);

        $oCustomerProfile = new AuthNetAPI\CustomerProfilePaymentType();
        $oCustomerProfile->setCustomerProfileId($iCustomerId);
        $oCustomerProfile->setPaymentProfile($oPaymentProfile);

        $oCharge->setProfile($oCustomerProfile);
    }

    // --------------------------------------------------------------------------

    /**
     * Complete the charge using credit card details
     *
     * @param AuthNetAPI\TransactionRequestType $oCharge The Charge object
     * @param string                            $sToken  The payment token
     *
     * @throws DriverException
     */
    protected function payUsingToken($oCharge, $sToken): void
    {
        $oToken = json_decode($sToken);

        if (empty($oToken->dataDescriptor)) {
            throw new DriverException('Token must contain a dataDescriptor property.');
        } elseif (empty($oToken->dataValue)) {
            throw new DriverException('Token must contain a dataValue property.');
        }

        $oOpaqueData = new AuthNetAPI\OpaqueDataType();
        $oOpaqueData->setDataDescriptor($oToken->dataDescriptor);
        $oOpaqueData->setDataValue($oToken->dataValue);

        $oPayment = new AuthNetAPI\PaymentType();
        $oPayment->setOpaqueData($oOpaqueData);

        $oCharge->setPayment($oPayment);
    }

    // --------------------------------------------------------------------------

    /**
     * Calculates the transaction fee
     *
     * @param int $iAmount The value of the transaction
     *
     * @return int
     */
    protected function calculateFee($iAmount): int
    {
        $iFixedFee      = (int) $this->getSetting('iPerTransactionFee');
        $fPercentageFee = (float) $this->getSetting('iPerTransactionPercentage');
        return $iFixedFee + ($fPercentageFee / 100) * $iAmount;
    }

    // --------------------------------------------------------------------------

    /**
     * Queries Authorize.Net for details about a transaction
     *
     * @param string $sTransactionId The original transaction ID
     *
     * @return stdClass
     * @throws DriverException
     */
    protected function getTransactionDetails($sTransactionId): stdClass
    {
        $oApiRequest = new AuthNetAPI\GetTransactionDetailsRequest();
        $oApiRequest->setMerchantAuthentication($this->getAuthentication());
        $oApiRequest->setTransId($sTransactionId);

        $oApiController = new AuthNetController\GetTransactionDetailsController($oApiRequest);
        $oResponse      = $oApiController->executeWithApiResponse($this->getApiMode());

        if ($oResponse->getMessages()->getResultCode() === Constants::API_RESPONSE_OK) {

            $oTransaction = $oResponse->getTransaction();
            $oPayment     = $oTransaction->getPayment();
            $oCard        = $oPayment->getCreditCard();

            return (object) [
                'id'     => $oTransaction->getTransId(),
                'status' => $oTransaction->getTransactionStatus(),
                'card'   => (object) [
                    'last4' => substr($oCard->getCardNumber(), -4),
                ],
            ];

        } else {
            $aGeneralErrors = $oResponse->getMessages()->getMessage();
            $oGeneralError  = reset($aGeneralErrors);
            throw new DriverException($oGeneralError->getCode() . ': ' . $oGeneralError->getText());
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Creates a new payment source, returns a semi-populated source resource
     *
     * @param Resource\Source $oResource The Resouce object to update
     * @param array           $aData     Data passed from the caller
     *
     * @throws DriverException
     */
    public function createSource(
        Resource\Source &$oResource,
        array $aData
    ): void {
        //  @todo (Pablo - 2019-09-05) - implement this
        throw new NailsException('Method not implemented');
    }
}
