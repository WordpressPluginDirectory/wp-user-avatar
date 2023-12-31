<?php

namespace ProfilePressVendor\Stripe\Issuing;

/**
 * Class CardDetails.
 *
 * @property string $id
 * @property string $object
 * @property Card $card
 * @property string $cvc
 * @property int $exp_month
 * @property int $exp_year
 * @property string $number
 * @internal
 */
class CardDetails extends \ProfilePressVendor\Stripe\ApiResource
{
    const OBJECT_NAME = 'issuing.card_details';
}
