# Coinsnap for Bitrix payment plugin #
![Coinsnap for Bitrix](https://resources.coinsnap.org/products/bitrix/images/cover.png)

## Accept Bitcoin and Lightning Payments with Bitrix ##

* Contributors: coinsnap
* Tags: Lightning, Lightning Payment, SATS, Satoshi sats, bitcoin, Bitrix, payment gateway, accept bitcoin, bitcoin plugin, bitcoin payment processor, bitcoin e-commerce, Lightning Network, cryptocurrency, lightning payment processor,  Битрикс, 1С, Энтерпрайз, управление сайтом, бизнес, интернет-магазин, оплата, биткойн
* Requires PHP: 8.1
* Requires Bitrix: 14.0+
* Compatible Bitrix versions: Small business, Business, Enterprise
* Stable tag: 1.1.0
* License: GNU General Public License v3.0
* License URI: https://github.com/Coinsnap/Coinsnap-for-Bitrix/blob/main/LICENSE

The Coinsnap Bitrix module allows you to accept Bitcoin and Ligtning payments in 1C-Bitrix e-commerce system.

## Description ##

With the Coinsnap Bitcoin Lightning payment processing plugin you can immediately accept Bitcoin Lightning payments on your site. You don’t need your own Lightning node or any other technical requirements if you'd like to provide payments via Coinsnap payment gateway. Coinsnap Bitcoin and Lightning payment requires no minimum costs, no fixed contracts, no hidden costs. The Prestashop payment plugin from Coinsnap can be downloaded and installed free of charge.

Simply register on [Coinsnap](https://app.coinsnap.io/register), enter your own Lightning address, upload and install the Coinsnap payment module in Prestashop backend. Add your store ID and your API key which you’ll find in your Coinsnap account, and your customers can pay you with Bitcoin Lightning right away!


* Blog Article: [https://coinsnap.io/coinsnap-for-bitrix-payment-module/](https://coinsnap.io/coinsnap-for-bitrix-payment-module/)
* Module page: [https://marketplace.1c-bitrix.ru/solutions/](https://marketplace.1c-bitrix.ru/solutions/)
* GitHub: [https://github.com/Coinsnap/Coinsnap-for-Bitrix](https://github.com/Coinsnap/Coinsnap-for-Bitrix)

## Bitcoin and Lightning payments in 1C-Bitrix ##

![Coinsnap for Bitrix](https://resources.coinsnap.org/products/bitrix/images/bitrix.jpg)

Coinsnap is a Lightning payment provider and offers a payment gateway for processing Bitcoin and Lightning payments. A merchant only needs a Lightning wallet with a lightning address to accept Bitcoin and Lightning payments on his web store.

## Features ##

* **All you need is your email and a Lightning Wallet with a Lightning address. [Here you can find an overview of suitable Lightning Wallets](https://coinsnap.io/en/lightning-wallet-with-lightning-address/)**

* **Accept Bitcoin and Lightning payments** in your online store **without running your own technical infrastructure.** You do not need your own server, nor do you need to run your own Lightning Node. You also do not need a shop-system, for you can sell right out of your forms using the Coinsnap for Content Form 7-plugin.

* **Quick and easy registration at Coinsnap**: Just enter your email address and your Lightning address – and you are ready to integrate the payment module and start selling for Bitcoin Lightning. You will find the necessary IDs and Keys in your Coinsnap account, too.

* **100% protected privacy**:
    * We do not collect personal data.
    * For the registration you only need an e-mail address, which we will also use to inform you when you have received a payment.
    * No other personal information is required as long as you request a withdrawal to a Lightning address or Bitcoin address.

* **Only 1 % fees!**:
    * No basic fee, no transaction fee, only 1% on the invoice amount with referrer code.
    * Without referrer code the fee is 1.25%.
    * Get a referrer code from our [partners](https://coinsnap.io/en/partner/) and customers and save 0.25% fee.

* **No KYC needed**:
    * Direct, P2P payments (instantly to your Lightning wallet)
    * No intermediaries and paperwork
    * Transaction information is only shared between you and your customer

* **Sophisticated merchant’s admin dashboard in Coinsnap:**:
    * See all your transactions at a glance
    * Follow-up on individual payments
    * See issues with payments
    * Export reports

* **A Bitcoin payment via Lightning offers significant advantages**:
    * Lightning **payments are executed immediately.**
    * Lightning **payments are credited directly to the recipient.**
    * Lightning **payments are inexpensive.**
    * Lightning **payments are guaranteed.** No chargeback risk for the merchant.
    * Lightning **payments can be used worldwide.**
    * Lightning **payments are perfect for micropayments.**

* **Multilingual interface and support**: We speak your language


## Documentation: ##

* [Coinsnap API (1.0) documentation](https://docs.coinsnap.io/)
* [Frequently Asked Questions](https://coinsnap.io/en/faq/) 
* [Terms and Conditions](https://coinsnap.io/en/general-terms-and-conditions/)
* [Privacy Policy](https://coinsnap.io/en/privacy/)

## Installation ##

## 1. Install Coinsnap for Bitrix module ##



## 2. Create Coinsnap account ##

### 2.1. Register in Coinsnap App ###

Now go to the Coinsnap website at: [https://app.coinsnap.io/register](https://app.coinsnap.io/register) and open an account by entering your email address and a password of your choice.

![Coinsnap registration](https://resources.coinsnap.org/products/bitrix/images/screenshot-07.png)

If you are using a Lightning Wallet with Lightning Login, then you can also open a Coinsnap account with it.

### 2.2. Confirm email address ###

You will receive an email to the given email address with a confirmation link, which you have to confirm. If you do not find the email, please check your spam folder.

![E-mail address confirmation](https://resources.coinsnap.org/products/bitrix/images/screenshot-08.png)

Then please log in to the Coinsnap backend with the appropriate credentials.

### 2.3. Set up website at Coinsnap ###

After you sign up, you will be asked to provide two pieces of information.

In the Website Name field, enter the name of your online store that you want customers to see when they check out.

In the Lightning Address field, enter the Lightning address to which the Bitcoin and Lightning transactions should be forwarded.

A Lightning address is similar to an e-mail address. Lightning payments are forwarded to this Lightning address and paid out. If you don’t have a Lightning address yet, set up a Lightning wallet that will provide you with a Lightning address.

For more information on Lightning addresses and the corresponding Lightning wallet providers, click here:
https://coinsnap.io/lightning-wallet-mit-lightning-adresse/

# Wiki

Read more about the integration configuration on [our Wiki](https://github.com/Coinsnap/Coinsnap-for-Bitrix).

# Changelog

### 1.0.0 :: 2025-02-20
* Initial release.

### 1.1.0 :: 2026-03-22
* Updated Coinsnap PHP library.
* Update: added payment descriptions.
* Update: added BTCPay server gateway.

### 1.2.0 :: 2026-11-16
* Security: Webhooks are verified with the timestamped X-Coinsnap-Signature header (Coinsnap) or BTCPay-Sig (BTCPay Server), with a timing-safe comparison. A missing webhook secret rejects every webhook (before, an empty secret let anyone sign a webhook).
* Security: The webhook finds the payment by the invoice id stored on it at checkout and marks only that payment paid, through Bitrix's payment API. Before, the payment was picked from an unsigned field in the request and the whole order was marked paid.
* Security: A payment is marked paid only when the invoice is settled, belongs to that order, and matches the payment's amount and currency. "Processing" never marks it paid, a paid payment is never changed by a later webhook, and an expired invoice no longer cancels a paid order. BTCPay invoices marked settled by hand are not accepted.
* Security: Payment server error details are no longer shown at checkout, and the checkout templates escape their output.
* Security: The library no longer sends an Access-Control-Allow-Origin: * header, and API error replies are no longer copied into error messages.
* Fix: Order totals of 1,000 or more were sent with a thousands separator and invoiced for the wrong amount.
* Fix: A payment server outage no longer deletes and re-registers the webhook, and a missing stored webhook no longer breaks checkout.
* Fix: The exchange rate lookup gives up after 3 tries instead of retrying forever.
* Update: added automatic redirect setting.
* Update: added redirect to custom URL after payment.