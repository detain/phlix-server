<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Github;

use Phlix\Plugins\Github\GithubOAuthProvider;
use Phlix\Plugins\OAuth2\Pkce;
use Phlix\Shared\Auth\ProviderInterface;
use Phlix\Tests\Unit\Plugins\OAuth2\FakeOAuth2HttpClient;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlix\Plugins\Github\GithubOAuthProvider
 * @covers \Phlix\Plugins\OAuth2\AbstractOAuth2Provider
 */
final class GithubOAuthProviderTest extends TestCase
{
    private function makeProvider(FakeOAuth2HttpClient $http): GithubOAuthProvider
    {
        return new GithubOAuthProvider('client-id', 'client-secret', GithubOAuthProvider::DEFAULT_SCOPES, $http);
    }

    public function test_implements_provider_interface_and_name_is_github(): void
    {
        $provider = $this->makeProvider(new FakeOAuth2HttpClient());

        $this->assertInstanceOf(ProviderInterface::class, $provider);
        $this->assertSame('github', $provider->name());
    }

    public function test_supports_authentication(): void
    {
        $provider = $this->makeProvider(new FakeOAuth2HttpClient());

        $this->assertTrue($provider->supportsAuthentication(['code' => 'abc']));
        $this->assertTrue($provider->supportsAuthentication(['access_token' => 'tok']));
        $this->assertFalse($provider->supportsAuthentication([]));
        $this->assertFalse($provider->supportsAuthentication(['nope' => 'x']));
    }

    public function test_build_authorization_url_carries_pkce_and_state(): void
    {
        $provider = $this->makeProvider(new FakeOAuth2HttpClient());

        $verifier = Pkce::generateCodeVerifier();
        $challenge = Pkce::computeCodeChallenge($verifier);

        $url = $provider->buildAuthorizationUrl('/auth/github/callback', 'state-xyz', $challenge);

        $this->assertStringContainsString(GithubOAuthProvider::AUTHORIZE_URL, $url);
        $this->assertStringContainsString('client_id=client-id', $url);
        $this->assertStringContainsString('response_type=code', $url);
        $this->assertStringContainsString('scope=read%3Auser+user%3Aemail', $url);
        $this->assertStringContainsString('state=state-xyz', $url);
        $this->assertStringContainsString('code_challenge=' . $challenge, $url);
        $this->assertStringContainsString('code_challenge_method=S256', $url);
    }

    public function test_authenticate_with_code_exchanges_and_maps_profile(): void
    {
        $http = new FakeOAuth2HttpClient();
        $http->queue('POST', GithubOAuthProvider::TOKEN_URL, 200, (string) json_encode([
            'access_token' => 'gho_test',
            'token_type' => 'bearer',
        ]));
        $http->queue('GET', GithubOAuthProvider::USER_API_URL, 200, (string) json_encode([
            'id' => 583231,
            'login' => 'octocat',
            'name' => 'The Octocat',
            'email' => 'octocat@github.com',
            'avatar_url' => 'https://avatars.example/oc.png',
        ]));

        $provider = $this->makeProvider($http);

        $result = $provider->authenticate([
            'code' => 'auth-code',
            'redirect_uri' => '/auth/github/callback',
            'code_verifier' => 'verifier',
        ]);

        $this->assertTrue($result->isSuccess());
        // external_id must use the STABLE numeric id, prefixed with the family.
        $this->assertSame('github.583231', $result->externalId);
        $this->assertSame('octocat@github.com', $result->getEmail());
        $this->assertSame('The Octocat', $result->getDisplayName());
        $this->assertSame('github', $result->attributes['provider']);

        // The token exchange must have posted the PKCE verifier to the token URL.
        $post = null;
        foreach ($http->requests as $req) {
            if ($req['method'] === 'POST' && $req['url'] === GithubOAuthProvider::TOKEN_URL) {
                $post = $req;
                break;
            }
        }
        $this->assertNotNull($post);
        $this->assertIsString($post['body']);
        $this->assertStringContainsString('code_verifier=verifier', $post['body']);
        $this->assertStringContainsString('grant_type=authorization_code', $post['body']);
    }

    public function test_null_email_is_tolerated_when_private_and_no_emails_endpoint(): void
    {
        $http = new FakeOAuth2HttpClient();
        $http->queue('POST', GithubOAuthProvider::TOKEN_URL, 200, (string) json_encode([
            'access_token' => 'gho_test',
        ]));
        // Private email → null on /user, and /user/emails is not queued (returns
        // null), so the result email stays null (the create path uses a placeholder).
        $http->queue('GET', GithubOAuthProvider::USER_API_URL, 200, (string) json_encode([
            'id' => 42,
            'login' => 'ghost',
            'name' => null,
            'email' => null,
        ]));

        $provider = $this->makeProvider($http);

        $result = $provider->authenticate([
            'code' => 'auth-code',
            'redirect_uri' => '/auth/github/callback',
        ]);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('github.42', $result->externalId);
        $this->assertNull($result->getEmail());
        // Falls back to the login name for a display name when `name` is null.
        $this->assertSame('ghost', $result->getDisplayName());
    }

    public function test_private_email_recovered_from_emails_endpoint(): void
    {
        $http = new FakeOAuth2HttpClient();
        $http->queue('POST', GithubOAuthProvider::TOKEN_URL, 200, (string) json_encode([
            'access_token' => 'gho_test',
        ]));
        $http->queue('GET', GithubOAuthProvider::USER_API_URL, 200, (string) json_encode([
            'id' => 7,
            'login' => 'priv',
            'email' => null,
        ]));
        $http->queue('GET', GithubOAuthProvider::USER_EMAILS_API_URL, 200, (string) json_encode([
            ['email' => 'secondary@example.com', 'primary' => false, 'verified' => true],
            ['email' => 'primary@example.com', 'primary' => true, 'verified' => true],
            ['email' => 'unverified@example.com', 'primary' => false, 'verified' => false],
        ]));

        $provider = $this->makeProvider($http);

        $result = $provider->authenticate(['code' => 'c', 'redirect_uri' => '/cb']);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('primary@example.com', $result->getEmail());
    }

    public function test_missing_access_token_fails(): void
    {
        $http = new FakeOAuth2HttpClient();
        $http->queue('POST', GithubOAuthProvider::TOKEN_URL, 200, (string) json_encode([
            'scope' => 'read:user',
        ]));

        $provider = $this->makeProvider($http);
        $result = $provider->authenticate(['code' => 'c', 'redirect_uri' => '/cb']);

        $this->assertTrue($result->isFailure());
        $this->assertSame('missing_access_token', $result->error);
    }

    public function test_missing_user_id_fails(): void
    {
        $http = new FakeOAuth2HttpClient();
        $http->queue('POST', GithubOAuthProvider::TOKEN_URL, 200, (string) json_encode([
            'access_token' => 'gho_test',
        ]));
        $http->queue('GET', GithubOAuthProvider::USER_API_URL, 200, (string) json_encode([
            'login' => 'no-id',
        ]));

        $provider = $this->makeProvider($http);
        $result = $provider->authenticate(['code' => 'c', 'redirect_uri' => '/cb']);

        $this->assertTrue($result->isFailure());
        $this->assertSame('missing_user_id', $result->error);
    }

    public function test_token_endpoint_error_is_reported(): void
    {
        $http = new FakeOAuth2HttpClient();
        $http->queue('POST', GithubOAuthProvider::TOKEN_URL, 200, (string) json_encode([
            'error' => 'bad_verification_code',
            'error_description' => 'The code passed is incorrect or expired.',
        ]));

        $provider = $this->makeProvider($http);
        $result = $provider->authenticate(['code' => 'c', 'redirect_uri' => '/cb']);

        $this->assertTrue($result->isFailure());
        $this->assertIsString($result->error);
        $this->assertStringContainsString('bad_verification_code', $result->error);
    }

    public function test_no_supported_credentials_returns_failure(): void
    {
        $provider = $this->makeProvider(new FakeOAuth2HttpClient());

        $result = $provider->authenticate(['unrelated' => 'value']);

        $this->assertTrue($result->isFailure());
        $this->assertSame('no_supported_credentials', $result->error);
    }

    public function test_get_user_info_and_link_account_are_noops(): void
    {
        $provider = $this->makeProvider(new FakeOAuth2HttpClient());

        $this->assertNull($provider->getUserInfo('github.1'));
        $provider->linkAccount('local-1', ['github' => 'github.1']);
        $this->assertSame('client-id', $provider->getClientId());
    }
}
