<?php

return [
    // A library with no subscription at all can only open the Subscription page until platform staff
    // assign a plan or grant free access. Set BILLING_REQUIRE_SUBSCRIPTION=false to leave such libraries unrestricted.
    'require_subscription' => (bool) env('BILLING_REQUIRE_SUBSCRIPTION', true),
];
