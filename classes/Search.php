<?php

/**
 * Contao Open Source CMS
 *
 * Copyright (c) 2005-2013 Leo Feyer
 *
 * @package Library
 * @link    https://contao.org
 * @license http://www.gnu.org/licenses/lgpl-3.0.html LGPL
 */

namespace Contao;


/**
 * Creates and queries the search index
 *
 * The class takes the HTML markup of a page, exctracts the content and writes
 * it to the database (search index). It also provides a method to query the
 * seach index, returning the matching entries.
 *
 * Usage:
 *
 *     Search::indexPage($objPage->row());
 *     $result = Search::searchFor('keyword');
 *
 *     while ($result->next())
 *     {
 *         echo $result->url;
 *     }
 *
 * @package   Library
 * @author    Leo Feyer <https://github.com/leofeyer>
 * @copyright Leo Feyer 2005-2013
 */
class Search
{

	/**
	 * Object instance (Singleton)
	 * @var \Search
	 */
	protected static $objInstance;


	/**
	 * Index a page
	 *
	 * @param array $arrData The data array
	 *
	 * @return boolean True if a new record was created
	 */
	public static function indexPage($arrData)
	{
		$objDatabase = \Database::getInstance();

		$arrSet['url'] = $arrData['url'];
		$arrSet['title'] = $arrData['title'];
		$arrSet['protected'] = $arrData['protected'];
		$arrSet['filesize'] = $arrData['filesize'];
		$arrSet['groups'] = $arrData['groups'];
		$arrSet['pid'] = $arrData['pid'];
		$arrSet['language'] = $arrData['language'];

		// Get the file size from the raw content
		if (!$arrSet['filesize'])
		{
			$arrSet['filesize'] = number_format((strlen($arrData['content']) / 1024 ), 2, '.', '');
		}

		// Replace special characters
		$strContent = str_replace(array("\n", "\r", "\t", '&#160;', '&nbsp;'), ' ', $arrData['content']);

		// Strip script tags
		while (($intStart = strpos($strContent, '<script')) !== false)
		{
			if (($intEnd = strpos($strContent, '</script>', $intStart)) !== false)
			{
				$strContent = substr($strContent, 0, $intStart) . substr($strContent, $intEnd + 9);
			}
			else
			{
				break; // see #5119
			}
		}

		// Strip style tags
		while (($intStart = strpos($strContent, '<style')) !== false)
		{
			if (($intEnd = strpos($strContent, '</style>', $intStart)) !== false)
			{
				$strContent = substr($strContent, 0, $intStart) . substr($strContent, $intEnd + 8);
			}
			else
			{
				break; // see #5119
			}
		}

		// Strip non-indexable areas
		while (($intStart = strpos($strContent, '<!-- indexer::stop -->')) !== false)
		{
			if (($intEnd = strpos($strContent, '<!-- indexer::continue -->', $intStart)) !== false)
			{
				$intCurrent = $intStart;

				// Handle nested tags
				while (($intNested = strpos($strContent, '<!-- indexer::stop -->', $intCurrent + 22)) !== false && $intNested < $intEnd)
				{
					if (($intNewEnd = strpos($strContent, '<!-- indexer::continue -->', $intEnd + 26)) !== false)
					{
						$intEnd = $intNewEnd;
						$intCurrent = $intNested;
					}
					else
					{
						break; // see #5119
					}
				}

				$strContent = substr($strContent, 0, $intStart) . substr($strContent, $intEnd + 26);
			}
			else
			{
				break; // see #5119
			}
		}

		// HOOK: add custom logic
		if (isset($GLOBALS['TL_HOOKS']['indexPage']) && is_array($GLOBALS['TL_HOOKS']['indexPage']))
		{
			foreach ($GLOBALS['TL_HOOKS']['indexPage'] as $callback)
			{
				\System::importStatic($callback[0])->$callback[1]($strContent, $arrData, $arrSet);
			}
		}

		// Free the memory
		unset($arrData['content']);

		// Calculate the checksum (see #4179)
		$arrSet['checksum'] = md5(preg_replace('/ +/', ' ', strip_tags($strContent)));

		// Return if the page is indexed and up to date
		$objIndex = $objDatabase->prepare("SELECT id, checksum FROM tl_search WHERE url=? AND pid=?")
								->limit(1)
								->execute($arrSet['url'], $arrSet['pid']);

		if ($objIndex->numRows && $objIndex->checksum == $arrSet['checksum'])
		{
			return false;
		}

		$arrMatches = array();
		preg_match('/<\/head>/', $strContent, $arrMatches, PREG_OFFSET_CAPTURE);
		$intOffset = strlen($arrMatches[0][0]) + $arrMatches[0][1];

		// Split page in head and body section
		$strHead = substr($strContent, 0, $intOffset);
		$strBody = substr($strContent, $intOffset);

		unset($strContent);
		$tags = array();

		// Get description
		if (preg_match('/<meta[^>]+name="description"[^>]+content="([^"]*)"[^>]*>/i', $strHead, $tags))
		{
			$arrData['description'] = trim(preg_replace('/ +/', ' ', \String::decodeEntities($tags[1])));
		}

		// Get keywords
		if (preg_match('/<meta[^>]+name="keywords"[^>]+content="([^"]*)"[^>]*>/i', $strHead, $tags))
		{
			$arrData['keywords'] = trim(preg_replace('/ +/', ' ', \String::decodeEntities($tags[1])));
		}

		//Feedback: Steffen: Get OpenGraph Image
		if (preg_match('/<meta property="og:image" content="([^"]*)"[^>]*>/i', $strHead, $tags)){
			$arrData['image'] = $tags[1];
		}

		// Read title and alt attributes
		if (preg_match_all('/<* (title|alt)="([^"]*)"[^>]*>/i', $strBody, $tags))
		{
			$arrData['keywords'] .= ' ' . implode(', ', array_unique($tags[2]));
		}

		// Add a whitespace character before line-breaks and between consecutive tags (see #5363)
		$strBody = str_ireplace(array('<br', '><'), array(' <br', '> <'), $strBody);
		$strBody = strip_tags($strBody);

		// Put everything together
		$arrSet['text'] = $arrData['title'] . ' ' . $arrData['description'] . ' ' . $strBody . ' ' . $arrData['keywords'];
		$arrSet['text'] = trim(preg_replace('/ +/', ' ', \String::decodeEntities($arrSet['text'])));

		//Feedback: Steffen: Add OpenGraph Image to Search index
		$arrSet['imageUrl'] = $arrData['image'];
		$arrSet['tstamp'] = time();

		// Update an existing old entry
		if ($objIndex->numRows)
		{
			$objDatabase->prepare("UPDATE tl_search %s WHERE id=?")
						->set($arrSet)
						->execute($objIndex->id);

			$intInsertId = $objIndex->id;
		}

		// Add a new entry
		else
		{
			// Check for a duplicate record with the same checksum
			$objDuplicates = $objDatabase->prepare("SELECT id, url FROM tl_search WHERE pid=? AND checksum=?")
										 ->limit(1)
										 ->execute($arrSet['pid'], $arrSet['checksum']);

			// Keep the existing record
			if ($objDuplicates->numRows)
			{
				// Update the URL if the new URL is shorter or the current URL is not canonical
				if (substr_count($arrSet['url'], '/') < substr_count($objDuplicates->url, '/') || strncmp($arrSet['url'] . '?', $objDuplicates->url, utf8_strlen($arrSet['url']) + 1) === 0)
				{
					$objDatabase->prepare("UPDATE tl_search SET url=? WHERE id=?")
								->execute($arrSet['url'], $objDuplicates->id);
				}

				return false;
			}

			// Insert the new record if there is no duplicate
			else
			{
				$objInsertStmt = $objDatabase->prepare("INSERT INTO tl_search %s")
											 ->set($arrSet)
											 ->execute();

				$intInsertId = $objInsertStmt->insertId;
			}
		}

		// Remove quotes
		$strText = str_replace(array('´', '`'), "'", $arrSet['text']);

		// Remove special characters
		if (function_exists('mb_eregi_replace'))
		{
			$strText = mb_eregi_replace('[^[:alnum:]\'\.:,\+_-]|- | -|\' | \'|\. |\.$|: |:$|, |,$', ' ', $strText);
		}
		else
		{
			$strText = preg_replace(array('/- /', '/ -/', "/' /", "/ '/", '/\. /', '/\.$/', '/: /', '/:$/', '/, /', '/,$/', '/[^\pN\pL\'\.:,\+_-]/u'), ' ', $strText);
		}

		// Split words
		$arrWords = preg_split('/ +/', utf8_strtolower($strText));
		$arrIndex = array();

		// Index words
		foreach ($arrWords as $strWord)
		{
			// Strip a leading plus (see #4497)
			if (strncmp($strWord, '+', 1) === 0)
			{
				$strWord = substr($strWord, 1);
			}

			$strWord = trim($strWord);

			if (!strlen($strWord) || preg_match('/^[\.:,\'_-]+$/', $strWord))
			{
				continue;
			}

			if (preg_match('/^[\':,]/', $strWord))
			{
				$strWord = substr($strWord, 1);
			}

			if (preg_match('/[\':,\.]$/', $strWord))
			{
				$strWord = substr($strWord, 0, -1);
			}

			//Feedback: Steffen: Skip Word if on Stoplist
			if(self::getExclude($strWord)) continue;

			if (isset($arrIndex[$strWord]))
			{
				$arrIndex[$strWord]++;
				continue;
			}

			$arrIndex[$strWord] = 1;
		}

		// Remove existing index
		$objDatabase->prepare("DELETE FROM tl_search_index WHERE pid=?")
					->execute($intInsertId);

		unset($arrSet);

		// Create new index
		$arrKeys = array();
		$arrValues = array();

		//Feedback: Steffen: Add Word in transliterated version
		foreach ($arrIndex as $k=>$v)
		{
			$arrKeys[] = "(?, ?, ?, ?, ?)";
			$arrValues[] = $intInsertId;
			$arrValues[] = $k;
			$arrValues[] = \Transliterate::trans($k);
			$arrValues[] = $v;
			$arrValues[] = $arrData['language'];
		}

		//Feedback: Steffen: Search by Transliterated Version
		$objDatabase->prepare("INSERT INTO tl_search_index (pid, word, word_transliterated, relevance, language) VALUES " . implode(", ", $arrKeys))
					->execute($arrValues);

		return true;
	}


	/**
	 * Search the index and return the result object
	 *
	 * @param string  $strKeywords The keyword string
	 * @param boolean $blnOrSearch If true, the result can contain any keyword
	 * @param array   $arrPid      An optional array of page IDs to limit the result to
	 * @param integer $intRows     An optional maximum number of result rows
	 * @param integer $intOffset   An optional result offset
	 * @param boolean $blnFuzzy    If true, the search will be fuzzy
	 *
	 * @return \Database\Result The database result object
	 *
	 * @throws \Exception If the cleaned keyword string is empty
	 */

	//Feedback: Steffen: Add Parameters for Levensthein
	public static function searchFor($strKeywords, $blnOrSearch=false, $arrPid=array(), $intRows=0, $intOffset=0, $blnFuzzy=false, $levensthein=false, $maxDist=2)
	{
		// Clean the keywords
		$strKeywords = utf8_strtolower($strKeywords);
		$strKeywords = \String::decodeEntities($strKeywords);

		if (function_exists('mb_eregi_replace'))
		{
			$strKeywords = mb_eregi_replace('[^[:alnum:] \*\+\'"\.:,_-]|\. |\.$|: |:$|, |,$', ' ', $strKeywords);
		}
		else
		{
			$strKeywords = preg_replace(array('/\. /', '/\.$/', '/: /', '/:$/', '/, /', '/,$/', '/[^\pN\pL \*\+\'"\.:,_-]/u'), ' ', $strKeywords);
		}

		// Check keyword string
		if (!strlen($strKeywords))
		{
			throw new \Exception('Empty keyword string');
		}

		// Split keywords
		$arrChunks = array();
		preg_match_all('/"[^"]+"|[\+\-]?[^ ]+\*?/', $strKeywords, $arrChunks);

		//Feedback: Steffen: Use own implementation for Levensthein
		if($levensthein){
			return self::levensthein($arrChunks, $arrPid, $intRows, $intOffset, $maxDist);
		}

		$arrPhrases = array();
		$arrKeywords = array();
		$arrWildcards = array();
		$arrIncluded = array();
		$arrExcluded = array();

		foreach ($arrChunks[0] as $strKeyword)
		{
			if (substr($strKeyword, -1) == '*' && strlen($strKeyword) > 1)
			{
				$arrWildcards[] = str_replace('*', '%', $strKeyword);
				continue;
			}

			switch (substr($strKeyword, 0, 1))
			{
				// Phrases
				case '"':
					if (($strKeyword = trim(substr($strKeyword, 1, -1))) != false)
					{
						$arrPhrases[] = '[[:<:]]' . str_replace(array(' ', '*'), array('[^[:alnum:]]+', ''), $strKeyword) . '[[:>:]]';
					}
					break;

				// Included keywords
				case '+':
					if (($strKeyword = trim(substr($strKeyword, 1))) != false)
					{
						$arrIncluded[] = $strKeyword;
					}
					break;

				// Excluded keywords
				case '-':
					if (($strKeyword = trim(substr($strKeyword, 1))) != false)
					{
						$arrExcluded[] = $strKeyword;
					}
					break;

				// Wildcards
				case '*':
					if (strlen($strKeyword) > 1)
					{
						$arrWildcards[] = str_replace('*', '%', $strKeyword);
					}
					break;

				// Normal keywords
				default:
					$arrKeywords[] = $strKeyword;
					break;
			}
		}

		// Fuzzy search
		if ($blnFuzzy)
		{
			foreach ($arrKeywords as $strKeyword)
			{
				$arrWildcards[] = '%' . $strKeyword . '%';
			}

			$arrKeywords = array();
		}

		// Count keywords
		$intPhrases = count($arrPhrases);
		$intWildcards = count($arrWildcards);
		$intIncluded = count($arrIncluded);
		$intExcluded = count($arrExcluded);

		$intKeywords = 0;
		$arrValues = array();

		// Remember found words so we can highlight them later
		$strQuery = "SELECT tl_search_index.pid AS sid, GROUP_CONCAT(word) AS matches";

		// Get the number of wildcard matches
		if (!$blnOrSearch && $intWildcards)
		{
			$strQuery .= ", (SELECT COUNT(*) FROM tl_search_index WHERE (" . implode(' OR ', array_fill(0, $intWildcards, 'word LIKE ?')) . ") AND pid=sid) AS wildcards";
			$arrValues = array_merge($arrValues, $arrWildcards);
		}

		// Count the number of matches
		$strQuery .= ", COUNT(*) AS count";

		// Get the relevance
		$strQuery .= ", SUM(relevance) AS relevance";

		// Get meta information from tl_search
		$strQuery .= ", tl_search.*"; // see #4506

		// Prepare keywords array
		$arrAllKeywords = array();

		// Get keywords
		if (!empty($arrKeywords))
		{
			$arrAllKeywords[] = implode(' OR ', array_fill(0, count($arrKeywords), 'word=?'));
			$arrValues = array_merge($arrValues, $arrKeywords);
			$intKeywords += count($arrKeywords);
		}

		// Get included keywords
		if ($intIncluded)
		{
			$arrAllKeywords[] = implode(' OR ', array_fill(0, $intIncluded, 'word=?'));
			$arrValues = array_merge($arrValues, $arrIncluded);
			$intKeywords += $intIncluded;
		}

		// Get keywords from phrases
		if ($intPhrases)
		{
			foreach ($arrPhrases as $strPhrase)
			{
				$arrWords = explode('[^[:alnum:]]+', utf8_substr($strPhrase, 7, -7));
				$arrAllKeywords[] = implode(' OR ', array_fill(0, count($arrWords), 'word=?'));
				$arrValues = array_merge($arrValues, $arrWords);
				$intKeywords += count($arrWords);
			}
		}

		// Get wildcards
		if ($intWildcards)
		{
			$arrAllKeywords[] = implode(' OR ', array_fill(0, $intWildcards, 'word LIKE ?'));
			$arrValues = array_merge($arrValues, $arrWildcards);
		}

		$strQuery .= " FROM tl_search_index LEFT JOIN tl_search ON(tl_search_index.pid=tl_search.id) WHERE (" . implode(' OR ', $arrAllKeywords) . ")";

		// Get phrases
		if ($intPhrases)
		{
			$strQuery .= " AND (" . implode(($blnOrSearch ? ' OR ' : ' AND '), array_fill(0, $intPhrases, 'tl_search_index.pid IN(SELECT id FROM tl_search WHERE text REGEXP ?)')) . ")";
			$arrValues = array_merge($arrValues, $arrPhrases);
		}

		// Include keywords
		if ($intIncluded)
		{
			$strQuery .= " AND tl_search_index.pid IN(SELECT pid FROM tl_search_index WHERE " . implode(' OR ', array_fill(0, $intIncluded, 'word=?')) . ")";
			$arrValues = array_merge($arrValues, $arrIncluded);
		}

		// Exclude keywords
		if ($intExcluded)
		{
			$strQuery .= " AND tl_search_index.pid NOT IN(SELECT pid FROM tl_search_index WHERE " . implode(' OR ', array_fill(0, $intExcluded, 'word=?')) . ")";
			$arrValues = array_merge($arrValues, $arrExcluded);
		}

		// Limit results to a particular set of pages
		if (!empty($arrPid) && is_array($arrPid))
		{
			$strQuery .= " AND tl_search_index.pid IN(SELECT id FROM tl_search WHERE pid IN(" . implode(',', array_map('intval', $arrPid)) . "))";
		}

		$strQuery .= " GROUP BY tl_search_index.pid";

		// Make sure to find all words
		if (!$blnOrSearch)
		{
			// Number of keywords without wildcards
			$strQuery .= " HAVING count >= " . $intKeywords;

			// Dynamically add the number of wildcard matches
			if ($intWildcards)
			{
				$strQuery .= " + IF(wildcards>" . $intWildcards . ", wildcards, " . $intWildcards . ")";
			}
		}

		// Sort by relevance
		$strQuery .= " ORDER BY relevance DESC";

		// Return result
		$objResultStmt = \Database::getInstance()->prepare($strQuery);

		if ($intRows > 0)
		{
			$objResultStmt->limit($intRows, $intOffset);
		}

		//Feedback: Steffen: Change return format
		return array('exact' => $objResultStmt->execute($arrValues));
	}


	/**
	 * Remove an entry from the search index
	 *
	 * @param string $strUrl The URL to be removed
	 */
	public static function removeEntry($strUrl)
	{
		$objDatabase = \Database::getInstance();

		$objSearch = $objDatabase->prepare("SELECT * FROM tl_search WHERE url=?")
								 ->limit(1)
								 ->execute($strUrl);

		if ($objSearch->numRows)
		{
			$objDatabase->prepare("DELETE FROM tl_search WHERE id=?")
						->execute($objSearch->id);

			$objDatabase->prepare("DELETE FROM tl_search_index WHERE pid=?")
						->execute($objSearch->id);
		}
	}

	//Feedback: Steffen: Use own implementation for Levensthein
	public static function levensthein($arrChunks, $arrPid, $intRows, $intOffset, $maxDist){

		$exactresults = array();
		$moreresults = array();

		self::searchIndex($arrChunks[0], $maxDist, $exactresults, $moreresults);

		//If no 100% Results found, Return only More Results otherwise continue
		if(! empty($exactresults)) $arrKeywords = $exactresults;
		else return array('more' => $moreresults);

		// Remember found words so we can highlight them later
		$strQuery = "SELECT tl_search_index.pid AS sid, GROUP_CONCAT(word) AS matches";

		// Count the number of matches
		$strQuery .= ", COUNT(*) AS count";

		// Get the relevance
		$strQuery .= ", SUM(relevance) AS relevance";

		// Get meta information from tl_search
		$strQuery .= ", tl_search.*"; // see #4506

		$arrAllKeywords = array();
		$intKeywords = 0;
		$arrValues = array();

		// Get keywords
		if (!empty($arrKeywords))
		{
			$arrAllKeywords[] = implode(' OR ', array_fill(0, count($arrKeywords), 'word_transliterated=?'));
			$arrValues = array_merge($arrValues, $arrKeywords);
			$intKeywords += count($arrKeywords);
		}

		$strQuery .= " FROM tl_search_index LEFT JOIN tl_search ON(tl_search_index.pid=tl_search.id) WHERE (" . implode(' OR ', $arrAllKeywords) . ")";

		// Limit results to a particular set of pages
		if (!empty($arrPid) && is_array($arrPid))
		{
			$strQuery .= " AND tl_search_index.pid IN(SELECT id FROM tl_search WHERE pid IN(" . implode(',', array_map('intval', $arrPid)) . "))";
		}

		$strQuery .= " GROUP BY tl_search_index.pid";

		// Sort by relevance
		$strQuery .= " ORDER BY relevance DESC";

		// Return result
		$objResultStmt = \Database::getInstance()->prepare($strQuery);

		if ($intRows > 0)
		{
			$objResultStmt->limit($intRows, $intOffset);
		}

		return array('exact' => $objResultStmt->execute($arrValues), 'more' => $moreresults);
	}

	public static function searchIndex($arrChunks, $maxDist, &$exactresults, &$moreresults){

		$minResLen = 2;

		foreach($arrChunks as $chunk){

			$transChunk = \Transliterate::trans($chunk);
			$wordlength = strlen($transChunk);
			$minLen = $wordlength-$maxDist;
			$maxLen = $wordlength+$maxDist;

			$db = \Contao\Database::getInstance();
			$res = $db->query('SELECT DISTINCT word_transliterated, word FROM tl_search_index WHERE length(word_transliterated) BETWEEN '.$minLen.' AND '.$maxLen.' AND language = "'.$GLOBALS['TL_LANGUAGE'].'"');

			while($word = $res->fetchRow()) {

				$lev = levenshtein($transChunk, $word[0]);

				if($lev <= $maxDist){
					if($lev == 0)
						$exactresults[] = $word[0];
					else{

						//Clean More Results
						//Result Length to short
						if(strlen($word[0]) <= $minResLen) continue;

						//Result has different Type (Prevent matches like XX = 01)
						if(is_numeric($word[0]) && ! is_numeric($transChunk)) continue;

						$moreresults[$lev][] = array('trans' => $word[0], 'org' => $word[1]);
					}
				}
			}
		}

		//Sort More-Results by lowest Levensthein difference
		ksort($moreresults);
	}

	//Feedback: Steffen: Match Keyword with Exclude List
	private static function getExclude($keyword){

		$stopwords = array();
		$stopwords['de'] = 'ab, aber, alle, allem, allen, aller, allerdings, als, also, am, an, ander, andere, anderem, anderen, anderer, anderes, anderm, andern, anderr, anders, angesichts, auch, auf, aus, ausser, ausserdem, begann, bei, beide, beiden, beides, beim, bekommen, bereits, bescheid, bestehen, besteht, bevor, bin, bis, bislang, bist, bitte, bleib, bleibt, bloss, brauchen, braucht, da, dabei, dadurch, dafuer, dafür, dagegen, daher, damit, danach, dann, daran, darf, darin, darueber, darum, darunter, darüber, das, dasselbe, davon, dazu, daß, dei, dein, deine, deinem, deinen, deiner, deines, dem, demselben, den, denen, denkt, denn, denselben, der, derer, derselbe, derselben, des, deshalb, desselben, dessen, deswegen, dich, die, dies, diese, dieselbe, dieselben, diesem, diesen, dieser, dieses, dir, doch, dort, dran, drin, du, duerfen, durch, durfte, durften, ebenfalls, ebenso, eher, ein, eine, einem, einen, einer, eines, einig, einige, einigem, einigen, einiger, einiges, einmal, einzig, entweder, er, erhalten, erst, erste, ersten, es, etwa, etwas, euch, euer, eure, eurem, euren, eurer, eures, falls, fast, ferner, folgender, folglich, fuer, fuers, für, gab, ganz, geben, gebracht, gegen, gehabt, gehoert, geht, gehört, gekonnt, gemaess, genau, genutzt, gerade, geschadet, getan, gewesen, gewollt, geworden, gibt, gilt, grade, hab, habe, haben, haette, haetten, hal, hallo, hast, hat, hatte, hatten, hattest, hattet, heraus, herein, hier, hin, hinein, hinter, holt, home, hätte, ich, ihm, ihn, ihnen, ihr, ihre, ihrem, ihren, ihrer, ihres, im, immer, in, indem, infolge, inkl, innen, innerhalb, ins, insbesondere, inzwischen, irgend, irgendwas, irgendwen, irgendwer, irgendwie, irgendwo, ist, ja, jede, jedem, jeden, jeder, jederzeit, jedes, jedoch, jemand, jene, jenem, jenen, jener, jenes, jetzt, kam, kann, kannst, kein, keine, keinem, keinen, keiner, keines, koennen, koennte, koennten, kommt, konnte, konnten, kuenftig, können, könnt, könnte, las, leer, lich, liegt, lässt, machen, macht, machte, machten, mal, man, manche, manchem, manchen, mancher, manches, mehr, mein, meine, meinem, meinen, meiner, meines, meist, meiste, meisten, mich, mir, mit, moechte, moechten, muessen, muessten, musste, mussten, muß, mußt, möchte, möchten, müssen, müssten, müßt, nach, nachdem, nacher, naemlich, nahezu, neben, nein, nem, nen, nicht, nichts, noch, nuetzt, nun, nur, nutzt, nämlich, nützt, ob, obgleich, obwohl, oder, oft, ohne, per, pro, rein, rund, scho, schon, sehr, seid, sein, seine, seinem, seinen, seiner, seines, seit, seitdem, seither, selber, selbst, sich, sicherlich, sie, siehe, sieht, sind, sitzt, so, sobald, solange, solch, solche, solchem, solchen, solcher, solches, soll, sollen, sollst, sollt, sollte, sollten, somit, sondern, sonst, soweit, sowie, spaeter, stellt, stets, such, tragen, treten, tun, ueber, um, ums, und, uns, unse, unsem, unsen, unser, unsere, unserem, unseren, unses, unter, unterliegt, usw, viel, viele, vielleicht, vollstaendig, vollständig, vom, von, vor, vorbei, vorher, vorueber, vorüber, waehrend, waere, waeren, wann, war, waren, warst, warum, was, weg, wegen, weil, weiter, weitere, weiterem, weiteren, weiterer, weiteres, weiterhin, welche, welchem, welchen, welcher, welches, wem, wen, wenigstens, wenn, wenngleich, wer, werde, werden, werdet, weshalb, wessen, wie, wieder, wies, wieso, will, wir, wird, wirst, wo, wodurch, woher, wohin, wollen, wollte, wollten, woran, worauf, worin, wozu, wuenschen, wuerde, wuerden, wurde, wurden, während, wär, wäre, wären, wünschen, würde, würden, zig, zu, zufolge, zum, zur, zusammen, zwar, zwischen, über';
		$stopwords['en'] = 'a, able, about, above, according, accordingly, across, actually, after, afterwards, again, against, albeit, all, allow, allows, almost, alone, along, already, also, although, always, am, among, amongst, amoungst, amount, an, and, another, any, anybody, anyhow, anyone, anything, anyway, anyways, anywhere, apart, appear, appreciate, appropriate, are, around, as, aside, ask, asking, associated, at, available, away, awfully, b, back, be, became, because, become, becomes, becoming, been, before, beforehand, behind, being, believe, below, beside, besides, best, better, between, beyond, bill, both, bottom, brief, but, by, c, call, came, can, cannot, cant, cause, causes, certain, certainly, changes, clearly, co, com, come, comes, comprises, computer, con, concerning, consequently, consider, considering, contain, containing, contains, corresponding, could, couldn\'t, couldnt, course, cry, currently, d, de, definitely, describe, described, desired, despite, detail, did, different, do, does, doing, done, down, downwards, due, during, e, each, edu, eg, eight, either, eleven, else, elsewhere, empty, enough, entirely, especially, et, etc, even, ever, every, everybody, everyone, everything, everywhere, ex, exactly, example, except, f, far, few, fifteen, fifth, fify, fill, find, fire, first, five, followed, following, follows, for, former, formerly, forth, forty, found, four, from, front, full, further, furthermore, g, generally, get, gets, getting, give, given, gives, go, goes, going, gone, got, gotten, greetings, h, had, happens, hardly, has, hasnt, have, having, he, he\'s, hello, help, hence, her, here, hereafter, hereby, herein, hereupon, hers, herself, herse”, hi, him, himself, himse”, his, hither, hopefully, how, howbeit, however, hundred, i, ie, if, ignored, immediate, in, inasmuch, inc, incl, indeed, indicate, indicated, indicates, inner, insofar, instead, interest, into, inward, is, it, its, itse, itself, j, just, k, keep, keeps, kept, know, known, knows, l, last, lately, later, latter, latterly, least, less, lest, let, let\'s, like, liked, likely, little, look, looking, looks, ltd, m, made, mainly, many, may, maybe, me, mean, means, meanwhile, merely, might, mill, mine, more, moreover, most, mostly, move, much, must, my, myself, myse”, n, name, namely, nd, near, nearly, necessary, need, needs, neither, never, nevertheless, new, next, nine, no, nobody, non, none, noone, nor, normally, not, nothing, novel, now, nowhere, o, obviously, of, off, often, oh, ok, okay, old, on, once, one, ones, only, onto, or, other, others, otherwise, ought, our, ours, ourselves, out, outside, over, overall, own, p, part, particular, particularly, per, perhaps, placed, please, plus, possible, preferably, preferred, present, presumably, probably, provides, put, q, que, quite, qv, r, rather, rd, re, really, reasonably, regarding, regardless, regards, relatively, respectively, right, s, said, same, saw, say, saying, says, second, secondly, see, seeing, seem, seemed, seeming, seems, seen, self, selves, sensible, sent, serious, seriously, seven, several, shall, she, should, show, side, since, sincere, six, sixty, so, some, somebody, somehow, someone, something, sometime, sometimes, somewhat, somewhere, soon, sorry, specified, specify, specifying, still, sub, such, suitable, sup, sure, system, t, take, taken, tell, ten, tends, th, than, thank, thanks, thanx, that, thats, the, their, theirs, them, themselves, then, thence, there, thereafter, thereby, therefor, therefore, therein, thereof, theres, thereto, thereupon, these, they, thick, thin, think, third, this, thorough, thoroughly, those, though, three, through, throughout, thru, thus, to, together, too, took, top, toward, towards, tried, tries, truly, try, trying, twelve, twenty, twice, two, u, un, under, unfortunately, unless, unlikely, until, unto, up, upon, us, use, used, useful, uses, using, usually, uucp, v, value, various, very, via, viz, vs, w, want, wants, was, way, we, welcome, well, went, were, what, whatever, whatsoever, when, whence, whenever, whensoever, where, whereafter, whereas, whereat, whereby, wherefrom, wherein, whereinto, whereof, whereon, whereto, whereunto, whereupon, wherever, wherewith, whether, which, whichever, whichsoever, while, whilst, whither, who, whoever, whole, whom, whomever, whomsoever, whose, whosoever, why, will, willing, wish, with, within, without, wonder, would, x, y, yes, yet, you, your, yours, yourself, yourselves, zero';

		foreach ($stopwords as $stopword){
			if(preg_match('/\b'.$keyword.'\b/i', $stopword)) return true;
		}
		return false;
	}

	/**
	 * Prevent cloning of the object (Singleton)
	 *
	 * @deprecated Search is now a static class
	 */
	final public function __clone() {}


	/**
	 * Return the object instance (Singleton)
	 *
	 * @return \Search The object instance
	 *
	 * @deprecated Search is now a static class
	 */
	public static function getInstance()
	{
		if (static::$objInstance === null)
		{
			static::$objInstance = new static();
		}

		return static::$objInstance;
	}
}
