<?php
// LinkedInAuth special page: OAuth callback handler.

declare(strict_types=1);

class LinkedInAuthSpecialCallback extends SpecialPage {
    public function __construct() {
        parent::__construct( 'LinkedInAuthCallback' );
    }

    /**
     * @return bool
     */
    public function isListed(): bool {
        return false;
    }

    /**
     * @param string|null $subPage
     * @return void
     */
    public function execute( $subPage ): void {
        $this->setHeaders();
        $this->getOutput()->disable();

        $this->startOauthSession();

        LinkedInAuthDebug::log( 'CALLBACK_START', [
            'host' => $_SERVER['HTTP_HOST'] ?? '',
            'uri' => $_SERVER['REQUEST_URI'] ?? '',
        ] );

        $config = LinkedInAuthConfig::getConfig();
        $clientId = $config['client_id'] ?? null;
        $clientSecret = $config['client_secret'] ?? null;
        $redirectUri = $config['redirect_uri'] ?? null;

        if ( empty( $clientId ) || empty( $clientSecret ) || empty( $redirectUri ) ) {
            echo '<p>Si è verificato un errore nell\'autenticazione con LinkedIn (config incompleta).</p>';
            LinkedInAuthDebug::log( 'CONFIG_INVALID', [
                'client_id' => empty( $clientId ) ? 'no' : 'yes',
                'client_secret' => empty( $clientSecret ) ? 'no' : 'yes',
                'redirect_uri' => empty( $redirectUri ) ? 'no' : 'yes',
            ] );
            exit;
        }

        $code = $this->getRequest()->getVal( 'code', '' );
        $stateEncoded = $this->getRequest()->getVal( 'state', '' );

        if ( $code === '' || $stateEncoded === '' ) {
            echo '<p>Si è verificato un errore nell\'autenticazione con LinkedIn (parametri mancanti).</p>';
            LinkedInAuthDebug::log( 'PARAMS_MISSING' );
            exit;
        }

        $state = json_decode( base64_decode( $stateEncoded ), true ) ?: [];
        $csrfFromState = $state['csrf'] ?? '';
        $csrfFromSession = $_SESSION['oauth_csrf'] ?? '';
        if ( $csrfFromSession !== '' && $csrfFromState !== '' && !hash_equals( $csrfFromSession, $csrfFromState ) ) {
            echo '<p>Si è verificato un errore nell\'autenticazione con LinkedIn (csrf non valido).</p>';
            LinkedInAuthDebug::log( 'CSRF_INVALID' );
            exit;
        }

        $returnTo = $state['returnTo'] ?? ( $_SESSION['mp_return_to'] ?? '/Main_Page' );
        if ( !is_string( $returnTo ) || $returnTo === '' ) {
            $returnTo = '/Main_Page';
        }
        if ( strpos( $returnTo, '/wiki/' ) === 0 ) {
            $returnTo = substr( $returnTo, 5 );
        }
        if ( preg_match( '~^https?://~i', $returnTo ) || strpos( $returnTo, '//' ) === 0 ) {
            $returnTo = '/Main_Page';
        } elseif ( strpos( $returnTo, '/' ) !== 0 ) {
            $returnTo = '/' . $returnTo;
        }

        $tokenUrl = 'https://www.linkedin.com/oauth/v2/accessToken';
        $postFields = http_build_query( [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ] );

        $ch = curl_init( $tokenUrl );
        curl_setopt( $ch, CURLOPT_POST, true );
        curl_setopt( $ch, CURLOPT_POSTFIELDS, $postFields );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
        curl_setopt( $ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded',
        ] );

        $tokenResponse = curl_exec( $ch );
        $tokenErr = curl_error( $ch );
        $tokenStatus = (int)curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
        curl_close( $ch );

        $tokenData = json_decode( $tokenResponse, true );
        if ( !is_array( $tokenData ) || !isset( $tokenData['access_token'] ) ) {
            echo '<p>Si è verificato un errore nell\'autenticazione con LinkedIn (access token).</p>';
            LinkedInAuthDebug::log( 'TOKEN_EXCHANGE_FAILED', [
                'response' => $tokenResponse ?: '',
                'error' => $tokenErr,
                'status' => $tokenStatus,
            ] );
            exit;
        }

        $accessToken = $tokenData['access_token'];

        $userinfoUrl = 'https://api.linkedin.com/v2/userinfo';
        $ch2 = curl_init( $userinfoUrl );
        curl_setopt( $ch2, CURLOPT_RETURNTRANSFER, true );
        curl_setopt( $ch2, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $accessToken",
        ] );
        $userinfo = curl_exec( $ch2 );
        curl_close( $ch2 );

        $userData = json_decode( $userinfo, true ) ?: [];
        $sub = $userData['sub'] ?? '';
        $name = $userData['name'] ?? '';
        $email = $userData['email'] ?? '';
        $picture = $userData['picture'] ?? '';
        $givenName = $userData['given_name'] ?? '';
        $familyName = $userData['family_name'] ?? '';

        if ( !$sub || !$name || !$email ) {
            echo '<p>Si è verificato un errore nell\'autenticazione con LinkedIn (dati mancanti).</p>';
            LinkedInAuthDebug::log( 'USERINFO_MISSING', [
                'sub' => $sub ? 'yes' : 'no',
                'email' => $email ? 'yes' : 'no',
            ] );
            exit;
        }

        $this->saveLinkedInToken( $sub, $accessToken, (int)( $tokenData['expires_in'] ?? 0 ) );
        LinkedInAuthDebug::log( 'USER_APPROVED', [ 'sub' => $sub, 'email' => $email ] );

        $hmacKey = $this->getHmacKey();
        if ( $hmacKey === '' ) {
            echo '<p>Errore interno (chiave sessione mancante).</p>';
            LinkedInAuthDebug::log( 'HMAC_MISSING' );
            exit;
        }

        $payload = [
            'sub' => $sub,
            'name' => $name,
            'email' => $email,
            'picture' => $picture,
            'first_name' => $givenName,
            'last_name' => $familyName,
            'returnTo' => $returnTo,
            'exp' => time() + 120,
        ];

        $payloadJson = json_encode( $payload, JSON_UNESCAPED_UNICODE );
        $payloadB64 = rtrim( strtr( base64_encode( $payloadJson ), '+/', '-_' ), '=' );
        $sig = hash_hmac( 'sha256', $payloadB64, $hmacKey );
        $token = $payloadB64 . '.' . $sig;

        $autoLoginUrl = LinkedInAuthConfig::getAutoLoginUrl();
        $separator = strpos( $autoLoginUrl, '?' ) === false ? '?' : '&';
        header( 'Location: ' . $autoLoginUrl . $separator . 'token=' . urlencode( $token ) );
        LinkedInAuthDebug::log( 'REDIRECT_AUTOLOGIN', [
            'returnTo' => $returnTo,
            'url' => $autoLoginUrl,
        ] );
        exit;
    }

    /**
     * @return string
     */
    private function getHmacKey(): string {
        $config = MediaWiki\MediaWikiServices::getInstance()->getMainConfig();
        try {
            $value = $config->get( 'LinkedInAuthHmacKey' );
        } catch ( MediaWiki\Config\ConfigException $e ) {
            $value = $GLOBALS['wgLinkedInAuthHmacKey'] ?? '';
        }
        return is_string( $value ) ? trim( $value ) : '';
    }

    /**
     * @return void
     */
    private function startOauthSession(): void {
        if ( session_status() === PHP_SESSION_ACTIVE ) {
            return;
        }

        session_name( 'oauth_linkedin' );
        session_set_cookie_params( [
            'lifetime' => 600,
            'path' => '/',
            'domain' => '.masticationpedia.org',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'None',
        ] );
        session_start();
    }

    /**
     * @param string $sub
     * @param string $accessToken
     * @param int $expiresIn
     * @return void
     */
    private function saveLinkedInToken( string $sub, string $accessToken, int $expiresIn ): void {
        $dbw = MediaWiki\MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );
        $now = $dbw->timestamp();
        $expiresAt = time() + max( 0, $expiresIn );

        $dbw->upsert(
            'linkedinauth_tokens',
            [
                'lat_sub' => $sub,
                'lat_access_token' => $accessToken,
                'lat_expires_at' => $expiresAt,
                'lat_updated_at' => $now,
            ],
            [ 'lat_sub' ],
            [
                'lat_access_token' => $accessToken,
                'lat_expires_at' => $expiresAt,
                'lat_updated_at' => $now,
            ],
            __METHOD__
        );
        LinkedInAuthDebug::log( 'DB_TOKEN_SAVED', [ 'sub' => $sub ] );
    }

    /**
     * @param string $path
     * @return array
     */
    // JSON file helpers removed; data now stored in DB.
}
