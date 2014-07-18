CearchPro
=========

* Enhances the default Contao search
* Uses the [Levensthein algorithm](http://en.wikipedia.org/wiki/Levenshtein_distance)
* Ignores typing errors by showing alternate words e.g "Mannnheim" shows "Mannheim", "Räferenzn" shows "Referenzen"
* Shows "Did you mean..." on search results page
* Transliterates all special characters for example "o" = "ö", "ê" = "e"
* Added word stoplists for german and english to provide better search results 
* SearchIndexer now also indexes OpenGraph image tags.
* SearchIndexer saves transliterated Version of all words
* Shows thumbnail for each search result


**Works on:**
Contao > 3.1.5


**Tested on:**
Contao 3.1.5, Contao 3.3.3


**HowTo:**
* Copy "zCearchPro" Folder to /system/modules/
* Enable CearchPro in Contao search engine module

------

[![Creative Commons License](https://i.creativecommons.org/l/by-sa/4.0/88x31.png)](http://creativecommons.org/licenses/by-sa/4.0/)

<span xmlns:dct="http://purl.org/dc/terms/" property="dct:title">**CearchPro**</span> by [feedback media design](https://github.com/feedbackmedia/cearch/) is licensed under a [Creative Commons Attribution-ShareAlike 4.0 International License](http://creativecommons.org/licenses/by-sa/4.0/). Based on a work at [https://github.com/contao/core](https://github.com/contao/core).
