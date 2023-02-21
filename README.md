# Magento 2 Nuvei Checkout Module

---

## System requirements
Magento v2.3.x
PHP 7.2 to 7.4

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

![Nuvei Logo](https://github.com/SafeChargeInternational/nuvei_checkout_magento/blob/master/view/frontend/web/images/logo-dark.svg)
