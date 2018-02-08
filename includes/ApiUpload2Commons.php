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
use FSFile;
use WikiPage;
use ApiQueryStashImageInfo;
use MediaWiki\OAuthClient\ClientConfig;
use MediaWiki\OAuthClient\Consumer;
use MediaWiki\OAuthClient\Client;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Extensions\OAuthAuthentication\Config;
use MediaWiki\Extensions\OAuthAuthentication\OAuthExternalUser;
use MediaWiki\Auth\AuthManager;
use Upload2Commons\OAuthRequest;

/**
 * This class implements action=upload-to-commons API, sending a locally uploaded file to commons.
 * @package Upload2Commons
 */
class ApiUpload2Commons extends ApiBase {
	public function execute() {
	    global $wgUploadDirectory;
	    // Check whether the user has the appropriate local permissions
		$this->user = $this->getUser();
		if ( !$this->user->isLoggedIn() ) {
		    $this->dieWithError( [ 'apierror-mustbeloggedin', $this->msg( 'action-upload' ) ] );
		}
		$this->checkUserRightsAny( 'remoteupload' );
        if ( $this->user->isBlocked() ) {
            $this->dieBlocked( $this->user->getBlock() );
        }
        if ( $this->user->isBlockedGlobally() ) {
            $this->dieBlocked( $this->user->getGlobalBlock() );
        }

		// Parameter handling
		$params = $this->extractRequestParams();
		$this->requireOnlyOneParameter($params, 'filename', 'filekey');

        // Prepare the request
        $request = $this->forgeRequest( $params );

        // Send the request to the foreign wiki
        $requester = new OAuthRequest();
		$result = $requester->postWithToken( $this->user, 'csrf', $request, true );
	    $this->getResult()->addValue( null, $this->getModuleName(),
			[
			    'result' => $result,
			]
		);
	    // Remove file from stash
	    // $this->stash->removeFile( $params['filekey'] );
	}

	public function forgeRequest($params) {
	    $request = array(
	        'action' => 'upload',
	        'format' => 'json',
	    );

	    // Fetch the given (possibely stashed) file from it's name
        $localRepo = RepoGroup::singleton()->getLocalRepo();
        if( $params['filename'] ) {
			$file = wfLocalFile( $params['filename'] );
        }
        else {
            $this->stash = $localRepo->getUploadStash( $this->user );
            $file = $this->stash->getFile( $params['filekey'] );
        }

        // Check that the file exists
	    if ( !$file->exists() ) {
		    $this->dieWithError( [ 'apierror-invalidtitle', wfEscapeWikiText( $params['filename'] ) ] );
	    }

	    // By default, the file will have the same name on the remote wiki
	    // but let the user change it by using the 'remotefilename param
	    $title = $file->getTitle();
	    $request['filename'] = $title->getPrefixedText();
	    if ( $params['remotefilename'] ) {
	        $request['filename'] = Title::makeTitle(NS_FILE, $params['remotefilename']);
	    }

	    $request['file'] = new \CurlFile($file->getLocalRefPath());

	    // Fetch the rest of the params to be passed to the remote wiki API

	    if ( $params['text'] ) {
	        $request['text'] = $params['text'];
	    }
	    else if ( $params['filename'] ) {
	        $request['text'] = WikiPage::factory( $title )->getContent()->mText;
	    }

	    if ( $params['comment'] ) {
            $request['comment'] = $params['comment'];
	    }

	    if ( $params['tags'] ) {
            $request['tags'] = $params['tags'];
        }

	    if ( $params['ignorewarnings'] ) {
            $request['ignorewarnings'] = $params['ignorewarnings'];
        }

        return $request;
	}

	public function getAllowedParams() {
		return [
			'filename' => [
				ApiBase::PARAM_TYPE => 'string',
			],
			'filekey' => [
				ApiBase::PARAM_TYPE => 'string',
			],
			'remotefilename' => [
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
