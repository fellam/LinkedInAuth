<?php
// LinkedInAuth special page: post-login welcome/debug page.

declare(strict_types=1);

use MediaWiki\MediaWikiServices;

class LinkedInAuthSpecialWelcome extends SpecialPage {
    public function __construct() {
        parent::__construct( 'LinkedInAuthWelcome' );
    }

    /**
     * @return bool
     */
    public function isListed(): bool {
        return true;
    }

    /**
     * @param string|null $subPage
     * @return void
     */
    public function execute( $subPage ): void {
        $this->setHeaders();

        $user = $this->getUser();
        $out = $this->getOutput();

        $out->addWikiTextAsInterface( "== LinkedInAuth welcome ==" );
        if ( $user->isRegistered() ) {
            $out->addWikiTextAsInterface( "You are logged in. This page shows basic session details for debugging." );
        } else {
            $out->addWikiTextAsInterface( "You are NOT logged in. This page shows basic session details for debugging." );
        }

        $groupManager = MediaWikiServices::getInstance()->getUserGroupManager();
        $groups = $groupManager->getUserGroups( $user );

        $session = $this->getRequest()->getSession();
        $sessionId = $session ? $session->getId() : '';
        $sessionName = session_name();

        $rows = [
            'Logged in' => $user->isRegistered() ? 'yes' : 'no',
            'Username' => $user->getName(),
            'User ID' => (string)$user->getId(),
            'Groups' => $groups ? implode( ', ', $groups ) : '(none)',
            'Session name' => $sessionName !== '' ? $sessionName : '(empty)',
            'Session id' => $sessionId !== '' ? $sessionId : '(empty)',
            'Cookie domain' => (string)( $GLOBALS['wgCookieDomain'] ?? '' ),
            'Cookie path' => (string)( $GLOBALS['wgCookiePath'] ?? '' ),
            'Cookie SameSite' => (string)( $GLOBALS['wgCookieSameSite'] ?? '' ),
        ];

        $html = '<table class="wikitable"><tbody>';
        foreach ( $rows as $key => $value ) {
            $html .= '<tr><th>' . htmlspecialchars( $key ) . '</th><td>' . htmlspecialchars( $value ) . '</td></tr>';
        }
        $html .= '</tbody></table>';

        $out->addHTML( $html );

        if ( $user->isAllowed( 'linauth' ) ) {
            $statusTitle = SpecialPage::getTitleFor( 'LinkedInAuthStatus' );
            $usersTitle = SpecialPage::getTitleFor( 'LinkedInAuthUsers' );
            $out->addHTML(
                '<p>'
                . '<a class="mw-ui-button" href="' . htmlspecialchars( $statusTitle->getLocalURL() ) . '">Status</a> '
                . '<a class="mw-ui-button" href="' . htmlspecialchars( $usersTitle->getLocalURL() ) . '">Users</a>'
                . '</p>'
            );
        }
    }
}
