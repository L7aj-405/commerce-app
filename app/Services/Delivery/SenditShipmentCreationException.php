<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use RuntimeException;

/**
 * Thrown when Sendit rejects POST /deliveries or its response can't be
 * parsed for a delivery code. Distinct from ValidationException (used for
 * readiness/city-mapping/pickup-district problems, which are the CALLER's
 * fault) — this is a PROVIDER response problem, and carries structured
 * debug info (sent district_id/pickup_district_id/amount, whether required
 * fields were present, response keys) so the UI can show it without digging
 * through logs. Never includes public_key/secret_key/token.
 */
class SenditShipmentCreationException extends RuntimeException
{
    /** @param array<string, mixed> $debug */
    public function __construct(string $message, public readonly array $debug = [])
    {
        parent::__construct($message);
    }
}
