<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

// Optional extensions, each asked for something only once it has said it is loaded. Naming them
// resolves their symbols without analysing their code, and is also what fetches them: quibble-action
// reads this directory list to decide what to clone, so a name here needs no workflow change.
$cfg['directory_list'][] = '../../extensions/Gadgets';
$cfg['exclude_analysis_directory_list'][] = '../../extensions/Gadgets';
$cfg['directory_list'][] = '../../extensions/Translate';
$cfg['exclude_analysis_directory_list'][] = '../../extensions/Translate';

return $cfg;
