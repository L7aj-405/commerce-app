<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use RuntimeException;

/**
 * Thrown when Ozon rejects add-parcel or its response can't be parsed for a
 * tracking number. Distinct from ValidationException (used for readiness/
 * city-mapping problems, which are the CALLER's fault) — this is a PROVIDER
 * response problem, and carries structured debug info (HTTP status,
 * response keys, a safe response preview) so the UI can show it without
 * digging through logs.
 */
class OzonShipmentCreationException extends RuntimeException
{
    /** @param array<string, mixed> $debug */
    public function __construct(string $message, public readonly array $debug = [])
    {
        parent::__construct($message);
    }
}
