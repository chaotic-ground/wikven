<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

// Gadgets is an optional dependency: buildScripts/rewriteScripts reference its
// classes behind class_exists() guards. Tell phan about it (so the references
// resolve) without analysing it.
$cfg['directory_list'][] = '../../extensions/Gadgets';
$cfg['exclude_analysis_directory_list'][] = '../../extensions/Gadgets';

return $cfg;
