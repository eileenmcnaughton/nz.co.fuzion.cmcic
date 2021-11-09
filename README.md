# Credit Mutuel (Monetico) CiviCRM integration

Extension to process payments using the Monetico payment processor by Credit Mutuel.

Distributed under the terms of the GNU Affero General public license (AGPL 3). See LICENSE.txt for details.

## Requirements

Tested on CiviCRM 5.35 and later.

## Configuration

First get the TPE key from the Monetico portal:

* Login to the Monetico portal to get a "TPE security key" (https://www.monetico-services.com/en/identification/authentification.html)
* Click on the Parameters menu
  * Go through the process to validate and email. It will send a code to one of the configured emails.
  * The TPE key will be displayed on screen. It's an alphanumeric code of 40 characters.
  * Also download the security key (something.key). It includes the same 40 char key, as well as the HMAC-SHA1 key.

Then in CiviCRM, configure the Payment Processor:

* Enable this extension (Administer > System Settings > Extensions)
* Add the CMCIC/Monetico payment processor (Administer > System Settings > Payment Processors)
  * POS terminal number: 6 digit code of the TPE
  * Merchant security key: the sha1 key
  * Site code: short alphanumeric name related to the organization. Ex: "acmeorg".
  * Algorithm: sha1
  * Site URL: https://p.monetico-services.com/paiement.cgi
  * Site URL for tests: https://p.monetico-services.com/test/paiement.cgi

Note that the TPE key/sha1/site code are the same for the dev and production configurations. Only the URL is different.

## Return URL (or webhook)

The Monetico merchant support must be contacted to set the return URL.

## Testing

Most often while testing Monetico will show an error that the TPE is closed. It has to be re-opened every 15 days.

* Login to the Monetico portal
* Go to the dev environment
* Click "TPE status", edit, and re-open for 15 days.

For test cards:  
https://p.monetico-services.com/test/cartes_test.cgi?lgue=FR

## Going to production

It might be necessary to contact Monetico support to enable production mode.
