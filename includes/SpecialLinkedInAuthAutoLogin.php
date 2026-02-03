<?php
// LinkedInAuth special page: MediaWiki auto-login.

declare(strict_types=1);

use MediaWiki\Context\RequestContext;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserNameUtils;

class LinkedInAuthSpecialAutoLogin extends SpecialPage {
    public function __construct() {
        parent::__construct( 'LinkedInAuthAutoLogin' );
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
        $debugParam = $this->getRequest()->getBool( 'debug' );
        $debugEnabled = LinkedInAuthDebug::enabled();
        $debugDump = ( $this->getUser()->isAllowed( 'linauth' ) || $debugEnabled ) && $debugParam;

        if ( $debugEnabled ) {
            LinkedInAuthDebug::log( 'AUTOLOGIN_ENTRY', [
                'uri' => $_SERVER['REQUEST_URI'] ?? '',
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ] );
        }

        $request = $this->getRequest();
        $token = $request->getVal( 'token', '' );
        if ( !$token || strpos( $token, '.' ) === false ) {
            http_response_code( 400 );
            echo 'Bad token';
            return;
        }

        $hmacKey = $this->getHmacKey();
        if ( $hmacKey === '' ) {
            http_response_code( 500 );
            echo 'Keys missing';
            return;
        }

        [ $payloadB64, $sig ] = explode( '.', $token, 2 );
        $calcSig = hash_hmac( 'sha256', $payloadB64, $hmacKey );
        if ( !hash_equals( $calcSig, $sig ) ) {
            http_response_code( 403 );
            echo 'Invalid signature';
            return;
        }

        $payloadJson = base64_decode( strtr( $payloadB64, '-_', '+/' ) );
        $data = json_decode( $payloadJson, true );

        if ( !is_array( $data ) ) {
            http_response_code( 400 );
            LinkedInAuthDebug::log( 'AUTOLOGIN_BAD_PAYLOAD' );
            echo 'Bad payload';
            return;
        }

        if ( time() > ( $data['exp'] ?? 0 ) ) {
            http_response_code( 403 );
            LinkedInAuthDebug::log( 'AUTOLOGIN_TOKEN_EXPIRED' );
            echo 'Token expired';
            return;
        }

        $sub = $data['sub'] ?? '';
        $name = $data['name'] ?? '';
        $email = $data['email'] ?? '';
        $picture = $data['picture'] ?? '';
        $firstName = $data['first_name'] ?? '';
        $lastName = $data['last_name'] ?? '';
        $returnTo = $data['returnTo'] ?? '/Main_Page';

        if ( !$sub || !$email ) {
            http_response_code( 400 );
            LinkedInAuthDebug::log( 'AUTOLOGIN_MISSING_USERDATA' );
            echo 'Missing user data';
            return;
        }

        $returnTo = $this->normalizeReturnTo( $returnTo );
        LinkedInAuthDebug::log( 'AUTOLOGIN_START', [
            'returnTo' => $returnTo,
            'sub' => $sub,
            'email' => $email,
            'debug' => $debugDump ? 'yes' : 'no',
        ] );
        if ( $debugDump ) {
            $this->emitDebugDump( [
                'returnTo' => $returnTo,
                'sub' => $sub,
                'email' => $email,
                'name' => $name,
                'first_name' => $firstName,
                'last_name' => $lastName,
            ] );
            return;
        }

        $userFactory = MediaWikiServices::getInstance()->getUserFactory();
        $userNameUtils = MediaWikiServices::getInstance()->getUserNameUtils();

        $user = $this->loadUserBySub( $sub, $userFactory );

        if ( !$user || $user->getId() === 0 ) {
            [ $first, $last ] = $this->splitName( $name, $firstName, $lastName );
            $baseName = trim( $first . ' ' . $last );
            if ( $baseName === '' ) {
                $baseName = 'LinkedIn User';
            }
            $baseName .= ' LIN';

            $username = $this->findAvailableUsername( $baseName, $userFactory, $userNameUtils );
            if ( $username === '' ) {
                http_response_code( 500 );
                echo 'Username not creatable';
                return;
            }

            $user = $userFactory->newFromName( $username, 'creatable' );
            if ( !$user ) {
                http_response_code( 500 );
                echo 'Username not creatable';
                return;
            }

            $user->setEmail( $email );
            $user->setRealName( $name !== '' ? $name : $username );

            $status = $user->addToDatabase();
            if ( !$status->isOK() ) {
                http_response_code( 500 );
                echo 'DB create failed';
                return;
            }

            $user->confirmEmail();
            $user->saveSettings();
            $user->loadFromDatabase();
        } else {
            $updated = false;
            if ( $email !== '' && $user->getEmail() !== $email ) {
                $user->setEmail( $email );
                $user->confirmEmail();
                $updated = true;
            }
            if ( $name !== '' && $user->getRealName() === '' ) {
                $user->setRealName( $name );
                $updated = true;
            }
            if ( $updated ) {
                $user->saveSettings();
                $user->loadFromDatabase();
            }
        }

        LinkedInAuthDebug::log( 'AUTOLOGIN_USER_OK', [
            'userId' => $user->getId(),
            'username' => $user->getName(),
        ] );

        $groupManager = MediaWikiServices::getInstance()->getUserGroupManager();
        $groups = $groupManager->getUserGroups( $user );
        if ( !in_array( 'approved', $groups, true ) ) {
            $groupManager->addUserToGroup( $user, 'approved' );
        }

        $this->updateTokenStore( $sub, $user );

        $context = RequestContext::getMain();
        $context->setUser( $user );

        $session = $request->getSession();
        $session->setUser( $user, true );
        $session->persist();
        if ( method_exists( $session, 'save' ) ) {
            $session->save();
        }
        if ( class_exists( '\\MediaWiki\\Session\\SessionManager' ) ) {
            $smClass = '\\MediaWiki\\Session\\SessionManager';
            if ( method_exists( $smClass, 'getGlobalSession' ) ) {
                $globalSession = $smClass::getGlobalSession();
                if ( $globalSession && $globalSession !== $session ) {
                    $globalSession->setUser( $user, true );
                    $globalSession->persist();
                    if ( method_exists( $globalSession, 'save' ) ) {
                        $globalSession->save();
                    }
                }
            }
        }
        LinkedInAuthDebug::log( 'AUTOLOGIN_SESSION', [ 'id' => $session->getId() ] );

        $user->setToken();
        $user->saveSettings();
        $user->loadFromDatabase();
        $user->setCookies( $request, $context->getOutput() );
        // Ensure the session cookie is set explicitly to avoid losing it on redirects.
        $cookieDomain = $GLOBALS['wgCookieDomain'] ?? '';
        $cookiePath = $GLOBALS['wgCookiePath'] ?? '/';
        $cookieSameSite = $GLOBALS['wgCookieSameSite'] ?? 'None';
        $cookieParams = [
            'expires' => time() + 3600,
            'path' => $cookiePath,
            'domain' => is_string( $cookieDomain ) ? $cookieDomain : '',
            'secure' => true,
            'httponly' => true,
            'samesite' => is_string( $cookieSameSite ) && $cookieSameSite !== '' ? $cookieSameSite : 'None',
        ];
        setcookie( session_name(), session_id(), $cookieParams );
        if ( $debugEnabled ) {
            LinkedInAuthDebug::log( 'AUTOLOGIN_SESSION_INFO', [
                'session_name' => session_name(),
                'session_id' => session_id(),
                'save_handler' => ini_get( 'session.save_handler' ),
                'save_path' => ini_get( 'session.save_path' ),
            ] );
            LinkedInAuthDebug::log( 'AUTOLOGIN_HEADERS', [
                'headers' => headers_list(),
            ] );
        }

        $out = $context->getOutput();
        // Use a 200 response with client-side redirect to avoid losing Set-Cookie on some proxies.
        $safeUrl = htmlspecialchars( $returnTo, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
        $out->addHTML(
            '<!doctype html><html><head><meta charset="utf-8">'
            . '<meta http-equiv="refresh" content="0;url=' . $safeUrl . '">'
            . '</head><body>'
            . '<script>window.location.href="' . $safeUrl . '";</script>'
            . '<noscript><a href="' . $safeUrl . '">Continue</a></noscript>'
            . '</body></html>'
        );
        $out->output();
    }

    /**
     * @param string $returnTo
     * @return string
     */
    private function normalizeReturnTo( string $returnTo ): string {
        if (
            $returnTo === '' ||
            preg_match( '~^https?://~i', $returnTo ) ||
            strpos( $returnTo, '//' ) === 0
        ) {
            return '/Main_Page';
        }
        if ( strpos( $returnTo, '/wiki/' ) === 0 ) {
            $returnTo = substr( $returnTo, 5 );
        }
        if ( $returnTo !== '' && $returnTo[0] !== '/' ) {
            $returnTo = '/' . $returnTo;
        }
        return $returnTo;
    }

    /**
     * @param string $sub
     * @param User $user
     * @return void
     */
    private function updateTokenStore( string $sub, User $user ): void {
        $dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );
        $now = $dbw->timestamp();

        $dbw->upsert(
            'linkedinauth_tokens',
            [
                'lat_sub' => $sub,
                'lat_user_id' => $user->getId(),
                'lat_username' => $user->getName(),
                'lat_updated_at' => $now,
                'lat_expires_at' => 0,
                'lat_access_token' => '',
            ],
            [ 'lat_sub' ],
            [
                'lat_user_id' => $user->getId(),
                'lat_username' => $user->getName(),
                'lat_updated_at' => $now,
            ],
            __METHOD__
        );
    }

    /**
     * @param string $sub
     * @param UserFactory $userFactory
     * @return ?User
     */
    private function loadUserBySub( string $sub, UserFactory $userFactory ): ?User {
        $dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );
        $row = $dbw->selectRow(
            'linkedinauth_tokens',
            [ 'lat_user_id' ],
            [ 'lat_sub' => $sub ],
            __METHOD__
        );
        if ( !$row || !$row->lat_user_id ) {
            return null;
        }
        $user = $userFactory->newFromId( (int)$row->lat_user_id );
        if ( $user ) {
            $user->loadFromDatabase();
        }
        return $user;
    }

    /**
     * @param string $fullName
     * @param string $firstName
     * @param string $lastName
     * @return array{0:string,1:string}
     */
    private function splitName( string $fullName, string $firstName, string $lastName ): array {
        $first = trim( $firstName );
        $last = trim( $lastName );
        if ( $first !== '' || $last !== '' ) {
            return [ $first, $last ];
        }
        $parts = preg_split( '/\s+/', trim( $fullName ) );
        if ( !$parts || count( $parts ) === 0 ) {
            return [ '', '' ];
        }
        $first = array_shift( $parts );
        $last = count( $parts ) ? array_pop( $parts ) : '';
        return [ $first, $last ];
    }

    /**
     * @param string $baseName
     * @param UserFactory $userFactory
     * @param UserNameUtils $userNameUtils
     * @return string
     */
    private function findAvailableUsername( string $baseName, UserFactory $userFactory, UserNameUtils $userNameUtils ): string {
        $maxAttempts = 50;
        for ( $i = 0; $i <= $maxAttempts; $i++ ) {
            $candidate = $i === 0 ? $baseName : $baseName . ' ' . $i;
            if ( !$userNameUtils->isUsable( $candidate ) ) {
                continue;
            }
            $existing = $userFactory->newFromName( $candidate );
            if ( $existing ) {
                $existing->loadFromDatabase();
                if ( $existing->getId() !== 0 ) {
                    continue;
                }
            }
            $creatable = $userFactory->newFromName( $candidate, 'creatable' );
            if ( $creatable ) {
                return $candidate;
            }
        }
        return '';
    }

    /**
     * @param array $data
     * @return void
     */
    private function emitDebugDump( array $data ): void {
        header( 'Content-Type: text/plain; charset=utf-8' );
        echo "LinkedInAuth auto-login debug\n";
        foreach ( $data as $key => $value ) {
            echo $key . '=' . (string)$value . "\n";
        }
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
}
