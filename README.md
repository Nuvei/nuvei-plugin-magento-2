# Magento 2 Nuvei Checkout Module

---

## System requirements
Magento v2.3.x and up.  
PHP 7.2 and up.  
Working CURL module.

---

## Nuvei requirements
Enabled DMNs into merchant settings.  
Whitelisted plugin endpoint so the plugin can receive the DMNs.  
In case of using plugin Rebilling functionality, plese provide the the DMNs endpoint to Integration and Teach Support teams, so it can be added to the merchant configuration.

---

## Install manually under app/code
Download & place the contents of this repository under {YOUR-MAGENTO2-ROOT-DIR}/app/code/Nuvei/Checkout  
Then, run the following commands under your Magento 2 root dir:
```
php bin/magento maintenance:enable
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento setup:static-content:deploy
php bin/magento maintenance:disable
php bin/magento cache:flush
```

---

https://www.nuvei.com/

© 2007 - 2023 Nuvei
All rights reserved.