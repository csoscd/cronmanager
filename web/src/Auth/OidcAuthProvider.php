<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – OIDC Authentication Provider
 *
 * Implements the OAuth 2.0 Authorization Code Flow with PKCE for OpenID
 * Connect providers (e.g. Authentik, Keycloak, Authelia).
 *
 * The provider's discovery document is fetched from:
 *   {oidc_provider_url}.well-known/openid-configuration
 *
 * Session keys used:
 *   oidc_state         – CSRF state token
 *   oidc_code_verifier – PKCE code verifier
 *   oidc_discovery     – cached discovery document (array)
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Web\Auth;

use Cronmanager\Web\Session\SessionManager;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Monolog\Logger;
use Noodlehaus\Config;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Class OidcAuthProvider
 *
 * Handles the Authorization Code + PKCE flow:
 *   1. getAuthorizationUrl() – builds the URL to redirect the user to.
 *   2. handleCallback()      – exchanges the code for tokens and resolves a
 *                              local user record.
 */
class OidcAuthProvider
{
    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * @param Config $config Noodlehaus configuration (auth.* keys).
     * @param PDO    $pdo    Active PDO connection for user lookup / creation.
     * @param Logger $logger Monolog logger.
     */
    public function __construct(
        private readonly Config $config,
        private readonly PDO    $pdo,
        private readonly Logger $logger,
    ) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Check whether OIDC authentication is enabled in configuration.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return (bool) $this->config->get('auth.oidc_enabled', false);
    }

    /**
     * Build the authorization URL to redirect the user to.
     *
     * Steps performed:
     *   1. Generate a cryptographically random PKCE code_verifier.
     *   2. Derive the code_challenge (S256).
     *   3. Generate a random state token (CSRF protection).
     *   4. Store both in the session.
     *   5. Fetch the OIDC discovery document.
     *   6. Build and return the authorization URL.
     *
     * @return string Full authorization URL.
     *
     * @throws RuntimeException When the discovery document cannot be fetched.
     */
    public function getAuthorizationUrl(): string
    {
        // ------------------------------------------------------------------
        // PKCE
        // ------------------------------------------------------------------
        $codeVerifier  = bin2hex(random_bytes(32));
        $codeChallenge = rtrim(
            strtr(base64_encode(hash('sha256', $codeVerifier, binary: true)), '+/', '-_'),
            '='
        );

        // ------------------------------------------------------------------
        // State (CSRF token)
        // ------------------------------------------------------------------
        $state = bin2hex(random_bytes(16));

        // Persist in session for callback validation
        SessionManager::set('oidc_state', $state);
        SessionManager::set('oidc_code_verifier', $codeVerifier);

        // ------------------------------------------------------------------
        // Discovery document
        // ------------------------------------------------------------------
        $discovery = $this->fetchDiscovery();

        // ------------------------------------------------------------------
        // Build authorization URL
        // ------------------------------------------------------------------
        $params = http_build_query([
            'response_type'         => 'code',
            'client_id'             => (string) $this->config->get('auth.oidc_client_id', ''),
            'redirect_uri'          => (string) $this->config->get('auth.oidc_redirect_uri', ''),
            'scope'                 => 'openid email profile',
            'state'                 => $state,
            'code_challenge'        => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);

        $authEndpoint = (string) ($discovery['authorization_endpoint'] ?? '');

        if ($authEndpoint === '') {
            throw new RuntimeException('OIDC discovery: authorization_endpoint is missing.');
        }

        $url = $authEndpoint . '?' . $params;

        $this->logger->debug('OIDC: built authorization URL', ['state' => $state]);

        return $url;
    }

    /**
     * Handle the callback from the OIDC provider.
     *
     * Steps performed:
     *   1. Validate state to prevent CSRF attacks.
     *   2. Fetch the discovery document.
     *   3. Exchange the authorization code for tokens (token endpoint).
     *   4. Fetch user information (userinfo endpoint).
     *   5. Find or create the local user record.
     *
     * @param string $code  Authorization code received in the callback query string.
     * @param string $state State parameter received in the callback (CSRF check).
     *
     * @return array<string, mixed>|null Local user row on success, null on failure.
     *
     * @throws RuntimeException On OIDC protocol errors (state mismatch, missing endpoints, etc.).
     */
    public function handleCallback(string $code, string $state): ?array
    {
        // ------------------------------------------------------------------
        // CSRF / state validation
        // ------------------------------------------------------------------
        $expectedState = (string) SessionManager::get('oidc_state', '');

        if ($state === '' || !hash_equals($expectedState, $state)) {
            $this->logger->warning('OIDC: state mismatch in callback');
            throw new RuntimeException('OIDC state validation failed. Please try again.');
        }

        $codeVerifier = (string) SessionManager::get('oidc_code_verifier', '');

        // Clean up single-use session values
        SessionManager::remove('oidc_state');
        SessionManager::remove('oidc_code_verifier');

        // ------------------------------------------------------------------
        // Discovery document
        // ------------------------------------------------------------------
        $discovery = $this->fetchDiscovery();

        // ------------------------------------------------------------------
        // Token exchange
        // ------------------------------------------------------------------
        $tokenEndpoint = (string) ($discovery['token_endpoint'] ?? '');
        if ($tokenEndpoint === '') {
            throw new RuntimeException('OIDC discovery: token_endpoint is missing.');
        }

        $tokens = $this->exchangeCodeForTokens(
            tokenEndpoint: $tokenEndpoint,
            code:          $code,
            codeVerifier:  $codeVerifier,
        );

        $accessToken = (string) ($tokens['access_token'] ?? '');
        if ($accessToken === '') {
            $this->logger->error('OIDC: token exchange returned no access_token');
            return null;
        }

        // ------------------------------------------------------------------
        // User info
        // ------------------------------------------------------------------
        $userinfoEndpoint = (string) ($discovery['userinfo_endpoint'] ?? '');
        if ($userinfoEndpoint === '') {
            throw new RuntimeException('OIDC discovery: userinfo_endpoint is missing.');
        }

        $userInfo = $this->fetchUserInfo($userinfoEndpoint, $accessToken);

        // ------------------------------------------------------------------
        // Resolve local user
        // ------------------------------------------------------------------
        return $this->resolveLocalUser($userInfo);
    }

    // -------------------------------------------------------------------------
    // Private helpers – OIDC protocol
    // -------------------------------------------------------------------------

    /**
     * Fetch and optionally cache the OIDC discovery document.
     *
     * The document is stored in the session for the duration of the request
     * to avoid duplicate HTTP calls within the same page load.
     *
     * @return array<string, mixed> Decoded discovery document.
     *
     * @throws RuntimeException When the discovery document cannot be fetched or decoded.
     */
    private function fetchDiscovery(): array
    {
        // Return cached version if available
        $cached = SessionManager::get('oidc_discovery');
        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        $providerUrl  = rtrim((string) $this->config->get('auth.oidc_provider_url', ''), '/');
        $discoveryUrl = $providerUrl . '/.well-known/openid-configuration';

        try {
            $client   = $this->createGuzzleClient();
            $response = $client->get($discoveryUrl);
            $body     = (string) $response->getBody();
        } catch (GuzzleException $e) {
            $this->logger->error('OIDC: failed to fetch discovery document', [
                'url'     => $discoveryUrl,
                'message' => $e->getMessage(),
            ]);
            throw new RuntimeException(
                sprintf('Failed to fetch OIDC discovery document from %s.', $discoveryUrl),
                previous: $e
            );
        }

        $document = json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($document)) {
            throw new RuntimeException('OIDC discovery document is not a valid JSON object.');
        }

        // Cache in session for subsequent calls in the same request
        SessionManager::set('oidc_discovery', $document);

        $this->logger->debug('OIDC: discovery document fetched', ['url' => $discoveryUrl]);

        return $document;
    }

    /**
     * Exchange an authorization code for tokens at the token endpoint.
     *
     * @param string $tokenEndpoint Token endpoint URL from the discovery document.
     * @param string $code          Authorization code from the callback.
     * @param string $codeVerifier  PKCE code verifier stored in session.
     *
     * @return array<string, mixed> Decoded token response.
     *
     * @throws RuntimeException On HTTP or JSON errors.
     */
    private function exchangeCodeForTokens(
        string $tokenEndpoint,
        string $code,
        string $codeVerifier,
    ): array {
        try {
            $client   = $this->createGuzzleClient();
            $response = $client->post($tokenEndpoint, [
                'form_params' => [
                    'grant_type'    => 'authorization_code',
                    'code'          => $code,
                    'redirect_uri'  => (string) $this->config->get('auth.oidc_redirect_uri', ''),
                    'client_id'     => (string) $this->config->get('auth.oidc_client_id', ''),
                    'client_secret' => (string) $this->config->get('auth.oidc_client_secret', ''),
                    'code_verifier' => $codeVerifier,
                ],
            ]);

            $tokens = json_decode(
                (string) $response->getBody(),
                associative: true,
                flags: JSON_THROW_ON_ERROR
            );
        } catch (GuzzleException $e) {
            $this->logger->error('OIDC: token exchange failed', ['message' => $e->getMessage()]);
            throw new RuntimeException('OIDC token exchange failed.', previous: $e);
        }

        if (!is_array($tokens)) {
            throw new RuntimeException('OIDC token response is not a valid JSON object.');
        }

        $this->logger->debug('OIDC: token exchange successful');

        return $tokens;
    }

    /**
     * Fetch user information from the userinfo endpoint using a Bearer token.
     *
     * @param string $userinfoEndpoint Userinfo endpoint URL from the discovery document.
     * @param string $accessToken      Access token obtained from the token endpoint.
     *
     * @return array<string, mixed> Decoded userinfo response.
     *
     * @throws RuntimeException On HTTP or JSON errors.
     */
    private function fetchUserInfo(string $userinfoEndpoint, string $accessToken): array
    {
        try {
            $client   = $this->createGuzzleClient();
            $response = $client->get($userinfoEndpoint, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
            ]);

            $userInfo = json_decode(
                (string) $response->getBody(),
                associative: true,
                flags: JSON_THROW_ON_ERROR
            );
        } catch (GuzzleException $e) {
            $this->logger->error('OIDC: userinfo request failed', ['message' => $e->getMessage()]);
            throw new RuntimeException('Failed to fetch OIDC user info.', previous: $e);
        }

        if (!is_array($userInfo)) {
            throw new RuntimeException('OIDC userinfo response is not a valid JSON object.');
        }

        $this->logger->debug('OIDC: userinfo fetched', ['sub' => $userInfo['sub'] ?? 'unknown']);

        return $userInfo;
    }

    // -------------------------------------------------------------------------
    // Private helpers – user resolution
    // -------------------------------------------------------------------------

    /**
     * Find or create a local user record based on OIDC userinfo claims.
     *
     * Resolution order:
     *   1. Look up by oauth_sub                                  → found → return
     *   2. Look up by username (using email as username)         → found → link oauth_sub → return
     *   3. Neither found → INSERT new user (role: view, no password)
     *
     * @param array<string, mixed> $userInfo Decoded OIDC userinfo claims.
     *
     * @return array<string, mixed>|null Local user row, or null on database error.
     *
     * @throws RuntimeException On unexpected database errors.
     */
    private function resolveLocalUser(array $userInfo): ?array
    {
        $sub      = (string) ($userInfo['sub']                ?? '');
        $email    = (string) ($userInfo['email']              ?? '');
        $username = (string) ($userInfo['preferred_username'] ?? $userInfo['name'] ?? $email);

        if ($sub === '') {
            $this->logger->error('OIDC: userinfo missing required "sub" claim');
            return null;
        }

        try {
            // ----------------------------------------------------------------
            // 1. Lookup by oauth_sub
            // ----------------------------------------------------------------
            $stmt = $this->pdo->prepare(
                'SELECT id, username, role, oauth_sub FROM users WHERE oauth_sub = :sub LIMIT 1'
            );
            $stmt->execute([':sub' => $sub]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row !== false) {
                $this->logger->info('OIDC: user found by oauth_sub', [
                    'username' => $row['username'],
                    'sub'      => $sub,
                ]);
                return $row;
            }

            // ----------------------------------------------------------------
            // 2. Lookup by username (email)
            // ----------------------------------------------------------------
            $stmt = $this->pdo->prepare(
                'SELECT id, username, role, oauth_sub FROM users WHERE username = :username LIMIT 1'
            );
            $stmt->execute([':username' => $email !== '' ? $email : $username]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row !== false) {
                // Link the OIDC subject to the existing account
                $update = $this->pdo->prepare(
                    'UPDATE users SET oauth_sub = :sub WHERE id = :id'
                );
                $update->execute([':sub' => $sub, ':id' => $row['id']]);
                $row['oauth_sub'] = $sub;

                $this->logger->info('OIDC: linked oauth_sub to existing user', [
                    'username' => $row['username'],
                    'sub'      => $sub,
                ]);

                return $row;
            }

            // ----------------------------------------------------------------
            // 3. Create new user
            // ----------------------------------------------------------------
            $newUsername = $email !== '' ? $email : $username;

            $stmt = $this->pdo->prepare(
                'INSERT INTO users (username, password_hash, role, oauth_sub)
                 VALUES (:username, NULL, :role, :sub)'
            );
            $stmt->execute([
                ':username' => $newUsername,
                ':role'     => 'view',
                ':sub'      => $sub,
            ]);

            $newId = (int) $this->pdo->lastInsertId();

            $this->logger->info('OIDC: new user created via OIDC', [
                'id'       => $newId,
                'username' => $newUsername,
                'sub'      => $sub,
            ]);

            return [
                'id'        => $newId,
                'username'  => $newUsername,
                'role'      => 'view',
                'oauth_sub' => $sub,
            ];

        } catch (PDOException $e) {
            $this->logger->error('OIDC: database error during user resolution', [
                'message' => $e->getMessage(),
                'sub'     => $sub,
            ]);
            throw new RuntimeException('OIDC user resolution failed due to a database error.', previous: $e);
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers – HTTP client
    // -------------------------------------------------------------------------

    /**
     * Create a configured Guzzle HTTP client.
     *
     * Timeout is intentionally kept short (10 s) since the OIDC requests
     * happen inline during the login flow and a hung provider should fail fast.
     *
     * SSL verification is controlled by two config keys:
     *   auth.oidc_ssl_verify    – true (default) | false (disable, insecure!)
     *   auth.oidc_ssl_ca_bundle – path to a custom CA certificate bundle (PEM).
     *                             When set, takes precedence over oidc_ssl_verify.
     *
     * @return Client
     */
    private function createGuzzleClient(): Client
    {
        // Determine the Guzzle `verify` value:
        //   string  → path to custom CA bundle (option a)
        //   false   → disable SSL verification entirely (option b)
        //   true    → use system CA bundle (default)
        $caBundle  = trim((string) $this->config->get('auth.oidc_ssl_ca_bundle', ''));
        $sslVerify = $this->config->get('auth.oidc_ssl_verify', true);

        if ($caBundle !== '') {
            $verify = $caBundle;                  // custom CA bundle path
        } elseif ($sslVerify === false || $sslVerify === 'false' || $sslVerify === '0') {
            $verify = false;                      // disable verification (insecure)
        } else {
            $verify = true;                       // system CA bundle (default)
        }

        return new Client([
            'timeout'         => 10.0,
            'connect_timeout' => 5.0,
            'http_errors'     => true,
            'verify'          => $verify,
        ]);
    }
}
