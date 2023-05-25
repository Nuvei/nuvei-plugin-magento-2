# Magento 2 Nuvei Checkout Module

---

# 3.0.0
```
    * Added Magento 2 REST API support.
    * Changed sourceApplication parameter value.
```

# 2.0.4
```
    * Changed the link to the plugin repo in the model who check for new plugin version.
    * Removed the Nuvei logo from the readme file.
```

# 2.0.3
```
    * In the last updateOrder request check if all products into the Cart are still available.
    * On the checkout call openOrder with pure JS.
```

# 2.0.2
```
    * Changes on checkout page logic - when client change the billing address just reload the SDK with the new country, but do not update the Order. The Order will be updated later.
    * Allow plugin Title settings to be set per site view.
    * Fixed some visual bugs on the checkout page.
    * When total amount of the Order is 0, force transactionType to Auth.
```

# 2.0.1
```
    * Do not pass anymore user and billing details to the Checkout SDK.
    * Do not save selected payment provider into Quote when change it.
```

# 2.0.0
```
    * Stop using Cc and TransparentInterface.
    * All Observers were removed.
    * When save Transaction data for the Order, use TransacionID as key. By it try to preved saving same Transaction data more than once.
    * Into the Payment->capture() method set canCreditMemo flag to True for the Order.
    * When we have Settle or Void try to delay DMN logic, because sometime it executes before Magento Capture logic. This can lead to wrong Order Status after Settle or Void.
    * Fixed some problems with PHP 8.1.
    * Fixed the links to Nuvei Documentation into the plugin settings.
    * Show better message if the merchant currency is not supported by the APM.
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
