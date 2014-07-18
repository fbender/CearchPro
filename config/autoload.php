<?php
/**
 * Contao Open Source CMS
 *
 * Copyright (C) 2005-2014 Leo Feyer
 *
 * @package
 * @author    Steffen Mohring
 * @license   LGPL
 * @copyright Steffen Mohring 2014
 */

/**
 * Register the classes
 */
ClassLoader::addClasses(array
(
    //General Classes
	'Contao\Search'                    => 'system/modules/zCearchPro/classes/Search.php',
	'Transliterate'                    => 'system/modules/zCearchPro/classes/Transliterate.php',

    //Modules
	'Contao\ModuleSearch'              => 'system/modules/zCearchPro/modules/ModuleSearch.php'
));

TemplateLoader::addFiles(array
(
	'mod_search'                    => 'system/modules/zCearchPro/templates/',
	'search_default'                    => 'system/modules/zCearchPro/templates/'
));



