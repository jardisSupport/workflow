<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Support;

use JardisSupport\Contract\Kernel\DomainResponseInterface;
use stdClass;

/**
 * The one JSON form of the response envelope used by fixtures and byte tests.
 *
 * The envelope shape is the one `jardiscore/app` serialises (status, data, errors, meta; an
 * empty part becomes a JSON object). Only the encoder flags are fixed here so fixture and test
 * produce identical bytes: pretty print, unescaped slashes and unicode, zero fraction kept.
 */
final class EnvelopeJson
{
    public const FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    /**
     * @return array{status: int, data: object|array<string, mixed>, errors: object|array<string, mixed>, meta: object|array<string, mixed>}
     */
    public static function envelope(DomainResponseInterface $response): array
    {
        return [
            'status' => $response->getStatus(),
            'data' => self::coerce($response->getData()),
            'errors' => self::coerce($response->getErrors()),
            'meta' => self::coerce($response->getMetadata()),
        ];
    }

    public static function encode(mixed $payload): string
    {
        return json_encode($payload, self::FLAGS);
    }

    /**
     * @param array<string, mixed> $value
     * @return object|array<string, mixed>
     */
    private static function coerce(array $value): object|array
    {
        return $value === [] ? new stdClass() : $value;
    }
}
