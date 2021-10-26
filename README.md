# CheckoutCom_Order_Place_After
Presently, the default checkout module creates order if when payment is unsucessful and later cancel those orders via webhook. This repository fixes that for Magento
Using this repository, Order are only placed in magento only when payment is successful and customers  successfully complete the challenge screen.

# Installation.
This repository is based on checkout.com version v2.3.1. To install Download this directory and replace vendor/checkoutcom/magento2/Controller/Payment/ with this directory
