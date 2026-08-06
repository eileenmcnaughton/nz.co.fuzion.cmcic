# Monetico Online (Crédit Mutuel / CIC) CiviCRM integration

Extension to process payments using the **Monetico Online** payment solution provided by Crédit Mutuel and CIC.

Distributed under the terms of the GNU Affero General public license (AGPL 3). See LICENSE.txt for details.

## Requirements

* **CiviCRM**: `6.10+` (Tested up to CiviCRM `6.17.0`)
* **PHP**: `8.1+` (compatible with PHP 8.1, 8.2, 8.3, 8.4, 8.5)

## Configuration

First get the TPE key from the Monetico portal:

* Login to the Monetico portal to get a "TPE security key" (https://www.monetico-services.com/en/identification/authentification.html)
* Click on the Parameters menu
  * Go through the process to validate and email. It will send a code to one of the configured emails.
  * The TPE key will be displayed on screen. It's an alphanumeric code of 40 characters.
  * Also download the security key (something.key). It includes the same 40 char key, as well as the HMAC-SHA1 key.

Then in CiviCRM, configure the Payment Processor:

* Enable this extension (Administer > System Settings > Extensions)
* Add the Monetico Online payment processor (Administer > System Settings > Payment Processors)
  * **POS terminal number**: 7-character alphanumeric code of the TPE (e.g. `1234567`)
  * **Merchant security key**: the hex / binary HMAC security key
  * **Site code**: short alphanumeric name related to the organization (e.g. `acmeorg`)
  * **Algorithm**: sha1 (or hmac-sha1)
  * **Site URL**: `https://p.monetico-services.com/paiement.cgi`
  * **Site URL for tests**: `https://p.monetico-services.com/test/paiement.cgi`

Note that the TPE key/sha1/site code are identical for the dev and production configurations. Only the URL differs.

## Server notification URL ("interface Retour" / CGI2)

The Monetico merchant support must be contacted to configure this URL. It is separate from the browser return URLs sent by CiviCRM.

Use the HTTPS route containing the CiviCRM payment processor ID:

* Test: `https://crm.example.org/civicrm/payment/ipn/{test_processor_id}`
* Production: `https://crm.example.org/civicrm/payment/ipn/{production_processor_id}`

Do not use HTTP, port 80, or the `?processor_id=` query-string form. A HTTP to HTTPS redirect prevents Monetico from receiving the required `version=2` / `cdr=0` acknowledgement.

## Online Refunds (`recredit_paiement.cgi`)

Online refunds via Monetico API integrate with CiviCRM core and the optional **MJWShared extension** (`mjwshared`) on the payment refund screen.

Prerequisites for online refunds:

1. **Monetico IP Whitelisting**: Contact Monetico support to authorize your CRM server's outbound public IP address for `recredit_paiement.cgi` calls (`payment-api.e-i.com` / `https://payment-api.e-i.com/test/recredit_paiement.cgi`). Calls without IP authorization will return `cdr=-30`.
2. **CiviCRM Setting**: Enable the refund feature flag via `cv` CLI or JS API v4:
   ```bash
   # Enable online refunds
   cv api4 Setting.set '{"values":{"cmcic_enable_refunds":true}}'

   # Disable online refunds
   cv api4 Setting.set '{"values":{"cmcic_enable_refunds":false}}'
   ```

When enabled, triggering a refund from the CiviCRM / MJWShared refund screen will issue a real-time signed HTTP POST request to Monetico's `recredit_paiement.cgi` API after validating the remaining refundable balance against Monetico's `etatpaiement.cgi`.

## Out-of-Bound Reconciliation (`CmcicPayment.reconcile`)

You can synchronously query Monetico's `etatpaiement.cgi` to inspect or reconcile any contribution against Monetico's bank state. When a bank refund is detected, execution mode (`dryRun: false`) idempotently records the negative financial payment transaction linked to the initial payment before marking the contribution as `Refunded`.

* **Preview / Dry Run** (simulates without modifying database):
  ```bash
  cv api4 CmcicPayment.reconcile '{"contributionId": 85816, "dryRun": true}'
  ```

* **Execute / Apply**:
  ```bash
  cv api4 CmcicPayment.reconcile '{"contributionId": 85816, "dryRun": false}'
  ```

## Testing

Most often while testing Monetico will show an error that the TPE is closed. It has to be re-opened every 15 days.

* Login to the Monetico portal
* Go to the dev environment
* Click "TPE status", edit, and re-open for 15 days.

## Going to production

It is necessary to contact Monetico support to enable production mode and whitelist your production outbound IP address for API calls.
