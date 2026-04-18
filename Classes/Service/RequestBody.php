<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Service;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Narrow type-safe accessor for PSR-7 parsed request bodies.
 *
 * PSR-7 declares ServerRequestInterface::getParsedBody() as
 * array|object|null with no guarantees about value types. This helper
 * collapses that to array<string, mixed> and provides typed getters
 * so callers never cast mixed values on their own.
 */
final class RequestBody
{
    /**
     * @var array<string, mixed>
     */
    private array $body;

    /**
     * @param array<string, mixed> $body
     */
    private function __construct(array $body)
    {
        $this->body = $body;
    }

    public static function fromRequest(ServerRequestInterface $request): self
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return new self([]);
        }
        $narrow = [];
        foreach ($body as $key => $value) {
            $narrow[(string)$key] = $value;
        }
        return new self($narrow);
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->body[$key] ?? null;
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string)$value;
        }
        return $default;
    }

    public function trimmedString(string $key): string
    {
        return trim($this->string($key));
    }

    public function has(string $key): bool
    {
        return isset($this->body[$key]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->body;
    }
}
