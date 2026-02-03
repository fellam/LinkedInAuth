<?php
// LinkedInAuth special page: config status / self-test.

declare(strict_types=1);

class LinkedInAuthSpecialStatus extends SpecialPage {
    public function __construct() {
        parent::__construct( 'LinkedInAuthStatus', 'linauth' );
    }

    /**
     * @return bool
     */
    public function isListed(): bool {
        return $this->getUser()->isAllowed( 'linauth' );
    }

    /**
     * @param string|null $subPage
     * @return void
     */
    public function execute( $subPage ): void {
        $this->setHeaders();

        if ( !$this->getUser()->isAllowed( 'linauth' ) ) {
            throw new PermissionsError( 'linauth' );
        }

        $out = $this->getOutput();
        $out->addWikiMsg( 'linkedinauth-status-intro' );

        $config = LinkedInAuthConfig::getConfig();
        $loginUrl = $this->getMainConfigValue( 'LinkedInAuthLoginUrl', $GLOBALS['wgLinkedInAuthLoginUrl'] ?? '' );
        $callbackUrl = $this->getMainConfigValue( 'LinkedInAuthCallbackUrl', $GLOBALS['wgLinkedInAuthCallbackUrl'] ?? '' );
        $debug = $this->getMainConfigValue( 'LinkedInAuthDebug', $GLOBALS['wgLinkedInAuthDebug'] ?? false );
        $hmacKey = $this->getMainConfigValue( 'LinkedInAuthHmacKey', $GLOBALS['wgLinkedInAuthHmacKey'] ?? '' );
        $defaultReturnTo = $this->getMainConfigValue( 'LinkedInAuthDefaultReturnTo', $GLOBALS['wgLinkedInAuthDefaultReturnTo'] ?? '' );

        $rows = [
            'LinkedInAuthClientId' => $this->presentValue( $config['client_id'] ?? '' ),
            'LinkedInAuthClientSecret' => $this->presentSecret( $config['client_secret'] ?? '' ),
            'LinkedInAuthRedirectUri' => $this->presentValue( $config['redirect_uri'] ?? '' ),
            'LinkedInAuthCallbackUrl' => $this->presentValue( $callbackUrl ),
            'LinkedInAuthLoginUrl' => $this->presentValue( $loginUrl ),
            'LinkedInAuthDefaultReturnTo' => $this->presentValue( is_string( $defaultReturnTo ) ? $defaultReturnTo : '' ),
            'LinkedInAuthHmacKey' => $this->presentSecret( is_string( $hmacKey ) ? $hmacKey : '' ),
            'LinkedInAuthDebug' => $debug ? 'true' : 'false',
        ];

        $html = '<table class="wikitable"><tbody>';
        foreach ( $rows as $key => $value ) {
            $html .= '<tr><th>' . htmlspecialchars( $key ) . '</th><td>' . htmlspecialchars( $value ) . '</td></tr>';
        }
        $html .= '</tbody></table>';

        $out->addHTML( $html );

        $out->addHTML( $this->buildConnectivitySection() );
    }

    /**
     * @return string
     */
    private function buildConnectivitySection(): string {
        $title = SpecialPage::getTitleFor( 'LinkedInAuthStatus' );
        $testUrl = $title->getLocalURL( [ 'action' => 'test' ] );
        $html = '<h3>' . htmlspecialchars( $this->msg( 'linkedinauth-status-connectivity-title' )->text() ) . '</h3>';
        $html .= '<p><a class="mw-ui-button" href="' . htmlspecialchars( $testUrl ) . '">'
            . htmlspecialchars( $this->msg( 'linkedinauth-status-connectivity-run' )->text() ) . '</a></p>';

        if ( $this->getRequest()->getVal( 'action' ) === 'test' ) {
            $html .= $this->runConnectivityTest();
        }

        return $html;
    }

    /**
     * @return string
     */
    private function runConnectivityTest(): string {
        $results = [];

        if ( !function_exists( 'curl_init' ) ) {
            $results[] = [
                'label' => 'cURL',
                'status' => 'missing',
                'detail' => 'PHP cURL extension not available',
            ];
        } else {
            $results[] = $this->testEndpoint( 'https://www.linkedin.com/.well-known/openid-configuration' );
        }

        $html = '<table class="wikitable"><tbody>';
        foreach ( $results as $row ) {
            $html .= '<tr><th>' . htmlspecialchars( $row['label'] ) . '</th><td>'
                . htmlspecialchars( $row['status'] . ' - ' . $row['detail'] ) . '</td></tr>';
        }
        $html .= '</tbody></table>';

        return $html;
    }

    /**
     * @param string $value
     * @return string
     */
    private function presentValue( string $value ): string {
        return $value !== '' ? $value : '(empty)';
    }

    /**
     * @param string $secret
     * @return string
     */
    private function presentSecret( string $secret ): string {
        if ( $secret === '' ) {
            return '(empty)';
        }
        $len = strlen( $secret );
        if ( $len <= 8 ) {
            return str_repeat( '*', $len );
        }
        return substr( $secret, 0, 3 ) . str_repeat( '*', $len - 6 ) . substr( $secret, -3 );
    }

    /**
     * @param string $key
     * @param mixed $fallback
     * @return mixed
     */
    private function getMainConfigValue( string $key, $fallback ) {
        $config = MediaWiki\MediaWikiServices::getInstance()->getMainConfig();
        try {
            return $config->get( $key );
        } catch ( MediaWiki\Config\ConfigException $e ) {
            return $fallback;
        }
    }

    /**
     * @param string $url
     * @return array{label:string,status:string,detail:string}
     */
    private function testEndpoint( string $url ): array {
        $ch = curl_init( $url );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
        curl_setopt( $ch, CURLOPT_TIMEOUT, 5 );
        curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 5 );
        curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );

        $body = curl_exec( $ch );
        $err = curl_error( $ch );
        $status = (int)curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
        curl_close( $ch );

        if ( $body === false ) {
            return [
                'label' => $url,
                'status' => 'error',
                'detail' => $err !== '' ? $err : 'request failed',
            ];
        }

        $detail = $status > 0 ? 'HTTP ' . $status : 'no status';
        return [
            'label' => $url,
            'status' => $status >= 200 && $status < 300 ? 'ok' : 'warn',
            'detail' => $detail,
        ];
    }
}
