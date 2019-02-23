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
use Title;
use RepoGroup;
use WikiPage;
use ManualLogEntry;
use MWException;
use MediaWiki\Extensions\OAuthAuthentication\OAuthExternalUser;

/**
 * This class implements action=upload-to-commons API, sending a locally uploaded file to commons.
 * @package Upload2Commons
 */
class ApiUpload2Commons extends ApiBase {
	public function execute() {
		// Parameter handling
		$params = $this->extractRequestParams();
		$this->requireOnlyOneParameter($params, 'localfilename', 'filekey');
		$this->isFileStashed = $params['filekey'] ? true : false;
		if ( $params['filekey'] and !$params['filename'] ) {
		    $this->dieWithError( 'apierror-stashrequirefilename' );
		}
		if ( $params['removeafterupload'] and !$this->isFileStashed ) {
		    $this->dieWithError( [ 'apierror-removeonlystash', $params['localfilename'] ] );
		}

        // Check whether the user has the appropriate local permissions
        $this->user = $this->getUser();
        $this->checkPermissions();

        // Prepare the request
        try {
            $request = $this->forgeRequest( $params );
        }
        catch (\UploadStashException $e) {
            $this->dieWithError( [ 'apierror-stashedfilenotfound', $params['filekey'] ] );
        }

        // Check that the user has the right to upload this file
        $this->canUserUploadFile();

        // Send the request to the foreign wiki
        try {
            $requester = new OAuthRequest();
		    $result = $requester->postWithToken( $this->user, 'csrf', $request, true );
		} catch(MWException $e) {
		    $this->dieWithError( 'apierror-unknownerror-oauth' );
		}

        if ( $this->isUploadSuccessfull( $result ) ) {
            // Log the action
            $this->doLog( $result->upload->imageinfo, $params['logtags'] );

            // Remove the stashed file if the upload has succeeded and if asked by the user
		    if ( $params['removeafterupload'] ) {
		        $isRemoved = $this->stash->removeFile( $params['filekey'] );
                $this->getResult()->addValue( null, $this->getModuleName(),
                    [ 'removeafterupload' => json_encode( $isRemoved ) ]
                );
		    }
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

	protected function canUserUploadFile() {
	    if ( $this->file->getUser('id') == $this->user->getId() ) {
	        $this->checkUserRightsAny( 'remoteuploadown' );
	    }
	    else {
	        $this->checkUserRightsAny( 'remoteupload' );
	    }
	}

	protected function forgeRequest(array $params) {
	    $request = array(
	        'action' => 'upload',
	        'format' => 'json',
	    );

	    // Fetch the given (possibely stashed) file from it's name
        $localRepo = RepoGroup::singleton()->getLocalRepo();
        if( $this->isFileStashed ) {
            $this->stash = $localRepo->getUploadStash( $this->user );
            $this->file = $this->stash->getFile( $params['filekey'] );
        }
        else {
			$this->file = wfLocalFile( $params['localfilename'] );
        }

        // Check that the file exists
	    if ( !$this->file->exists() ) {
		    $this->dieWithError( [ 'apierror-invalidtitle', wfEscapeWikiText( $params['localfilename'] ) ] );
	    }
	    $title = $this->file->getTitle();

	    // By default, the file will have the same name on the remote wiki
	    // but let the user change it by using the remotefilename param
	    $request['filename'] = $title->getPrefixedText();
	    if ( $params['filename'] ) {
	        $request['filename'] = Title::makeTitle(NS_FILE, $params['filename']);
	    }

	    $request['file'] = new \CurlFile($this->file->getLocalRefPath());

	    // Fetch the rest of the params to be passed to the remote wiki API

	    if ( $params['text'] ) {
	        $request['text'] = $params['text'];
	    }
	    else if ( !$this->isFileStashed ) {
	        $request['text'] = WikiPage::factory( $title )->getContent()->getNativeData();
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

    protected function isUploadSuccessfull( $result ) {
        if( isset( $result->upload->result ) ) {
            if ( $result->upload->result === 'Success' ) {
                return true;
            }
        }
        return false;
    }

	protected function doLog( $imageInfo, $tags ) {
        if ( $this->isFileStashed ) {
            $logEntry = new ManualLogEntry( 'remoteupload', 'stashedfile' );
            $logEntry->setTarget( Title::makeTitle( NS_SPECIAL, 'UploadStash' ) );
        }
        else {
            $logEntry = new ManualLogEntry( 'remoteupload', 'file' );
            $logEntry->setTarget( $this->file->getTitle() );
        }
        $logEntry->setPerformer( $this->user );
        $logEntry->setParameters( array(
            '4::remoteurl' => $imageInfo->descriptionurl,
            '5::remotetitle' => $imageInfo->canonicaltitle,
        ) );
        if ( $tags ) {
            $logEntry->setTags( $tags );
        }

        $logid = $logEntry->insert();
        $logEntry->publish( $logid );
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
