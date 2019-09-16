import Utilities from './Utilities';

const DRIVER = 'nails/driver-invoice-authorize-net';

class Checkout {

    /**
     * Constructs Checkout
     */
    constructor() {

        this.params = Utilities.getParams('vendor/' + DRIVER + '/assets/js/checkout.min.js');
        this.hash = this.params.hash;
        this.clientKey = this.params.key;
        this.apiLoginId = this.params.loginId;

        this.prepareForm();
        this.bindEvents();
        this.registerValidator();
    }

    // --------------------------------------------------------------------------

    /**
     * Prepares the form
     */
    prepareForm() {
        //  Get references to the relevant elements
        this.$form = $('#js-invoice-main-form');
        this.$panel = $('.js-invoice-panel-payment-details[data-driver="' + DRIVER + '"]', this.$form);
        this.$inputCardName = $('.js-invoice-cc-name', this.$panel);
        this.$inputCardNum = $('.js-invoice-cc-num', this.$panel);
        this.$inputCardExp = $('.js-invoice-cc-exp', this.$panel);
        this.$inputCardCvc = $('.js-invoice-cc-cvc', this.$panel);

        //  Add the token element
        this.$input = $('<input>').attr('type', 'hidden').attr('name', this.params.hash + '[token]');
        this.$panel.append(this.$input);

        //  Ensure card elements do not post to the server
        this.$inputCardName.removeAttr('name');
        this.$inputCardNum.removeAttr('name');
        this.$inputCardExp.removeAttr('name');
        this.$inputCardCvc.removeAttr('name');
    }

    // --------------------------------------------------------------------------

    /**
     * Binds to user events
     */
    bindEvents() {
    }

    // --------------------------------------------------------------------------

    /**
     * Registers the validator
     */
    registerValidator() {
        this.$form
            .data('validators')
            .push({
                'slug': DRIVER,
                'instance': this
            });
    }

    // --------------------------------------------------------------------------

    /**
     * Validates the form, and generates the card token
     * @param deferred
     */
    validate(deferred) {
        this.getToken()
            .done(() => {
                deferred.resolve();
            })
            .fail((message) => {
                deferred.reject(message);
            });

    }

    // --------------------------------------------------------------------------


    /**
     * Retrieves a card token from Auth.net
     * @returns {*}
     */
    getToken() {

        let promise = new $.Deferred();
        let authData = {
            'clientKey': this.clientKey,
            'apiLoginID': this.apiLoginId,
        };

        let exp = this.$inputCardExp.val().split('/');
        let month = $.trim(exp[0]);
        if (month.length === 1) {
            month = '0' + month;
        }

        let year = $.trim(exp[1]);
        if (year.length > 2) {
            year = year.slice(-2);
        } else if (year.length === 1) {
            year = '0' + year;
        }

        let cardData = {
            'fullName': $.trim(this.$inputCardName.val()),
            'cardNumber': this.$inputCardNum.val().replace(/[^\d]/g, ''),
            'month': month,
            'year': year,
            'cardCode': $.trim(this.$inputCardCvc.val()),
        };

        let secureData = {
            'authData': authData,
            'cardData': cardData
        };

        Accept.dispatchData(secureData, (response) => {

            if (response.messages.resultCode === 'Error') {
                let message = [];
                let i = 0;
                while (i < response.messages.message.length) {
                    message.push(response.messages.message[i].text);
                    i = i + 1;
                }
                promise.reject(message.join(' '));
            } else {
                this.$input.val(JSON.stringify(response.opaqueData));
                this.$inputCardName.val('');
                this.$inputCardNum.val('');
                this.$inputCardExp.val('');
                this.$inputCardCvc.val('');
                promise.resolve();
            }
        });

        return promise.promise();
    }
}

export default Checkout;
