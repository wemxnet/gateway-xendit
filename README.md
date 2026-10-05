# Xendit

Accept payments on WemX through Xendit invoices (cards, e-wallets, bank transfers and QR codes across Southeast Asia).

## Features

- Create a Xendit invoice and redirect the customer to its hosted checkout
- Complete the payment from Xendit's invoice webhook, or when the customer returns from checkout
- Re-read every invoice from the Xendit API before marking a payment paid, and check its reference, currency and amount

## Install

Install from the WemX marketplace, or download `Xendit.zip` from a GitHub release and place the `Xendit` folder at `extensions/Gateways/Xendit`. Then enable **Xendit**.

Publishing a release builds `Xendit.zip`. Unzipping it creates a folder named `Xendit`. GitHub's own "Source code" archive unpacks to `gateway-xendit-<tag>`. Use `Xendit.zip`.

## Connection

1. In the Xendit dashboard, create a secret API key with write access to Money-in (invoices). Paste it as **Secret key**.
2. Under Settings → Webhooks, copy the **Verification token** and paste it as **Callback token**.
3. Set the **Invoices paid** webhook URL to the webhook URL shown on the WemX gateway configuration.

Supported currencies: IDR, PHP, THB, VND, MYR and USD (depending on what your Xendit account has enabled).

## Credits

Based on the MIT-licensed Xendit extension from [Paymenter](https://github.com/Paymenter/Extensions).
