<?php
/**
 *
 * @license GPL2+
 * @file
 *
 * @author Antoine Lamielle
 */
namespace Upload2Commons;

use MWException;
use MediaWiki\OAuthClient\Client;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Extensions\OAuthAuthentication\Config;
use MediaWiki\Extensions\OAuthAuthentication\OAuthExternalUser;

/**
 *
 * @package Upload2Commons
 */
class OAuthRequest extends Client {

    function __construct() {
        global $wgOAuthAuthenticationCallbackUrl;

        $config = Config::getDefaultConfig();
        parent::__construct( $config, LoggerFactory::getInstance( 'Upload2Commons' )  );
        if ( $wgOAuthAuthenticationCallbackUrl ) {
		    $this->setCallback( $wgOAuthAuthenticationCallbackUrl );
	    }
    }

    public function postWithToken( \User $user, $tokenType, array $apiParams, $hasFile = false ) {
        $tokens = $this->post( $user, array(
            'action' => 'query',
            'format' => 'json',
            'meta' => 'tokens',
            'type' => $tokenType
        ) );
        if ( !isset( $tokens->query->tokens ) ) {
            throw new \MWException('unknownerror-oauth');
        }
        $tokens = $tokens->query->tokens;

        foreach ( $tokens as $name=>$token ) {
            if ( $name === "{$tokenType}token" ) {
                $apiParams['token'] = $token;
                break;
            }
        }
        return $this->post( $user, $apiParams, $hasFile );
    }

    public function post( \User $user, array $apiParams, $hasFile = false ) {
        global $wgUpload2CommonsApiUrl;
		$accessToken = $this->getAccessToken( $user );

        if ( ! isset($apiParams['file']) ) {
            $this->setExtraParams( $apiParams );
        }
        else {
            $this->setExtraParams( array() );
        }

        return json_decode( $this->makeOAuthCall(
            $accessToken,
            $wgUpload2CommonsApiUrl,
            true,
            $apiParams,
            $hasFile
        ) );
	}
	protected function getAccessToken( \User $user ) {
        // Get OAuth datas stored in the DB
		if ( !isset( $user->extAuthObj ) ) {
			$user->extAuthObj = OAuthExternalUser::allFromUser( $user, wfGetDB( DB_MASTER ) )[0];
		}

	    return $user->extAuthObj->getAccessToken();
	}

}
