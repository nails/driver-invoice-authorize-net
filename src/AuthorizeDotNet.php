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
use Nails\Invoice\Driver\Payment\AuthorizeDotNet\Constants;
use Nails\Invoice\Driver\PaymentBase;
use Nails\Invoice\Exception\DriverException;
use Nails\Invoice\Factory\ScaResponse;
use net\authorize\api\constants\ANetEnvironment as AuthNetConstants;
use net\authorize\api\contract\v1 as AuthNetAPI;
use net\authorize\api\controller as AuthNetController;

class AuthorizeDotNet extends PaymentBase
{
    /**
     * Returns whether the driver is available to be used against the selected invoice
     *
     * @return boolean
     */
    public function isAvailable($oInvoice)
    {
        return true;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns whether the driver uses a redirect payment flow or not.
     *
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
     *
     * @return mixed
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
        return [];
    }

    // --------------------------------------------------------------------------

    /**
     * Initiate a payment
     *
     * @param integer   $iAmount      The payment amount
     * @param string    $sCurrency    The payment currency
     * @param \stdClass $oData        The driver data object
     * @param \stdClass $oCustomData  The custom data object
     * @param string    $sDescription The charge description
     * @param \stdClass $oPayment     The payment object
     * @param \stdClass $oInvoice     The invoice object
     * @param string    $sSuccessUrl  The URL to go to after successful payment
     * @param string    $sFailUrl     The URL to go to after failed payment
     * @param string    $sContinueUrl The URL to go to after payment is completed
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
        $oChargeResponse = Factory::factory('ChargeResponse', 'nails/module-invoice');

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

            $sPaymentProfileId  = getFromArray('payment_profile_id', (array) $oCustomData);
            $sCustomerProfileId = getFromArray('customer_profile_id', (array) $oCustomData);
            $sCardNumber        = getFromArray('number', (array) $oData);
            $oCardExpire        = getFromArray('exp', (array) $oData);
            $sCardExpireMonth   = getFromArray('month', (array) $oCardExpire);
            $sCardExpireYear    = getFromArray('year', (array) $oCardExpire);
            $sCardCvc           = getFromArray('cvc', (array) $oData);

            // --------------------------------------------------------------------------

            if ($sPaymentProfileId || $sCustomerProfileId) {
                $this->payUsingProfile($oCharge, $sPaymentProfileId, $sCustomerProfileId);
            } else {
                $this->payUsingCardDetails($oCharge, $sCardNumber, $sCardExpireMonth, $sCardExpireYear, $sCardCvc);
            }

            $oCharge->setCurrencyCode($sCurrency);
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
                    $oChargeResponse->setTxnId($oTransactionResponse->getTransId());
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
        //  @todo (Pablo - 2019-07-24) - Implement this method
    }

    // --------------------------------------------------------------------------

    /**
     * Complete the payment
     *
     * @param \stdClass $oPayment  The Payment object
     * @param \stdClass $oInvoice  The Invoice object
     * @param array     $aGetVars  Any $_GET variables passed from the redirect flow
     * @param array     $aPostVars Any $_POST variables passed from the redirect flow
     *
     * @return \Nails\Invoice\Model\CompleteResponse
     */
    public function complete($oPayment, $oInvoice, $aGetVars, $aPostVars)
    {
        $oCompleteResponse = Factory::factory('CompleteResponse', 'nails/module-invoice');
        $oCompleteResponse->setStatusComplete();
        return $oCompleteResponse;
    }

    // --------------------------------------------------------------------------

    /**
     * Issue a refund for a payment
     *
     * @param string    $sTxnId      The original transaction's ID
     * @param integer   $iAmount     The amount to refund
     * @param string    $sCurrency   The currency in which to refund
     * @param \stdClass $oCustomData The custom data object
     * @param string    $sReason     The refund's reason
     * @param \stdClass $oPayment    The payment object
     * @param \stdClass $oInvoice    The invoice object
     *
     * @return \Nails\Invoice\Model\RefundResponse
     */
    public function refund($sTxnId, $iAmount, $sCurrency, $oCustomData, $sReason, $oPayment, $oInvoice)
    {
        $oRefundResponse = Factory::factory('RefundResponse', 'nails/module-invoice');

        try {

            //  Get the transaction details
            $oTransactionDetails = $this->getTransactionDetails($sTxnId);

            // Create the payment data for a credit card
            $oCard = new AuthNetAPI\CreditCardType();
            $oCard->setCardNumber($oTransactionDetails->card->last4);
            $oCard->setExpirationDate('XXXX'); //  This is deliberate
            $oPayment = new AuthNetAPI\PaymentType();
            $oPayment->setCreditCard($oCard);

            $oCharge = new AuthNetAPI\TransactionRequestType();
            $oCharge->setTransactionType('refundTransaction');
            $oCharge->setRefTransId($sTxnId);
            $oCharge->setCurrencyCode($sCurrency);
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
                $oRefundResponse->setTxnId($oResponse->getTransactionResponse()->getTransId());
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
    public function getApiMode()
    {
        return Environment::is(Environment::ENV_PROD) ? AuthNetConstants::PRODUCTION : AuthNetConstants::SANDBOX;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns an Authorize.Net authentication object
     *
     * @return AuthNetAPI\MerchantAuthenticationType
     */
    public function getAuthentication()
    {
        $oAuthentication = new AuthNetAPI\MerchantAuthenticationType();
        $oAuthentication->setName($this->getSetting('sLoginId'));
        $oAuthentication->setTransactionKey($this->getSetting('sTransactionKey'));
        return $oAuthentication;
    }

    // --------------------------------------------------------------------------

    /**
     * Complete the charge using an existing payment profile.
     *
     * @param AuthNetAPI\TransactionRequestType $oCharge     The Charge object
     * @param integer                           $iProfileId  The Payment profile ID
     * @param integer                           $iCustomerId The customer profile ID
     *
     * @throws DriverException
     */
    protected function payUsingProfile($oCharge, $iProfileId, $iCustomerId)
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
     * @param AuthNetAPI\TransactionRequestType $oCharge          the Charge object
     * @param string                            $sCardNumber      The Card's number
     * @param integer                           $iCardExpireMonth The Card's expiry month
     * @param integer                           $iCardExpireYear  The Card's expiry year
     * @param string                            $sCardCvc         The Card's CVC
     *
     * @throws DriverException
     */
    protected function payUsingCardDetails($oCharge, $sCardNumber, $iCardExpireMonth, $iCardExpireYear, $sCardCvc)
    {
        $sCardNumber = preg_replace('/[^\d]/', '', $sCardNumber);

        if (empty($sCardNumber)) {
            throw new DriverException('Card number must be supplied.');
        } elseif (empty($iCardExpireMonth)) {
            throw new DriverException('Card expiry month must be supplied.');
        } elseif (empty($iCardExpireYear)) {
            throw new DriverException('Card expiry year must be supplied.');
        } elseif (empty($sCardCvc)) {
            throw new DriverException('Card CVC number must be supplied.');
        }

        $oCreditCard = new AuthNetAPI\CreditCardType();
        $oCreditCard->setCardNumber($sCardNumber);
        $oCreditCard->setExpirationDate($iCardExpireYear . '-' . $iCardExpireMonth);
        $oCreditCard->setCardCode($sCardCvc);

        $oPayment = new AuthNetAPI\PaymentType();
        $oPayment->setCreditCard($oCreditCard);

        $oCharge->setPayment($oPayment);
    }

    // --------------------------------------------------------------------------

    /**
     * Calculates the transaction fee
     *
     * @param integer $iAmount The value of the transaction
     *
     * @return int
     */
    protected function calculateFee($iAmount)
    {
        $iFixedFee      = (int) $this->getSetting('iPerTransactionFee');
        $fPercentageFee = (float) $this->getSetting('iPerTransactionPercentage');
        return $iFixedFee + ($fPercentageFee / 100) * $iAmount;
    }

    // --------------------------------------------------------------------------

    /**
     * Queries Authorize.Net for details about a transaction
     *
     * @param string $sTxnId The original transaction ID
     *
     * @return \stdClass
     * @throws DriverException
     */
    protected function getTransactionDetails($sTxnId)
    {
        $oApiRequest = new AuthNetAPI\GetTransactionDetailsRequest();
        $oApiRequest->setMerchantAuthentication($this->getAuthentication());
        $oApiRequest->setTransId($sTxnId);

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
}
