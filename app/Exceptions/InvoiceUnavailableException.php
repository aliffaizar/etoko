<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An order that has no invoice to give (P2-E6-T1).
 *
 * The message is written for whoever will read it on a screen, in Indonesian,
 * following `ShippingFailedException`: the only case today is an order that
 * has not been paid for, and the sentence says so.
 */
class InvoiceUnavailableException extends RuntimeException {}
