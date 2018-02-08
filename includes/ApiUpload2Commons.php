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
use ManualLogEntry;
use MWException;
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

		// Parameter handling
		$params = $this->extractRequestParams();
		$this->requireOnlyOneParameter($params, 'localfilename', 'filekey');
		if ( $params['filekey'] and !$params['filename'] ) {
		    $this->dieWithError( 'apierror-stashrequirefilename' );
		}
		if ( $params['removeafterupload'] and $params['localfilename'] ) {
		    $this->dieWithError( [ 'apierror-removeonlystash', $params['localfilename'] ] );
		}

        // Prepare the request
        $request = $this->forgeRequest( $params );

        // Check that the user has the right tu upload this file
        $this->canUserUploadFile();

        // Send the request to the foreign wiki
        try {
            $requester = new OAuthRequest();
		    $result = $requester->postWithToken( $this->user, 'csrf', $request, true );
		} catch(MWException $e) {
		    $this->dieWithError( 'apierror-unknownerror-oauth' );
		}
		if ( $result == null ) {
		    $this->dieWithError( 'apierror-unknownerror-oauth' );
		}

        if ( $this->isUploadSuccessfull( $result ) ) {
            // Log the action
            if ( $params['localfilename'] ) {
                $logEntry = new ManualLogEntry( 'remoteupload', 'file' );
                $logEntry->setTarget( $this->file->getTitle() );
            }
            else {
                $logEntry = new ManualLogEntry( 'remoteupload', 'stashedfile' );
                $logEntry->setTarget( Title::makeTitle( NS_SPECIAL, 'UploadStash' ) );
            }
            $logEntry->setPerformer( $this->user );
            $logEntry->setParameters( array(
                '4::remoteurl' => $result->upload->imageinfo->descriptionurl,
                '5::remotetitle' => $result->upload->imageinfo->canonicaltitle,
            ) );
            if ( $params['logtags'] ) {
                $logEntry->setTags( $params['logtags'] );
            }

            $logid = $logEntry->insert();
            $logEntry->publish( $logid );

            // Remove the stash if the upload has succeeded and if asked by the user
		    if ( $params['removeafterupload'] ) {
		        $isRemoved = $this->stash->removeFile( $params['filekey'] );
                $this->getResult()->addValue( null, $this->getModuleName(),
                    [ 'removeafterupload' => json_encode( $isRemoved ) ]
                );
		    }
		}

	    $this->getResult()->addValue( null, $this->getModuleName(),
			[
			    'result' => $result
			]
		);
	    // Remove file from stash
	    // $this->stash->removeFile( $params['filekey'] );
	}

	private function canUserUploadFile() {
	    if ( $this->file->getUser('id') == $this->user->getId() ) {
	        $this->checkUserRightsAny( 'remoteuploadown' );
	    }
	    else {
	        $this->checkUserRightsAny( 'remoteupload' );
	    }
	}

    public function isUploadSuccessfull( $result ) {
        if( isset( $result->upload->result ) ) {
            if ( $result->upload->result === 'Success' ) {
                return true;
            }
        }
        return false;
    }

	public function forgeRequest($params) {
	    $request = array(
	        'action' => 'upload',
	        'format' => 'json',
	    );

	    // Fetch the given (possibely stashed) file from it's name
        $localRepo = RepoGroup::singleton()->getLocalRepo();
        if( $params['localfilename'] ) {
			$this->file = wfLocalFile( $params['localfilename'] );
        }
        else {
            $this->stash = $localRepo->getUploadStash( $this->user );
            $this->file= $this->stash->getFile( $params['filekey'] );
        }

        // Check that the file exists
	    if ( !$this->file->exists() ) {
		    $this->dieWithError( [ 'apierror-invalidtitle', wfEscapeWikiText( $params['localfilename'] ) ] );
	    }

	    // By default, the file will have the same name on the remote wiki
	    // but let the user change it by using the remotefilename param
	    $title = $this->file->getTitle();
	    $request['filename'] = $title->getPrefixedText();
	    if ( $params['filename'] ) {
	        $request['filename'] = Title::makeTitle(NS_FILE, $params['filename']);
	    }

	    $request['file'] = new \CurlFile($this->file->getLocalRefPath());

	    // Fetch the rest of the params to be passed to the remote wiki API

	    if ( $params['text'] ) {
	        $request['text'] = $params['text'];
	    }
	    else if ( $params['localfilename'] ) {
	        $request['text'] = WikiPage::factory( $title )->getContent()->mText;
	    }

	    if ( $params['comment'] ) {
            $request['comment'] = $params['comment'];
	    }

	    if ( $params['tags'] ) {
            $request['tags'] = implode( '|', $params['tags'] );
        }

	    if ( $params['ignorewarnings'] ) {
            $request['ignorewarnings'] = $params['ignorewarnings'];
        }

        return $request;
	}

	public function getAllowedParams() {
		return [
			'localfilename' => [
				ApiBase::PARAM_TYPE => 'string',
			],
			'filekey' => [
				ApiBase::PARAM_TYPE => 'string',
			],
			'filename' => [
				ApiBase::PARAM_TYPE => 'string',
			],
			'comment' => [
				ApiBase::PARAM_TYPE => 'string',
			],
			'tags' => [
				ApiBase::PARAM_TYPE => 'string',
				ApiBase::PARAM_ISMULTI => true,
			],
			'text' => [
				ApiBase::PARAM_TYPE => 'string',
			],
			'ignorewarnings' => [
			    ApiBase::PARAM_TYPE => 'boolean',
			],
			'removeafterupload' => [
				ApiBase::PARAM_TYPE => 'boolean',
			],
			'logtags' => [
				ApiBase::PARAM_TYPE => 'tags',
				ApiBase::PARAM_ISMULTI => true,
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
