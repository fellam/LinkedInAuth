<?php
// LinkedInAuth special page: login entry point.

declare(strict_types=1);

class LinkedInAuthSpecialLogin extends SpecialPage {
    public function __construct() {
        parent::__construct( 'LinkedInAuthLogin' );
    }

    /**
     * Only list in Special:SpecialPages for users with linauth.
     *
     * @return bool
     */
    public function isListed(): bool {
        return $this->getUser()->isAllowed( 'linauth' );
    }

    /**
     * Entry point for LinkedIn auth.
     *
     * @param string|null $subPage
     */
    public function execute( $subPage ): void {
        $this->setHeaders();
        $this->getOutput()->disable();

        $this->startOauthSession();

        LinkedInAuthDebug::log( 'LOGIN_START', [
            'host' => $_SERVER['HTTP_HOST'] ?? '',
            'uri' => $_SERVER['REQUEST_URI'] ?? '',
        ] );

        $config = LinkedInAuthConfig::getConfig();
        $clientId = $config['client_id'] ?? null;
        $redirectUri = $config['redirect_uri'] ?? null;
        $hasClientId = is_string( $clientId ) && $clientId !== '';
        $hasRedirect = is_string( $redirectUri ) && $redirectUri !== '';

        if ( !$hasClientId || !$hasRedirect ) {
            LinkedInAuthDebug::log( 'CONFIG_INVALID', [
                'client_id' => $hasClientId ? 'yes' : 'no',
                'redirect_uri' => $hasRedirect ? 'yes' : 'no',
            ] );
            http_response_code( 500 );
            echo 'Configuration error (invalid LinkedIn config). ';
            echo 'Debug: client_id=' . ( $hasClientId ? 'yes' : 'no' ) . ', redirect_uri=' . ( $hasRedirect ? 'yes' : 'no' );
            exit;
        }

        $returnTo = $this->getRequest()->getVal( 'returnTo', '' );
        if ( !is_string( $returnTo ) || $returnTo === '' ) {
            $returnTo = LinkedInAuthConfig::getDefaultReturnTo();
        }
        if ( strpos( $returnTo, '/wiki/' ) === 0 ) {
            $returnTo = substr( $returnTo, 5 );
        }
        if ( $returnTo !== '' && $returnTo[0] !== '/' ) {
            $returnTo = '/' . $returnTo;
        }

        $_SESSION['mp_return_to'] = $returnTo;
        LinkedInAuthDebug::log( 'RETURN_TO_SET', [ 'returnTo' => $returnTo ] );

        try {
            $csrfToken = bin2hex( random_bytes( 16 ) );
        } catch ( Exception $e ) {
            $csrfToken = bin2hex( openssl_random_pseudo_bytes( 16 ) );
        }

        $_SESSION['oauth_csrf'] = $csrfToken;

        $stateData = [
            'csrf' => $csrfToken,
            'returnTo' => $returnTo,
        ];

        $stateJson = json_encode( $stateData, JSON_UNESCAPED_SLASHES );
        $state = base64_encode( $stateJson );

        LinkedInAuthDebug::log( 'STATE_CREATED', [
            'csrf' => $csrfToken,
            'state' => $state,
        ] );

        $authUrl = 'https://www.linkedin.com/oauth/v2/authorization?' . http_build_query( [
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => 'openid profile email',
            'state' => $state,
        ] );

        LinkedInAuthDebug::log( 'REDIRECT_LINKEDIN', [ 'url' => $authUrl ] );

        header( 'Location: ' . $authUrl );
        exit;
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
}
