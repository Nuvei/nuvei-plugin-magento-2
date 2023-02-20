# Magento 2 Nuvei Checkout Module

---

# 1.1.1
```
    * Fix for the bug with the Refunds.
```

# 1.1.0
```
	* Added ReaderWriter class, to read and save files for the plugin.
    * Added new helper class PaymentsPlans, who provide information about the payment plan of a product.
    * createLog method was moved from Config class to ReaderWriter class.
    * Removed Nuvei request and response DB tables. All information for the requests/responses is into the log. Removed all classes for reading and writing into those tables.
    * Removed the Cron job cleaning Nuvei DB tables.
    * Code was cleaned.
    * Removed the option to show/hide Nuvei logo on the checkout.
    * Added new Order status - 'Nuvei Canceled'.
    * Added confirm prompt when try to Void.
    * Added correct links to Nuvei Documentation.
    * When receive DMN use retry logic if deadlock happen.
    * Added better message when we get "Insufficient funds" error.
    * Replaced UpgradeData class with Data Patch class.
```

# 1.0.0
```
    * In the admin added check for the merchant data before try to get merchant payment methods.
    * When call Checkokut SDK, pass the billing address and into userData block.
    * Removed nuveiLoadCheckout() method from frontend JS.
    * Removed nuveiGetSessionToken() method from frontend JS.
    * Removed the Cron job for a new plugin version.
    * In this plugin we only replace the Web SDK with the Checkout SDK.
```
