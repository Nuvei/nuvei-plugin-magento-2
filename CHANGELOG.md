# Magento 2 Nuvei Checkout Module
---

# 3.1.6
```
    * Fixed the "Nuvei Subscriptions" list in user account on the Storefront.
```

# 3.1.5
```
    * Code scanned and cleaned with PHPCS and PHPCBF.
    * Added option to mask user details in the log.
    * At the places where use str_replace before check if the third paramter is not empty or typecast it to string.
    * In openOrder and updateOrder requests skip customFiled3 - getReservedOrderId.
    * In Cancel class added check if $order is an object, in case it is not throw an excception.
    * Use local version of SimplyConnect with new name to avoid conflicts with other checkout.js files and objects/methods.
    * In SubscriptionsHistory class check which class exists Zend_Uri or Laminas_Uri and use the existing one.
    * In readerWriter class, treat arrays as objects before save them in the log file.
    * Fixed some typos in the plugin settings.
    * Do not process Pending DMNs.
    * Fix for the logic who check for new plugin version.
    * Fix for the hiding error message in Product Page, when try to combine rebilling and ordinary product, or when try to add rebilling product into the Cart of Guest user.
```

# 3.1.4-p2
```
    * For Magento v2.4.* where Zend\Uri\Uri class is missing. It is replaced with Laminas\Uri\Uri.
    * Fixed the bug when try to set custom title on Storefront My Account -> Nuvei Subscriptions page.
```

# 3.1.4-p1
```
    * Added new SDK's URLs for the QA site.
```

# 3.1.4
```
    * Include the 3.1.3-p1 patch.
    * Remove Void button from Invoice View in case ot multiple invoices for the Order.
    * Fixed broken list in plugin's "Block Payment methods" setting.
```

# 3.1.3-p1
```
    * Load SimplyConnect directly, not in a custom variable.
    * Added few more URLs to SCP whitelist.
```

# 3.1.3
```
    * Removed the updateOrder reques on SDK pre-payment event. In case of Quote problems, the page will reloads.
    * Removed WebSDK options in plugin configuration.
    * Check for new plugin version each day and keep the information into the session.
    * Allow Nuvei GW to be used for Zero-Total Orders.
    * Disable DCC when Order total is Zero.
    * Save Order messages for Pending DMNs.
    * Fixed the problem with duplicate Invoice when Settle an Order.
    * Added additional check for fraund Transactions.
    * Fixed the example for the "Checkout transalations" setting.
    * Added locale for Gpay button.
    * In case of declined Void or Refund DMN, return the previous Order Status in Payment class.
    * Changes in the Void processing logic.
    * Fix for the Payment Cancel model.
    * Removed Response\Payment\ Settle and Cancel classes.
    * Fix for wrong Transaction IDs in Order View -> Transactions section in the admin.
    * Fix for the logic who removes the Void button in Invoices section.
    * Fix for Magento 2.3.x and missing Quote Payment Method when use APM redirect window.
```

# 3.1.2
```
    * Clean new line and backslash symbol from order billing and shipping addresses.
    * Set different delay time in the DMN logic according the environment.
    * Added additional check for wrong Order status upgrade.
    * Fix for the missing Credit Memo button in Invoice view.
```

# 3.1.1
```
    * Fix for the case when with WebSDK try to create rebilling Order with UPO.
    * Fix for save UPO problem when use WebSDK.
    * Fix for the daily log file name, when the plugin save both log files.
    * Removed ApplePay data from the WebSDK Payment Methods, before pass them to the front-end.
    * When for SimplyConnect pay buttons is set option "text", the plugin will not subscribe for total amount change event.
    * When the plugin can not find an Order by DMN data, do not try to create Order, but return 400 to the Cashier.
    * Implemented Auto-Void logic for DMNs.
    * Trim merchant credentials when get them.
    * Chanaged sourceApplication parameter to "MAGENTO_2_PLUGIN".
```

# 3.1.0
```
    * Added WebSDK as option to finish the payment.
    * Added more options for "Save UPOs" setting according SimplyConnect.
    * Added an option to stop sending Notification URL into Nuvei requests.
    * Fix for SimplyConnect "Block Payment methods" option.
```

# 3.0.1
```
    * Use new Sandbox endpoint.
    * Display plugin version into the plugin settings.
    * Removed plugin setting "Use Dev SDK version".
    * Added SimplyConnect themes.
    * Added option to choose APMs window type.
    * Load SDK and its style separate.
```

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
