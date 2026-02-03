<?php
// LinkedInAuth parser hooks.

declare(strict_types=1);

use MediaWiki\MediaWikiServices;

class LinkedInAuthHooks {
    /**
     * Register <linAuth> tag.
     *
     * @param Parser $parser
     * @return bool
     */
    public static function onParserFirstCallInit( Parser $parser ): bool {
        $parser->setHook( 'linAuth', [ self::class, 'renderLinAuth' ] );
        // Be defensive about case handling.
        $parser->setHook( 'linauth', [ self::class, 'renderLinAuth' ] );
        return true;
    }

    /**
     * Register DB schema.
     *
     * @param DatabaseUpdater $updater
     * @return bool
     */
    public static function onLoadExtensionSchemaUpdates( DatabaseUpdater $updater ): bool {
        $baseDir = __DIR__ . '/../sql/mysql';
        $updater->addExtensionTable(
            'linkedinauth_tokens',
            $baseDir . '/linkedinauth_tokens.sql'
        );
        return true;
    }

    /**
     * Render <linAuth>...</linAuth> content as button, link, or custom HTML.
     *
     * @param string|null $input
     * @param array $args
     * @param Parser $parser
     * @param PPFrame $frame
     * @return string
     */
    public static function renderLinAuth( $input, array $args, Parser $parser, PPFrame $frame ): string {
        $type = isset( $args['type'] ) ? strtolower( trim( (string)$args['type'] ) ) : 'button';
        $rawText = $input ?? '';
        $text = htmlspecialchars( $rawText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );

        if ( $type === 'custom' ) {
            // Allow nested wikitext expansion, return HTML string for the tag.
            return $parser->recursiveTagParse( $rawText, $frame );
        }

        $loginUrl = self::getLoginUrl();
        $hrefAttr = ' href="' . htmlspecialchars( $loginUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '"';

        if ( $type === 'link' ) {
            return '<a' . $hrefAttr . '>' . $text . '</a>';
        }

        // Default: button.
        return '<a class="mw-ui-button"' . $hrefAttr . '>' . $text . '</a>';
    }

    /**
     * Resolve the login URL from configuration or the default special page.
     *
     * @return string
     */
    private static function getLoginUrl(): string {
        $config = MediaWikiServices::getInstance()->getMainConfig();
        try {
            $configured = $config->get( 'LinkedInAuthLoginUrl' );
        } catch ( MediaWiki\Config\ConfigException $e ) {
            $configured = $GLOBALS['wgLinkedInAuthLoginUrl'] ?? '';
        }
        if ( is_string( $configured ) && $configured !== '' ) {
            $legacyHint = strpos( $configured, 'oauth/linkedin-login.php' ) !== false;
            $ip = $GLOBALS['IP'] ?? '';
            $legacyPath = is_string( $ip ) && $ip !== '' ? rtrim( $ip, "/\\" ) . '/oauth/linkedin-login.php' : '';
            if ( $legacyHint && ( $legacyPath === '' || !file_exists( $legacyPath ) ) ) {
                $configured = '';
            }
        }
        if ( is_string( $configured ) && $configured !== '' ) {
            return $configured;
        }

        $title = SpecialPage::getTitleFor( 'LinkedInAuthLogin' );
        return $title->getLocalURL();
    }
}
