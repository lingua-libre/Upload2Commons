<?php
/**
 *
 * @license GPL2+
 * @file
 *
 * @author Antoine Lamielle
 */
namespace Upload2Commons;

use ApiBase;
use MWException;
use MediaWiki\Extensions\OAuthAuthentication\OAuthExternalUser;

/**
 * This class implements action=post-to-commons API, sending a post request to commons.
 * @package Post2Commons
 */
class ApiPost2Commons extends ApiBase {
	public function execute() {
		// Parameter handling
		$params = $this->extractRequestParams();
		$this->requireOnlyOneParameter($params, 'request');

        // Check whether the user has the appropriate local permissions
        $this->user = $this->getUser();// //
        $this->checkPermissions();

        // Prepare the request
        $request = json_decode($params['request'], true);
		if ( $request == null ) {
			$this->dieWithError( 'apierror-cantdecoderequest' );
		}

        // Send the request to the foreign wiki
        try {
            $requester = new OAuthRequest();
		    $result = $requester->postWithToken( $this->user, 'csrf', $request, false );
		} catch(MWException $e) {
		    $this->dieWithError( 'apierror-unknownerror-oauth' );
		}

	    $this->getResult()->addValue( null, $this->getModuleName(),
			[
			    'oauth' => $result
			]
		);
	}

    protected function checkPermissions() {
		if ( !$this->user->isLoggedIn() ) {
		    $this->dieWithError( [ 'apierror-mustbeloggedin', $this->msg( 'action-upload' ) ] );
		}
        if ( $this->user->isBlocked() ) {
            $this->dieBlocked( $this->user->getBlock() );
        }
        if ( $this->user->isBlockedGlobally() ) {
            $this->dieBlocked( $this->user->getGlobalBlock() );
        }
        $externalUsers = OAuthExternalUser::allFromUser( $this->user, wfGetDB( DB_MASTER ) );
        if ( count( $externalUsers ) != 1 ) {
            $this->dieWithError( 'apierror-nolinkedaccount' );
        }
        $this->user->extAuthObj = $externalUsers[0];
    }

	public function getAllowedParams() {
		return [
			'request' => [
				ApiBase::PARAM_TYPE => 'string',
			],
		];
	}
	public function needsToken() {
	    return 'csrf';
	}
	public function mustBePosted() {
		return true;
	}
	public function isWriteMode() {
	    return true;
	}
}
