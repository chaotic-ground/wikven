<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

// Gadgets is optional (referenced behind class_exists guards); phan resolves it without analysing.
$cfg['directory_list'][] = '../../extensions/Gadgets';
$cfg['exclude_analysis_directory_list'][] = '../../extensions/Gadgets';

return $cfg;
