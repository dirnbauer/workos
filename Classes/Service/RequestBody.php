<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Service;

use Psr\Http\Message\ServerRequestInterface;
use Webconsulting\WorkosAuth\Security\MixedCaster;

/**
 * Typed accessor for PSR-7 parsed request bodies, which are declared as
 * `array|object|null` without any guarantee about value types.
 */
final readonly class RequestBody
{
    /**
     * @param array<string, mixed> $body
     */
    private function __construct(
        private array $body,
    ) {}

    public static function fromRequest(ServerRequestInterface $request): self
    {
        return new self(MixedCaster::stringKeyedArray($request->getParsedBody()) ?? []);
    }

    public function string(string $key, string $default = ''): string
    {
        return MixedCaster::string($this->body[$key] ?? null, $default);
    }

    public function trimmedString(string $key): string
    {
        return trim($this->string($key));
    }

    /**
     * Nested `name[key]` form values, e.g. `configuration[apiKey]`.
     *
     * @return array<string, mixed>
     */
    public function group(string $key): array
    {
        return MixedCaster::stringKeyedArray($this->body[$key] ?? null) ?? [];
    }
}
