<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Tests\Functional\Fixtures;

use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The WorkOS API calls of the login flows, answered in-process: the
 * workos_fake_api test extension hands {@see handlerStack()} to the SDK, so a
 * functional test can complete a sign-in through the TYPO3 frontend.
 *
 * The state is static because every frontend sub-request boots a new
 * container; {@see reset()} it in setUp().
 */
final class FakeWorkosApi
{
    public const string USER_ID = 'user_01FAKEVISITOR';
    public const string EMAIL = 'visitor@example.com';
    public const string PENDING_TOKEN = 'pending_auth_fake';

    private const string EMAIL_VERIFICATION_GRANT = 'urn:workos:oauth:grant-type:email-verification:code';

    /**
     * Password and email-code sign-ins answer `email_verification_required`
     * until the emailed verification code is submitted.
     */
    public static bool $requireEmailVerification = false;

    /**
     * @var list<string> `METHOD /path` of every call, in order
     */
    public static array $calls = [];

    private function __construct() {}

    public static function reset(): void
    {
        self::$requireEmailVerification = false;
        self::$calls = [];
    }

    /**
     * @return HandlerStack<callable(RequestInterface, array<array-key, mixed>): PromiseInterface<ResponseInterface, mixed>>
     */
    public static function handlerStack(): HandlerStack
    {
        return HandlerStack::create(
            static fn(RequestInterface $request, array $options): PromiseInterface => Create::promiseFor(self::respond($request))
        );
    }

    private static function respond(RequestInterface $request): Response
    {
        $call = $request->getMethod() . ' ' . $request->getUri()->getPath();
        self::$calls[] = $call;
        $body = json_decode((string)$request->getBody(), true);
        $grantType = is_array($body) && is_string($body['grant_type'] ?? null) ? $body['grant_type'] : '';

        return match ($call) {
            'POST /user_management/authenticate' => self::authenticate($grantType),
            'POST /user_management/magic_auth' => self::json(201, [
                'id' => 'magic_auth_fake',
                'user_id' => self::USER_ID,
                'email' => self::EMAIL,
                'expires_at' => '2030-01-01T00:10:00.000Z',
                'created_at' => '2030-01-01T00:00:00.000Z',
                'updated_at' => '2030-01-01T00:00:00.000Z',
                'code' => '123456',
            ]),
            'POST /user_management/users', 'GET /user_management/users/' . self::USER_ID => self::json(200, self::user()),
            default => self::json(404, ['message' => 'Not faked: ' . $call]),
        };
    }

    private static function authenticate(string $grantType): Response
    {
        if (self::$requireEmailVerification && $grantType !== self::EMAIL_VERIFICATION_GRANT) {
            return self::json(403, [
                'code' => 'email_verification_required',
                'message' => 'Email ownership must be verified before authentication.',
                'pending_authentication_token' => self::PENDING_TOKEN,
                'email' => self::EMAIL,
                'user_id' => self::USER_ID,
            ]);
        }

        return self::json(200, [
            'user' => self::user(),
            'access_token' => 'access_token_fake',
            'refresh_token' => 'refresh_token_fake',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function user(): array
    {
        return [
            'object' => 'user',
            'id' => self::USER_ID,
            'email' => self::EMAIL,
            'email_verified' => true,
            'first_name' => 'Vera',
            'last_name' => 'Visitor',
            'created_at' => '2030-01-01T00:00:00.000Z',
            'updated_at' => '2030-01-01T00:00:00.000Z',
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function json(int $status, array $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }
}
