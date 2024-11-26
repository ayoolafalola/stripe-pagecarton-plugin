<?php

class Stripe_InlineEmbed extends Stripe
{
    protected static $_currency = array('whitelist' => 'USD,EUR,CAD', 'blacklist' => 'ALL');
    protected static $_formAction = '';

    protected function init()
    {
        self::$_apiName = $this->getParameter('checkoutoption_name') ?: array_pop(explode('_', get_class($this)));
        if (!$cart = self::getStorage()->retrieve()) {
            return;
        }
        $values = $cart['cart'];

        $parameters = static::getDefaultParameters();
        $parameters['email'] = Ayoola_Form::getGlobalValue('email') ?: (Ayoola_Form::getGlobalValue('email_address') ?: Ayoola_Application::getUserInfo('email'));
        if (!empty($cart['checkout_info']['email_address'])) {
            $parameters['email'] = $cart['checkout_info']['email_address'];
        }

        $parameters['reference'] = $this->getParameter('reference') ?: $parameters['order_number'];
        $parameters['key'] = Stripe_Settings::retrieve('public_key');
        $parameters['currency'] = Stripe_Settings::retrieve('currency');
        $secretKey = Stripe_Settings::retrieve('secret_key');
        if( Stripe_Settings::retrieve('test_mode') )
        {
            $parameters['key'] = Stripe_Settings::retrieve('test_public_key');
            $secretKey = Stripe_Settings::retrieve('test_secret_key');
        }
        $parameters['price'] = 0.00;

        foreach ($values as $name => $value) {
            if (!isset($value['price'])) {
                $value = array_merge(self::getPriceInfo($value['price_id']), $value);
            }
            @$parameters['price'] += floatval($value['price'] * $value['multiple']);
        }

        $parameters['amount'] = $parameters['price'] * 100; // Stripe uses the smallest currency unit (e.g., cents)

        // Generate PaymentIntent and get clientSecret
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => 'https://api.stripe.com/v1/payment_intents',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $secretKey,
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_POSTFIELDS => http_build_query([
                'amount' => $parameters['amount'],
                'currency' => $parameters['currency'],
                'automatic_payment_methods[enabled]' => 'true',
            ]),
        ]);
        
        $response = curl_exec($curl);
        curl_close($curl);
        
        if ($response) {
            $response = json_decode($response, true);
            if (!empty($response['client_secret'])) {
                $clientSecret = $response['client_secret'];
            } else {
                $this->setViewContent('<p>Error: Unable to retrieve client secret.</p>');
                return;
            }
        } else {
            $this->setViewContent('<p>Error: PaymentIntent creation failed.</p>');
            return;
        }
        

        // Add clientSecret to frontend script
        $this->setViewContent('<form id="payment-form">
                                    <div id="payment-element"></div>
                                    <br>
                                    <button id="submit">Confirm Payment</button>
                                </form>
                                <script src="https://js.stripe.com/v3/"></script>
                                <script>
                                    const stripe = Stripe("' . $parameters['key'] . '");
                                    const elements = stripe.elements({ clientSecret: "' . $clientSecret . '" });
                                    const paymentElement = elements.create("payment");
                                    paymentElement.mount("#payment-element");

                                    const form = document.getElementById("payment-form");
                                    form.addEventListener("submit", async (event) => {
                                        event.preventDefault();
                                        const {error} = await stripe.confirmPayment({
                                            elements,
                                            confirmParams: {
                                                return_url: "' . $parameters['success_url'] . '",
                                            },
                                        });
                                        if (error) {
                                            alert( error.message );
                                            console.error(error.message);
                                        }
                                    });
                                </script>');
    }

    static function checkStatus($orderNumber)
    {
        $table = new Application_Subscription_Checkout_Order();
        if (!$orderInfo = $table->selectOne(null, array('order_id' => $orderNumber))) {
            return false;
        }
        $secretKey = Stripe_Settings::retrieve('secret_key');
        if( Stripe_Settings::retrieve('test_mode') )
        {
            $secretKey = Stripe_Settings::retrieve('test_secret_key');
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1/payment_intents/' . $_REQUEST['payment_intent']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $secretKey]);
        $request = curl_exec($ch);
        curl_close($ch);

        $result = $request ? json_decode($request, true) : array();

        $orderInfo['order_status'] = empty($result['status']) || $result['status'] !== 'succeeded' ? 0 : 99;
        $orderInfo['order_random_code'] = $_REQUEST['payment_intent'];
        $orderInfo['gateway_response'] = $result;

        self::changeStatus($orderInfo);

        return $orderInfo;
    }
}
