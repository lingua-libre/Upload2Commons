<?php

namespace Upload2Commons;

use LogFormatter;
use Message;
use Linker;

/**
 * This class formats remoteupload log entries.
 * @package Upload2Commons
 */
class RemoteUploadLogFormatter extends LogFormatter {
    public function getMessageParameters() {
        $params = parent::getMessageParameters();
        $params[5] = Message::rawParam( Linker::makeExternalLink( $params[3], $params[4] ) );
        return $params;
    }
}

