# Magento 2 Nuvei Checkout Module

## Description
Nuvei supports major international credit and debit cards enabling you to accept payments from your global customers. 

A wide selection of region-specific payment methods can help your business grow in new markets. Other popular payment methods, from mobile payments to e-wallets, can be easily implemented on your checkout page.

The correct payment methods at the checkout page can bring you global reach, help you increase conversions, and create a seamless experience for your customers.

## System Requirements
- Magento v2.3.x and up  
- PHP 7.2 and up  
- Working PHP cURL module

## Nuvei Requirements
- Enabled DMNs into merchant settings.  
- Whitelisted plugin endpoint so the plugin can receive the DMNs.  
- If using the Rebilling plugin functionality, please provide the DMN endpoint to the Integration and Technical Support teams, so it can be added to the merchant configuration.

## Install Manually under app/code
1. Download and place the contents of this repository under {YOUR-MAGENTO2-ROOT-DIR}/app/code/Nuvei/Checkout
2. Run the following commands under your Magento 2 root dir:
```
php bin/magento maintenance:enable
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento setup:static-content:deploy
php bin/magento maintenance:disable
php bin/magento cache:flush
```

## Support
Please contact our Technical Support (tech-support@nuvei.com) for any questions and difficulties.