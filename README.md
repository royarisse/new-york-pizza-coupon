# New York Pizza Coupon code Enumerator

Call the hacked together script with your favorite products and it'll try to find any working Coupon code for you :)
It doesn't actually order anything, it'll only list working coupon codes.

## Usage

```bash
php -f /var/www/enum-nypizza.php '[{"product":284,"option":121,"quantity":1},{"product":93,"option":8,"quantity":1},{"slices":[92,262],"option":3,"quantity":1}]'
php -f /var/www/enum-nypizza.php products.json
```

## Setting products

- Standard products require the `product` and `option` fields to be set.
- XTasty products require the `slices` (2 or 4 `product` ids) and `option` fields to be set.

You can look at the `$optionIds` and `$productIds` arrays to get valid options and products resp. These lists however are incomplete. Have a dive in DevTools and watch the `AddProductToCurrentOrder` endpoint when you add your products to the cart to find the correct option and product ids.
