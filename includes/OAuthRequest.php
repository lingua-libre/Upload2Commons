<?php
/**
 *
 * @license GPL2+
 * @file
 *
 * @author Antoine Lamielle
 */
namespace Upload2Commons;

use FormatJson;
use stdClass;
use User;
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

    public function postWithToken( $user, $tokenType, $apiParams, $hasFile = false ) {
        $tokens = $this->post( $user, array(
            'action' => 'query',
            'format' => 'json',
            'meta' => 'tokens',
            'type' => $tokenType
        ) )->query->tokens;
        foreach ( $tokens as $name=>$token ) {
            if ( $name === "{$tokenType}token" ) {
                $apiParams['token'] = $token;
                break;
            }
        }
        // TODO: raise an error if we failed to get the token
        return $this->post( $user, $apiParams, $hasFile );
    }

    public function post( $user, $apiParams, $hasFile = false ) {
		$accessToken = $this->getAccessToken( $user );

        if ( ! isset($apiParams['file']) ) {
            $this->setExtraParams( $apiParams );
        }
        else {
            $this->setExtraParams( array() );
        }

        return json_decode( $this->makeOAuthCall(
            $accessToken,
            'https://oauth.0x010c.fr/api.php',
            true,
            $apiParams,
            $hasFile
        ) );
	}
	protected function getAccessToken( $user ) {
        // Get OAuth datas stored in the DB
        // TODO: Manage not OAuth users ('you have to connect your account to a remote account')
		if ( !isset( $user->extAuthObj ) ) {
			$user->extAuthObj = OAuthExternalUser::allFromUser( $user, wfGetDB( DB_MASTER ) )[0];
		}

	    return $user->extAuthObj->getAccessToken();
	}

}
