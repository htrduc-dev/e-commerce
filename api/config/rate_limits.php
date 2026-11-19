<?php

return [
    // Preserve the original type so invalid values cannot be cast into a quota.
    'public_products_per_minute' => env('PRODUCT_API_RATE_LIMIT_PER_MINUTE', 60),
];
