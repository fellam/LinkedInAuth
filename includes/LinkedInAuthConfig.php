<?php
// LinkedInAuth configuration helper.

declare(strict_types=1);

class LinkedInAuthConfig {
    /**
     * @return array{client_id:?string,client_secret:?string,redirect_uri:?string}
     */
    public static function getConfig(): array {
        $config = MediaWiki\MediaWikiServices::getInstance()->getMainConfig();

        $clientId = self::normalize( self::safeGet( $config, 'LinkedInAuthClientId', $GLOBALS['wgLinkedInAuthClientId'] ?? null ) );
        $clientSecret = self::normalize( self::safeGet( $config, 'LinkedInAuthClientSecret', $GLOBALS['wgLinkedInAuthClientSecret'] ?? null ) );
        $redirectUri = self::normalize( self::safeGet( $config, 'LinkedInAuthRedirectUri', $GLOBALS['wgLinkedInAuthRedirectUri'] ?? null ) );
        $callbackUrl = self::normalize( self::safeGet( $config, 'LinkedInAuthCallbackUrl', $GLOBALS['wgLinkedInAuthCallbackUrl'] ?? null ) );

        if ( $callbackUrl !== null ) {
            $redirectUri = $callbackUrl;
        }

        if ( $clientId !== null && $redirectUri !== null ) {
            return [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'redirect_uri' => $redirectUri,
            ];
        }
        if ( $redirectUri === null ) {
            $redirectUri = self::defaultRedirectUri();
        }

        return [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
        ];
    }

    /**
     * @return string
     */
    public static function getAutoLoginUrl(): string {
        $config = MediaWiki\MediaWikiServices::getInstance()->getMainConfig();
        $configured = self::normalize( self::safeGet( $config, 'LinkedInAuthAutoLoginUrl', $GLOBALS['wgLinkedInAuthAutoLoginUrl'] ?? null ) );
        if ( $configured !== null ) {
            return $configured;
        }

        $title = SpecialPage::getTitleFor( 'LinkedInAuthAutoLogin' );
        return $title->getLocalURL();
    }

    /**
     * @return string
     */
    public static function getDefaultReturnTo(): string {
        $config = MediaWiki\MediaWikiServices::getInstance()->getMainConfig();
        $configured = self::normalize( self::safeGet( $config, 'LinkedInAuthDefaultReturnTo', $GLOBALS['wgLinkedInAuthDefaultReturnTo'] ?? null ) );
        if ( $configured !== null ) {
            return $configured;
        }
        return '/Main_Page';
    }

    /**
     * @return string
     */
    public static function getLogPath(): string {
        $config = MediaWiki\MediaWikiServices::getInstance()->getMainConfig();
        $configured = self::normalize( self::safeGet( $config, 'LinkedInAuthLogPath', $GLOBALS['wgLinkedInAuthLogPath'] ?? null ) );
        if ( $configured !== null ) {
            return $configured;
        }

        $ip = $GLOBALS['IP'] ?? null;
        if ( is_string( $ip ) && $ip !== '' ) {
            return rtrim( $ip, "/\\" ) . '/sso_login_debug.log';
        }

        $root = realpath( dirname( __DIR__, 3 ) );
        if ( $root === false ) {
            $root = dirname( __DIR__, 3 );
        }
        return rtrim( $root, "/\\" ) . '/sso_login_debug.log';
    }

    /**
     * @return string
     */
    private static function defaultRedirectUri(): string {
        $config = MediaWiki\MediaWikiServices::getInstance()->getMainConfig();
        $callbackUrl = self::normalize( self::safeGet( $config, 'LinkedInAuthCallbackUrl', $GLOBALS['wgLinkedInAuthCallbackUrl'] ?? null ) );
        if ( $callbackUrl !== null ) {
            return $callbackUrl;
        }

        $title = SpecialPage::getTitleFor( 'LinkedInAuthCallback' );
        $server = $GLOBALS['wgServer'] ?? '';
        $scriptPath = $GLOBALS['wgScriptPath'] ?? '';
        if ( is_string( $server ) && $server !== '' ) {
            $base = rtrim( $server, '/' ) . ( is_string( $scriptPath ) ? $scriptPath : '' );
            return $base . '/index.php?title=' . rawurlencode( $title->getPrefixedText() );
        }

        $host = isset( $_SERVER['HTTP_HOST'] ) ? (string)$_SERVER['HTTP_HOST'] : '';

        if ( $host !== '' ) {
            if ( strpos( $host, 'staging.masticationpedia.org' ) !== false ) {
                return 'https://staging.masticationpedia.org/index.php?title=Speciale:LinkedInAuthCallback';
            }
            if ( strpos( $host, 'dev.masticationpedia.org' ) !== false ) {
                return 'https://dev.masticationpedia.org/index.php?title=Speciale:LinkedInAuthCallback';
            }
            return 'https://' . $host . '/index.php?title=Speciale:LinkedInAuthCallback';
        }

        return 'https://dev.masticationpedia.org/index.php?title=Speciale:LinkedInAuthCallback';
    }

    /**
     * @param mixed $value
     * @return ?string
     */
    private static function normalize( $value ): ?string {
        if ( !is_string( $value ) ) {
            return null;
        }
        $trimmed = trim( $value );
        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * @param MediaWiki\Config\Config $config
     * @param string $key
     * @param mixed $fallback
     * @return mixed
     */
    private static function safeGet( $config, string $key, $fallback ) {
        try {
            return $config->get( $key );
        } catch ( MediaWiki\Config\ConfigException $e ) {
            return $fallback;
        }
    }
}
