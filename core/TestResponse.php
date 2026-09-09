<?php

// The result of a test-client request (http_get(), http_post(), ...) with
// chainable assertions. Every assertion returns $this so they can be
// strung together:
//
//   http_post('/login', ['email' => ..., 'password' => ...])
//       ->assertRedirect('/account')
//       ->assertSessionHasNoErrors();

class TestResponse
{
    private const NO_VALUE = "\0none";

    public function __construct(public Response $response, public ?Throwable $exception = null)
    {
    }

    // ------------------------------------------------------------ access --

    public function status(): int
    {
        return $this->response->status;
    }

    public function body(): string
    {
        return $this->response->body;
    }

    public function headers(): array
    {
        return $this->response->headers;
    }

    public function header(string $name): ?string
    {
        return $this->response->headers[ucwords(strtolower($name), '-')] ?? null;
    }

    // Decoded JSON body, or a value at a "dot.path" inside it.
    public function json(?string $path = null)
    {
        $decoded = json_decode($this->body(), true);

        if ($path === null) {
            return $decoded;
        }

        return Collection::valueOf($decoded, $path);
    }

    public function dump(): static
    {
        dump(['status' => $this->status(), 'headers' => $this->headers(), 'body' => $this->body()]);

        return $this;
    }

    // ------------------------------------------------------------ status --

    public function assertStatus(int $status): static
    {
        if ($this->status() !== $status) {
            $this->fail("Expected status $status, got " . $this->status());
        }

        return $this;
    }

    public function assertOk(): static
    {
        return $this->assertStatus(200);
    }

    public function assertCreated(): static
    {
        return $this->assertStatus(201);
    }

    public function assertNotFound(): static
    {
        return $this->assertStatus(404);
    }

    public function assertForbidden(): static
    {
        return $this->assertStatus(403);
    }

    public function assertUnauthorized(): static
    {
        return $this->assertStatus(401);
    }

    public function assertUnprocessable(): static
    {
        return $this->assertStatus(422);
    }

    public function assertServerError(): static
    {
        if ($this->status() < 500) {
            $this->fail('Expected a 5xx status, got ' . $this->status());
        }

        return $this;
    }

    // ---------------------------------------------------------- redirect --

    // assertRedirect() checks for a 3xx; assertRedirect('/login') also
    // compares the path (host and base path ignored).
    public function assertRedirect(?string $to = null): static
    {
        $location = $this->header('Location');

        if ($this->status() < 300 || $this->status() >= 400 || $location === null) {
            $this->fail('Expected a redirect, got status ' . $this->status());
        }

        if ($to !== null && self::pathOf($location) !== self::pathOf($to)) {
            $this->fail("Expected a redirect to [$to], got [$location]");
        }

        return $this;
    }

    public function assertRedirectToRoute(string $name, array $parameters = []): static
    {
        return $this->assertRedirect(route_url($name, $parameters));
    }

    public function assertNoRedirect(): static
    {
        if ($this->status() >= 300 && $this->status() < 400) {
            $this->fail('Did not expect a redirect to [' . $this->header('Location') . ']');
        }

        return $this;
    }

    private static function pathOf(string $url): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '/');
        $base = base_path();

        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }

        $path = '/' . trim($path, '/');
        $query = parse_url($url, PHP_URL_QUERY);

        return $query ? $path . '?' . $query : $path;
    }

    // -------------------------------------------------------------- body --

    public function assertSee(string $text): static
    {
        if (!str_contains($this->body(), $text)) {
            $this->fail('Expected to see ' . var_export($text, true) . ' in the response');
        }

        return $this;
    }

    public function assertDontSee(string $text): static
    {
        if (str_contains($this->body(), $text)) {
            $this->fail('Did not expect to see ' . var_export($text, true) . ' in the response');
        }

        return $this;
    }

    // Like assertSee but ignores HTML tags.
    public function assertSeeText(string $text): static
    {
        if (!str_contains(html_entity_decode(strip_tags($this->body())), $text)) {
            $this->fail('Expected to see text ' . var_export($text, true) . ' in the response');
        }

        return $this;
    }

    public function assertSeeInOrder(array $texts): static
    {
        $offset = 0;

        foreach ($texts as $text) {
            $position = strpos($this->body(), $text, $offset);

            if ($position === false) {
                $this->fail('Expected to see ' . var_export($text, true) . ' after position ' . $offset);
            }

            $offset = $position + strlen($text);
        }

        return $this;
    }

    // -------------------------------------------------------------- json --

    // Asserts the given keys/values appear in the JSON body (subset match).
    public function assertJson(array $expected): static
    {
        $actual = $this->json();

        if (!is_array($actual)) {
            $this->fail('Response is not valid JSON');
        }

        foreach ($expected as $key => $value) {
            if (!array_key_exists($key, $actual) || $actual[$key] != $value) {
                $this->fail('JSON key ' . var_export($key, true) . ' expected ' . test_export($value) . ', got ' . test_export($actual[$key] ?? null));
            }
        }

        return $this;
    }

    public function assertExactJson(array $expected): static
    {
        if ($this->json() !== $expected) {
            $this->fail('JSON body differs: ' . test_export($this->json()));
        }

        return $this;
    }

    public function assertJsonPath(string $path, $expected): static
    {
        $actual = $this->json($path);

        if ($actual != $expected) {
            $this->fail("JSON path [$path] expected " . test_export($expected) . ', got ' . test_export($actual));
        }

        return $this;
    }

    public function assertJsonMissing(string $path): static
    {
        $missing = new stdClass();
        $segments = explode('.', $path);
        $value = $this->json();

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $this;
            }

            $value = $value[$segment];
        }

        $this->fail("JSON path [$path] was present with " . test_export($value));
    }

    public function assertJsonCount(int $count, ?string $path = null): static
    {
        $value = $this->json($path);

        if (!is_array($value) || count($value) !== $count) {
            $this->fail('Expected ' . $count . ' JSON items' . ($path ? " at [$path]" : '') . ', got ' . (is_array($value) ? count($value) : 'none'));
        }

        return $this;
    }

    // ----------------------------------------------------------- headers --

    public function assertHeader(string $name, ?string $value = null): static
    {
        $actual = $this->header($name);

        if ($actual === null) {
            $this->fail("Header [$name] is missing");
        }

        if ($value !== null && $actual !== $value) {
            $this->fail("Header [$name] expected [$value], got [$actual]");
        }

        return $this;
    }

    public function assertHeaderMissing(string $name): static
    {
        if ($this->header($name) !== null) {
            $this->fail("Header [$name] should be absent");
        }

        return $this;
    }

    // ----------------------------------------------------------- session --

    public function assertSessionHas(string $key, $value = self::NO_VALUE): static
    {
        if (!session_has($key)) {
            $this->fail("Session key [$key] is missing");
        }

        if ($value !== self::NO_VALUE && session_get($key) != $value) {
            $this->fail("Session key [$key] expected " . test_export($value) . ', got ' . test_export(session_get($key)));
        }

        return $this;
    }

    public function assertSessionMissing(string $key): static
    {
        if (session_has($key)) {
            $this->fail("Session key [$key] should be absent");
        }

        return $this;
    }

    // Validation errors flashed by this response (visible on the next request).
    public function assertSessionHasErrors(string|array $keys = []): static
    {
        $errors = $this->flashedErrors();

        if ($errors === []) {
            $this->fail('Expected validation errors in the session, found none');
        }

        foreach ((array) $keys as $key => $message) {
            $field = is_int($key) ? $message : $key;

            if (!isset($errors[$field])) {
                $this->fail("Expected a validation error for [$field]; errors: " . implode(', ', array_keys($errors)));
            }

            if (!is_int($key) && !in_array($message, (array) $errors[$field], true)) {
                $this->fail("Expected error [$message] for [$field], got " . test_export($errors[$field]));
            }
        }

        return $this;
    }

    public function assertSessionHasNoErrors(): static
    {
        $errors = $this->flashedErrors();

        if ($errors !== []) {
            $this->fail('Unexpected validation errors: ' . test_export($errors));
        }

        return $this;
    }

    public function assertValid(string|array $keys = []): static
    {
        $errors = $this->flashedErrors();

        foreach ((array) $keys as $key) {
            if (isset($errors[$key])) {
                $this->fail("Expected [$key] to be valid, got " . test_export($errors[$key]));
            }
        }

        return (array) $keys === [] ? $this->assertSessionHasNoErrors() : $this;
    }

    public function assertInvalid(string|array $keys): static
    {
        return $this->assertSessionHasErrors($keys);
    }

    private function flashedErrors(): array
    {
        return (array) ($_SESSION['_flash']['next']['errors'] ?? []);
    }

    // -------------------------------------------------------------- auth --

    public function assertAuthenticated(?Model $as = null, ?string $guard = null): static
    {
        $id = session_get(auth_session_key($guard));

        if ($id === null) {
            $this->fail('Expected an authenticated user, found a guest');
        }

        if ($as !== null && $id != $as->getKey()) {
            $this->fail('Expected user ' . $as->getKey() . ' to be authenticated, got ' . $id);
        }

        return $this;
    }

    public function assertGuest(?string $guard = null): static
    {
        if (session_get(auth_session_key($guard)) !== null) {
            $this->fail('Expected a guest, found an authenticated user');
        }

        return $this;
    }

    // ---------------------------------------------------------- failures --

    private function fail(string $message): never
    {
        $context = ' [status ' . $this->status();

        if ($this->exception !== null) {
            $context .= ', exception ' . get_class($this->exception) . ': ' . $this->exception->getMessage();
        }

        $context .= ']';

        fail($message . $context);
    }
}
