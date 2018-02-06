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
use FormatJson;
use Parser;
use ParserOptions;
use stdClass;
use Title;
use RepoGroup;
use MediaWiki\OAuthClient\ClientConfig;
use MediaWiki\OAuthClient\Consumer;
use MediaWiki\OAuthClient\Client;

/**
 * This class implements action=upload-to-commons API, sending a locally uploaded file to commons.
 * @package Upload2Commons
 */
class ApiUpload2Commons extends ApiBase {
	public function execute() {
	    // Check whether the user has the appropriate local permissions
		$user = $this->getUser();
		if ( !$user->isLoggedIn() ) {
		    $this->dieWithError( [ 'apierror-mustbeloggedin', $this->msg( 'action-upload' ) ] );
		}
		$this->checkUserRightsAny( 'remoteupload' );
        if ( $user->isBlocked() ) {
            $this->dieBlocked( $user->getBlock() );
        }
        if ( $user->isBlockedGlobally() ) {
            $this->dieBlocked( $user->getGlobalBlock() );
        }

		// Parameter handling
		$params = $this->extractRequestParams();
		$this->requireOnlyOneParameter($params, 'filename', 'filekey');

        // Fetch the given (possibely stashed) file from it's name
        if( $params['filename'] ) {
			$this->file = wfLocalFile( $params['filename'] );
        }
        else {
            $this->stash = RepoGroup::singleton()->getLocalRepo()->getUploadStash( $user );
            $this->file = $this->stash->getFile( $params['filekey'] );
        }

        // Check that the file exists
	    if ( !$this->file->exists() ) {
		    $this->dieWithError( [ 'apierror-invalidtitle', wfEscapeWikiText( $params['filename'] ) ] );
	    }

	    $this->getResult()->addValue( null, $this->getModuleName(),
				[ 'filename' => $this->file->getPath() ]
			);
	}
	public function getAllowedParams() {
		return [
			'filename' => [
				ApiBase::PARAM_TYPE => 'string',
			],
			'filekey' => [
				ApiBase::PARAM_TYPE => 'string',
			],
			'rfilename' => [
				ApiBase::PARAM_TYPE => 'string',
			],
			'text' => [
				ApiBase::PARAM_TYPE => 'string',
			],
			'comment' => [
				ApiBase::PARAM_TYPE => 'string',
			],
			'tags' => [
				ApiBase::PARAM_TYPE => 'tags',
				ApiBase::PARAM_ISMULTI => true,
			],
			'ignorewarnings' => [
			    ApiBase::PARAM_TYPE => 'boolean',
			],
			'removeafterupload' => [
				ApiBase::PARAM_TYPE => 'boolean',
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
