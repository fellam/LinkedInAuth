<?php
// LinkedInAuth debug helper.

declare(strict_types=1);

class LinkedInAuthDebug {
    /**
     * @return bool
     */
    public static function enabled(): bool {
        $config = MediaWiki\MediaWikiServices::getInstance()->getMainConfig();
        try {
            $value = $config->get( 'LinkedInAuthDebug' );
        } catch ( MediaWiki\Config\ConfigException $e ) {
            $value = $GLOBALS['wgLinkedInAuthDebug'] ?? false;
        }
        return (bool)$value;
    }

    /**
     * @param string $step
     * @param array $ctx
     * @return void
     */
    public static function log( string $step, array $ctx = [] ): void {
        if ( !self::enabled() ) {
            return;
        }

        $line = '[' . date( 'c' ) . '] ' . $step;
        if ( $ctx ) {
            $line .= ' | ' . json_encode( $ctx, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        }
        $line .= "\n";
        $path = LinkedInAuthConfig::getLogPath();
        @file_put_contents( $path, $line, FILE_APPEND );
    }
}
