<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class ConnectorException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $platform,
        public readonly array $context = [],
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public static function connectionFailed(string $platform, string $reason): self
    {
        return new self(
            message: "Connection to {$platform} failed: {$reason}",
            platform: $platform,
            context: ['reason' => $reason],
        );
    }

    public static function authFailed(string $platform): self
    {
        return new self(
            message: "Authentication failed for {$platform}",
            platform: $platform,
            context: ['hint' => 'Check credentials in store settings'],
        );
    }

    public static function rateLimited(string $platform): self
    {
        return new self(
            message: "{$platform} API rate limit exceeded",
            platform: $platform,
            context: ['hint' => 'Retry after the rate limit window resets'],
        );
    }
}
