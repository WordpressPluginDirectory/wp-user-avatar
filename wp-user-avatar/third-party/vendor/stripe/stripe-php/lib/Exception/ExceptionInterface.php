<?php

namespace ProfilePressVendor\Stripe\Exception;

// TODO: remove this check once we drop support for PHP 5
if (\interface_exists(\Throwable::class, \false)) {
    /**
     * The base interface for all Stripe exceptions.
     * @internal
     */
    interface ExceptionInterface extends \Throwable
    {
    }
} else {
    /**
     * The base interface for all Stripe exceptions.
     * @internal
     */
    // phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses
    interface ExceptionInterface
    {
    }
    // phpcs:enable
}
