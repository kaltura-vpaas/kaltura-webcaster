<?php

// IMPORTANT NOTE: in a production application, the partner ID and Kaltura Admin Secret should never
// be exposed on the client side of an application. It is done here for simplicity only.

// https://kmc.kaltura.com/index.php/kmcng/settings/integrationSettings
define('PARTNER_ID', <INSERT_PROPER_VALUE>);

// https://kmc.kaltura.com/index.php/kmcng/settings/integrationSettings
define('API_ADMIN_SECRET', '<INSERT_PROPER_VALUE>');

// Make sure this is set to real user IDs so that Analytics and Entitlements will really be tracked according to business needs
define('UNIQUE_USER_ID', '<INSERT_PROPER_VALUE>');

// Get these values from Kwebcast module in KMS config (Kaltura to provide these values)
define('WIN_UI_CONF_ID', <INSERT_PROPER_VALUE>); // winUiconfId
define('MAC_UI_CONF_ID', <INSERT_PROPER_VALUE>); // macUiconfId
define('PLAYER_UI_CONF', <INSERT_PROPER_VALUE>); // playerUIConf

// Metadata profile settings
define('METADATA_PROFILE_SYS_NAME_KWEBCAST', 'KMS_KWEBCAST2'); // metadataProfileSysNameKWebcast
define('METADATA_PROFILE_SYS_NAME_EVENTS', 'KMS_EVENTS3'); // metadataProfileSysNameEvents

// Set KS expiry to one day
define('KS_EXPIRY', 60*60*24);

// Assign these values appropriately
define('APP_NAME', 'Kaltura Webcaster');
define('PRESENTER_NAME', 'Philip Futernik');
define('PRESENTER_TITLE', 'Solutions Engineer');
define('PRESENTER_BIO', 'Philip is a big fan of webcasting!');
define('PRESENTER_LINK', 'https://www.linkedin.com/in/philip-futernik/');
define('WEBCAST_NAME', 'Kaltura Sample Webcast');
define('WEBCAST_DESCRIPTION', 'Here is proof that Kaltura has made webcasting easy');
?>
