<?php

namespace Midtrans;

/**
 * The SDK's own test hook: `ApiRequestor` looks for a class of exactly this
 * name and, when `$stubHttp` is on, answers from it instead of running cURL.
 *
 * It is not shipped in the package's autoloaded files, so it is declared here
 * and loaded only by the test that needs it. With `$stubHttp` off — the
 * default — the SDK behaves as if this class did not exist.
 */
class MT_Tests
{
    public static bool $stubHttp = false;

    public static string|false $stubHttpResponse = '';

    /** @var array<string, mixed> */
    public static array $lastHttpRequest = [];
}
