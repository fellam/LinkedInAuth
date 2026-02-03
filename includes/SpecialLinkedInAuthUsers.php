<?php
// LinkedInAuth special page: list LinkedIn-created users and status.

declare(strict_types=1);

use MediaWiki\MediaWikiServices;
use MediaWiki\User\User;
use Wikimedia\Rdbms\IDatabase;

class LinkedInAuthSpecialUsers extends SpecialPage {
    public function __construct() {
        parent::__construct( 'LinkedInAuthUsers', 'linauth' );
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
        $out->addWikiTextAsInterface( "== LinkedInAuth users ==" );
        $this->handleDeleteAction();

        $dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );
        $rows = [];

        $tokenRes = $dbr->select(
            'linkedinauth_tokens',
            [
                'lat_sub',
                'lat_user_id',
                'lat_username',
                'lat_updated_at',
            ],
            [],
            __METHOD__
        );

        foreach ( $tokenRes as $row ) {
            $sub = (string)$row->lat_sub;
            if ( !isset( $rows[$sub] ) ) {
                $rows[$sub] = [
                    'sub' => $sub,
                    'email' => '',
                    'name' => '',
                ];
            }
            $rows[$sub]['user_id'] = (string)( $row->lat_user_id ?? '' );
            $rows[$sub]['username'] = (string)( $row->lat_username ?? '' );
            $rows[$sub]['token_updated_at'] = $row->lat_updated_at ? (string)$row->lat_updated_at : '';
            if ( $rows[$sub]['name'] === '' && $rows[$sub]['username'] !== '' ) {
                $rows[$sub]['name'] = $rows[$sub]['username'];
            }
        }

        $userIds = [];
        foreach ( $rows as $row ) {
            if ( !empty( $row['user_id'] ) ) {
                $userIds[] = (int)$row['user_id'];
            }
        }
        $userIds = array_values( array_unique( array_filter( $userIds ) ) );
        if ( $userIds ) {
            $userRes = $dbr->select(
                'user',
                [ 'user_id', 'user_name', 'user_real_name', 'user_email' ],
                [ 'user_id' => $userIds ],
                __METHOD__
            );
            $userInfo = [];
            foreach ( $userRes as $urow ) {
                $userInfo[(int)$urow->user_id] = [
                    'name' => (string)$urow->user_real_name,
                    'email' => (string)$urow->user_email,
                    'username' => (string)$urow->user_name,
                ];
            }
            foreach ( $rows as $sub => $row ) {
                $uid = isset( $row['user_id'] ) ? (int)$row['user_id'] : 0;
                if ( $uid && isset( $userInfo[$uid] ) ) {
                    if ( $rows[$sub]['email'] === '' ) {
                        $rows[$sub]['email'] = $userInfo[$uid]['email'];
                    }
                    if ( $rows[$sub]['name'] === '' ) {
                        $rows[$sub]['name'] = $userInfo[$uid]['name'] !== '' ? $userInfo[$uid]['name'] : $userInfo[$uid]['username'];
                    }
                    if ( $rows[$sub]['username'] === '' ) {
                        $rows[$sub]['username'] = $userInfo[$uid]['username'];
                    }
                }
            }
        }

        $html = '<table class="wikitable"><thead><tr>'
            . '<th>LinkedIn sub</th>'
            . '<th>Email</th>'
            . '<th>Name</th>'
            . '<th>User ID</th>'
            . '<th>Username</th>'
            . '<th>Token updated</th>'
            . '<th>Actions</th>'
            . '</tr></thead><tbody>';

        $deleteToken = $this->getUser()->getEditToken( 'linkedinauth-delete' );
        foreach ( $rows as $row ) {
            $sub = $row['sub'] ?? '';
            $userId = $row['user_id'] ?? '';
            $actionHtml = '';
            if ( $sub !== '' ) {
                $actionHtml = '<form method="post" style="margin:0" onsubmit="return confirm(\'Delete LinkedInAuth data for this user?\');">'
                    . '<input type="hidden" name="action" value="delete">'
                    . '<input type="hidden" name="sub" value="' . htmlspecialchars( $sub ) . '">'
                    . '<input type="hidden" name="user_id" value="' . htmlspecialchars( (string)$userId ) . '">'
                    . '<input type="hidden" name="token" value="' . htmlspecialchars( $deleteToken ) . '">'
                    . '<button class="mw-ui-button mw-ui-destructive" type="submit">Delete</button>'
                    . '</form>';
            }
            $html .= '<tr>'
                . '<td>' . htmlspecialchars( $row['sub'] ?? '' ) . '</td>'
                . '<td>' . htmlspecialchars( $row['email'] ?? '' ) . '</td>'
                . '<td>' . htmlspecialchars( $row['name'] ?? '' ) . '</td>'
                . '<td>' . htmlspecialchars( $row['user_id'] ?? '' ) . '</td>'
                . '<td>' . htmlspecialchars( $row['username'] ?? '' ) . '</td>'
                . '<td>' . htmlspecialchars( $row['token_updated_at'] ?? '' ) . '</td>'
                . '<td>' . $actionHtml . '</td>'
                . '</tr>';
        }

        $html .= '</tbody></table>';
        $out->addHTML( $html );
    }

    /**
     * @return void
     */
    private function handleDeleteAction(): void {
        $request = $this->getRequest();
        if ( !$request->wasPosted() || $request->getVal( 'action' ) !== 'delete' ) {
            return;
        }

        $token = $request->getVal( 'token', '' );
        if ( !$this->getUser()->matchEditToken( $token, 'linkedinauth-delete' ) ) {
            $this->getOutput()->addHTML( '<div class="error">Invalid token.</div>' );
            return;
        }

        $sub = $request->getVal( 'sub', '' );
        $userId = (int)$request->getVal( 'user_id', 0 );
        if ( !is_string( $sub ) || $sub === '' ) {
            $this->getOutput()->addHTML( '<div class="error">Missing LinkedIn sub.</div>' );
            return;
        }

        $dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );
        $tokenRow = $dbw->selectRow(
            'linkedinauth_tokens',
            [ 'lat_user_id' ],
            [ 'lat_sub' => $sub ],
            __METHOD__
        );
        if ( $tokenRow && $tokenRow->lat_user_id && $userId > 0 && (int)$tokenRow->lat_user_id !== $userId ) {
            $this->getOutput()->addHTML( '<div class="error">User mismatch for this LinkedIn record.</div>' );
            return;
        }

        if ( $userId > 0 ) {
            $userFactory = MediaWikiServices::getInstance()->getUserFactory();
            $user = $userFactory->newFromId( $userId );
            if ( $user ) {
                $user->loadFromDatabase();
                if ( !$this->canHardDeleteUser( $user, $dbw ) ) {
                    $this->getOutput()->addHTML( '<div class="error">User has activity; hard delete aborted.</div>' );
                    return;
                }
            }
        }

        $dbw->startAtomic( __METHOD__ );
        $dbw->delete( 'linkedinauth_tokens', [ 'lat_sub' => $sub ], __METHOD__ );
        if ( $userId > 0 ) {
            $this->hardDeleteUser( $userId, $dbw );
        }
        $dbw->endAtomic( __METHOD__ );

        $this->getOutput()->addHTML( '<div class="success">LinkedInAuth data and user removed.</div>' );
    }

    /**
     * @param User $user
     * @param IDatabase $dbw
     * @return bool
     */
    private function canHardDeleteUser( User $user, IDatabase $dbw ): bool {
        if ( $user->getEditCount() > 0 ) {
            return false;
        }

        $actorStore = MediaWikiServices::getInstance()->getActorStore();
        $actorId = $actorStore->findActorId( $user, $dbw );
        if ( $actorId ) {
            if ( $dbw->tableExists( 'revision_actor' ) ) {
                $revCount = (int)$dbw->newSelectQueryBuilder()
                    ->select( '1' )
                    ->from( 'revision_actor' )
                    ->where( [ 'revactor_actor' => $actorId ] )
                    ->caller( __METHOD__ )
                    ->fetchRowCount();
                if ( $revCount > 0 ) {
                    return false;
                }
            }
            if ( $dbw->tableExists( 'logging' ) && $dbw->fieldExists( 'logging', 'log_actor', __METHOD__ ) ) {
                $logCount = (int)$dbw->newSelectQueryBuilder()
                    ->select( '1' )
                    ->from( 'logging' )
                    ->where( [ 'log_actor' => $actorId ] )
                    ->caller( __METHOD__ )
                    ->fetchRowCount();
                if ( $logCount > 0 ) {
                    return false;
                }
            }
            if ( $dbw->tableExists( 'recentchanges' ) && $dbw->fieldExists( 'recentchanges', 'rc_actor', __METHOD__ ) ) {
                $rcCount = (int)$dbw->newSelectQueryBuilder()
                    ->select( '1' )
                    ->from( 'recentchanges' )
                    ->where( [ 'rc_actor' => $actorId ] )
                    ->caller( __METHOD__ )
                    ->fetchRowCount();
                if ( $rcCount > 0 ) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param int $userId
     * @param IDatabase $dbw
     * @return void
     */
    private function hardDeleteUser( int $userId, IDatabase $dbw ): void {
        $tables = [
            'user_groups' => 'ug_user',
            'user_properties' => 'up_user',
            'user_options' => 'uoi_user_id',
            'user_former_groups' => 'ufg_user',
            'user_newtalk' => 'user_id',
            'watchlist' => 'wl_user',
        ];

        foreach ( $tables as $table => $field ) {
            if ( $dbw->tableExists( $table ) ) {
                $dbw->delete( $table, [ $field => $userId ], __METHOD__ );
            }
        }

        if ( $dbw->tableExists( 'actor' ) && $dbw->fieldExists( 'actor', 'actor_user', __METHOD__ ) ) {
            $dbw->delete( 'actor', [ 'actor_user' => $userId ], __METHOD__ );
        }

        $dbw->delete( 'user', [ 'user_id' => $userId ], __METHOD__ );
    }
}
