<?php

/**
 * Loads config/cors.php with FRONTEND_URL set to the given value.
 *
 * @return array<string, mixed>
 */
function corsConfigWithFrontendUrl(?string $value): array
{
    $previous = getenv('FRONTEND_URL');

    if ($value === null) {
        putenv('FRONTEND_URL');
        unset($_ENV['FRONTEND_URL'], $_SERVER['FRONTEND_URL']);
    } else {
        putenv("FRONTEND_URL={$value}");
        $_ENV['FRONTEND_URL'] = $_SERVER['FRONTEND_URL'] = $value;
    }

    try {
        return require config_path('cors.php');
    } finally {
        if ($previous === false) {
            putenv('FRONTEND_URL');
            unset($_ENV['FRONTEND_URL'], $_SERVER['FRONTEND_URL']);
        } else {
            putenv("FRONTEND_URL={$previous}");
            $_ENV['FRONTEND_URL'] = $_SERVER['FRONTEND_URL'] = $previous;
        }
    }
}

it('reads the allowed origins from FRONTEND_URL separated by commas', function () {
    $config = corsConfigWithFrontendUrl(' http://localhost:5173 , https://app.legumex.com,, ');

    expect($config['allowed_origins'])->toBe(['http://localhost:5173', 'https://app.legumex.com']);
});

it('allows no origin when FRONTEND_URL is missing', function () {
    expect(corsConfigWithFrontendUrl(null)['allowed_origins'])->toBe([]);
});

it('answers the preflight only for a configured origin', function () {
    config(['cors.allowed_origins' => ['http://localhost:5173', 'https://app.legumex.com']]);

    $preflight = fn (string $origin) => $this->call('OPTIONS', '/api/auth/login', server: [
        'HTTP_ORIGIN' => $origin,
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
    ]);

    $preflight('https://app.legumex.com')
        ->assertHeader('Access-Control-Allow-Origin', 'https://app.legumex.com');

    $preflight('https://evil.example.com')
        ->assertHeaderMissing('Access-Control-Allow-Origin');
});
