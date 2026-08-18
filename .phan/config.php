<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

// Optional extensions
$cfg['directory_list'][] = '../../extensions/Gadgets';
$cfg['exclude_analysis_directory_list'][] = '../../extensions/Gadgets';
$cfg['directory_list'][] = '../../extensions/Translate';
$cfg['exclude_analysis_directory_list'][] = '../../extensions/Translate';

return $cfg;
