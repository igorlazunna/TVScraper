<?php

require_once('Logger.php');
require_once('TVShowUtils.php');

class TVShowScraperDB  {
	
	protected $pDom;
	protected $xPath;
	
	protected $logger;
	
	
	public function setLogger($logger) {
		$this->logger = $logger;
	}
	
	public function setLogFile($logFile, $severity = LOGGER_DEBUG) {
		$this->logger = new Logger($logFile, $severity);
	}
	
	protected function log($msg, $severity = LOGGER_DEBUG) {
		if ($this->logger) $this->logger->log($msg, $severity);
	}
	
	protected function error($msg) {
		if ($this->logger) $this->logger->error($msg);
	}
	
	public function __construct($fileName) {
		if (file_exists($fileName)) {
			$this->pDom = DOMDocument::load($fileName);
			$this->xPath = new DOMXPath($this->pDom);
			$x = $this->xPath->query('/tvscraper');
		} else {
			$this->pDom = new DOMDocument();
			$this->xPath = new DOMXPath($this->pDom);
			$pTvScraper = $this->pDom->createElement('tvscraper');
			$this->pDom->appendChild($pTvScraper);
		}
	}
	
	public function save($fileName) {
		$this->log("Saving data to $fileName");
		$this->pDom->save($fileName);
	}
	
	
	
	
	
	
	
	protected function addElement($tag, $baseXPath) {
		
		$this->log("Searching for xpath $baseXPath");
		$x = $this->xPath->query($baseXPath);
		
		if ($x->length != 1) {
			$this->log("None or multiple root entries found");
			return NULL;
		}
		
		$this->log("Single root entry found, creating new element");
		$root = $x->item(0);
		$newId = uniqid();
		$newElement = $this->pDom->createElement($tag);
		$newElement->setAttribute('id', $newId);
		$root->appendChild($newElement);

		return $newId;
	}

	protected function removeElement($xpath) {
		$x = $this->xPath->query($xpath);
		if ($x->length != 1) {
			return FALSE;
		}
		$elem = $x->item(0);
		$root = $elem->parentNode;
		$root->removeChild($elem);
		return TRUE;
	}
	
	/**
	 * Returns a single DOMNode matching an xpath, only if a single match is found
	 *
	 * @param string $xpath the xpath query
	 * @return DOMElement the query result
	 */
	
	protected function getElement($xpath) {
		$this->log("Searching for xpath $xpath");
		$x = $this->xPath->query($xpath);
		return ($x->length == 1) ? $x->item(0) : FALSE;
	}
	
	protected function setElementTextAttribute($element, $attr, $val) {
		if ($val == '_REMOVE_') {
			$element->removeAttribute($attr);
		} else {
			$text = $this->pDom->createTextNode(utf8_encode($val));
			$child = $this->pDom->createAttribute($attr);
			$child->appendChild($text);
			$element->appendChild($child);
		}
	}

	protected function getElementAttributes($element) {
		$ret = array();
		foreach ($element->attributes as $c) {
			$ret[$c->nodeName] = $c->nodeValue;
		}
		return $ret;
	}
	
	
	// TVSHOW
	
	public function addTVShow($p) {
		
		$newId = $this->addElement('tvshow', '/tvscraper');
		if ($newId == NULL) {
			$this->error("Can't create tvshow element");
			return FALSE; 
		}
		
		if (! $this->setTVShow($newId, $p)) {
			$this->remoteTVShow($newId);
			return FALSE;
		}

		return $newId;
	}
	
	public function removeTVShow($id) {
		$seasons = $this->getTVShowSeasons($id);
		if ($seasons === FALSE) return FALSE;

		foreach ($seasons as $season) {
			$this->log("Removing season " . $season['id']);
			if ($this->removeSeason($season['id']) === FALSE) return FALSE;
		}

		if ($this->removeElement("/tvscraper/tvshow[@id='$id']")) {
			return TRUE;
		} else {
			$this->error("Can't remove TV show $id");
			return FALSE;
		}
	}
	
	public function setTVShow($id, $p) {
		$tvShow = $this->getElement("/tvscraper/tvshow[@id='$id']");
		if ($tvShow === FALSE) {
			$this->error("Could not find unique TV show $id");
			return FALSE;
		}
		
		foreach ($p as $k => $v) {
			switch ($k) {
			case 'title':
				$this->setElementTextAttribute($tvShow, $k, $v);
				break;
			default:
				$this->error("Unknown TV show parameter $k");
				return FALSE;
			}
		}

		return TRUE;
	}

	public function getTVShow($id) {
		$tvShow = $this->getElement("/tvscraper/tvshow[@id='$id']");
		if ($tvShow === FALSE) {
			$this->error("Can't find unique show $id");
			return FALSE;
		}

		$res = $this->getElementAttributes($tvShow);

		$episodes = $this->xPath->query("/tvscraper/tvshow[@id='$id']/season[@status='watched']/episode[@airDate]");
		$t = intval((time() / 86400) - 1) * 86400;
		for ($i = 0; $i < $episodes->length; $i++) {
			$air = $episodes->item($i)->getAttribute('airDate');
			if ($air < $t) {
				if (! isset($res['lastAirDate']) || $res['lastAirDate'] < $air) {
					$res['lastAirDate'] = $air;
				}
			} else {
				if (! isset($res['nextAirDate']) || $res['nextAirDate'] > $air) {
					$res['nextAirDate'] = $air;
				}
			}
		}
		$files = $this->xPath->query("/tvscraper/tvshow[@id='$id']/season[@status='watched']/file[@pubDate]");
		for ($i = 0; $i < $files->length; $i++) {
			$pubDate = $files->item($i)->getAttribute('pubDate');
			if (!isset($res['lastPubDate']) || $res['lastPubDate'] < $pubDate) {
				$res['lastPubDate'] = $pubDate;
			}
		}

		return $res;
	}
	
	public function getAllTVShows() {
		$shows = $this->xPath->query('/tvscraper/tvshow');
		$res = array();
		
		for ($i = 0; $i < $shows->length; $i++) {
			$res[] = $this->getTVShow($shows->item($i)->getAttribute('id'));
		}
		
		return $res;
	}
	

	// SEASON
	
	public function addSeason($showId, $p) {
	
		$newId = $this->addElement('season', "/tvscraper/tvshow[@id='$showId']");
		if ($newId == NULL) {
			$this->error("Can't create new season for show $showId");
			return FALSE;
		}
	
		if (! $this->setSeason($newId, $p)) {
			$this->removeSeason($newId);
			return FALSE;
		}
	
		return $newId;
	}
	
	public function removeSeason($id) {
		$episodes = $this->getSeasonEpisodes($id);
		if ($episodes === FALSE) return FALSE;

		$scrapers = $this->getSeasonScrapers($id);
		if ($scrapers === FALSE) return FALSE;

		foreach ($episodes as $episode) {
			$this->log("Removing episode " . $episode['id']);
			if ($this->removeEpisode($episode['id']) === FALSE) return FALSE;
		}
		
		foreach ($scrapers as $scraper) {
			$this->log("Removing scraper ". $scraper['id']);
			if ($this->removeScraper($scraper['id']) === FALSE) return FALSE;
		}
		
		if ($this->removeElement("/tvscraper/tvshow/season[@id='$id']")) {
			return TRUE;
		} else {
			$this->error("Can't remove season $id");
		}
	}
	
	
	public function setSeason($id, $p) {
		$season = $this->getElement("/tvscraper/tvshow/season[@id='$id']");
		if ($season === FALSE) {
			$this->error("Could not find unique season $id");
			return FALSE;
		}
			
		foreach ($p as $k => $v) {
			switch ($k) {
			case 'n' :
			case 'status':
				$this->setElementTextAttribute($season, $k, $v);
				break;
			default:
				$this->error("Unknown season parameter $k");
				return FALSE;
			}
		}
	
		return TRUE;
	}
	
	public function getSeason($id) {
		$season = $this->getElement("/tvscraper/tvshow/season[@id='$id']");
		if ($season === FALSE) {
			$this->error("Can't fine unique season $id");
			return FALSE;
		}
	
		$res = $this->getElementAttributes($season);
		$res['tvshow'] = $season->parentNode->getAttribute('id');
	
		return $res;
	}
	
	public function getSeasonFromN($showId, $n) {
		$season = $this->getElement("/tvscraper/tvshow[@id='$showId']/season[@n='$n']");
		if ($season === FALSE) {
			return NULL;
		}
		
		return $this->getSeason($season->getAttribute('id'));
	}
	
	public function getTVShowSeasons($showId) {
		$seasons = $this->xPath->query("/tvscraper/tvshow[@id='$showId']/season");
		$res = array();
		
		for ($i = 0; $i < $seasons->length; $i++) {
			$res[] = $this->getSeason($seasons->item($i)->getAttribute('id'));
		}
		
		return $res;
	}
	
	
	public function getAllWatchedSeasons() {
		$x = $this->xPath->query("/tvscraper/tvshow/season[@status='watched']");
		$res = array();
		for ($i = 0; $i < $x->length; $i++) {
			$res[] = $this->getSeason($x->item($i)->getAttribute('id'));
		}
		return $res;
	}
	

	// EPISODE
	
	public function addEpisode($seasonId, $p) {
	
		$newId = $this->addElement('episode', "/tvscraper/tvshow/season[@id='$seasonId']");
		if ($newId == NULL) {
			$this->error("Can't add new episode for season $seasonId");
			return FALSE;
		}
	
		if (! $this->setEpisode($newId, $p)) {
			$this->removeEpisode($newId);
			return FALSE;
		}
	
		return $newId;
	}
	
	public function removeEpisode($id) {
		$files = $this->getFilesForEpisode($id);
		if ($files === FALSE) return FALSE;

		foreach ($files as $file) {
			if ($this->removeFile($file['id']) === FALSE) return FALSE;
		}

		if ($this->removeElement("/tvscraper/tvshow/season/episode[@id='$id']")) {
			return TRUE;
		} else {
			$this->error("Can't remove episode $id");
			return FALSE;
		}
	}
	
	public function setEpisode($id, $p) {
		$episode = $this->getElement("/tvscraper/tvshow/season/episode[@id='$id']");
		if ($episode === FALSE) {
			$this->error("Could not find unique episode $id");
			return FALSE;
		}
			
		foreach ($p as $k => $v) {
			switch ($k){
			case 'n' :
			case 'airDate' :
			case 'title':
				$this->setElementTextAttribute($episode, $k, $v);
				break;
			default:
				$this->error("Unknown episode parameter $k");
				return FALSE;
			}
		}
		return TRUE;
	}
	
	public function getEpisode($id) {
		$episode = $this->getElement("/tvscraper/tvshow/season/episode[@id='$id']");
		if ($episode === FALSE) {
			$this->error("Can't fine unique episode $id");
			return FALSE;
		}
	
		$res = $this->getElementAttributes($episode);
		$res['season'] = $episode->parentNode->getAttribute('id');
			
		return $res;
	}

	public function getEpisodeFromIndex($showId, $season, $episode) {
		$episode = $this->getElement("/tvscraper/tvshow[@id='$showId']/season[@n='$season']/episode[@n='$episode']");	
		if ($episode === FALSE) return FALSE;
		return $this->getEpisode($episode->getAttribute('id'));
	}
	
	public function getSeasonEpisodes($seasonId) {
		$episodes = $this->xPath->query("/tvscraper/tvshow/season[@id='$seasonId']/episode");
		$res = array();
	
		for ($i = 0; $i < $episodes->length; $i++) {
			$res[] = $this->getEpisode($episodes->item($i)->getAttribute('id'));
		}
	
		return $res;
	}
	

	// SCRAPER
	
	public function addScraper($rootId, $p) {
	
		$newId = $this->addElement('scraper', "/tvscraper/tvshow/season[@id='$rootId']");
		if ($newId == NULL) {
			$newId = $this->addElement('scraper', "/tvscraper/tvshow[@id='$rootId']");
			if ($newId == NULL) {
				$this->error("Could not find TV show or season with id $rootId. Can't create scraper.");
				return FALSE;
			}
		}
	
		if (! $this->setScraper($newId, $p)) {
			$this->removeScraper($newId);
			return FALSE;
		}
		return $this->getScraper($newId);
	}
	
	public function removeScraper($id) {
		$this->log("Removing scraper $id...");
		if ($this->removeElement("/tvscraper/tvshow//scraper[@id='$id']")) {
			return TRUE;
		} else {
			$this->error("Can't remove scraper $id");
			return FALSE;
		}
	}
	
	public function setScraper($id, $p) {
		$this->log("Searching for scraper $id");
		$scraper = $this->getElement("/tvscraper/tvshow//scraper[@id='$id']");
		if ($scraper === FALSE) {
			$this->error("Could not find unique scraper $id");
			return FALSE;
		}
			
		foreach ($p as $k => $v) {
			switch ($k) {
			case 'uri':
			case 'source':
			case 'preference':
			case 'delay':
			case 'autoAdd':
			case 'notify':				
				$this->setElementTextAttribute($scraper, $k, $v);
				break;
			default:
				$this->error("Unknown scraper parameter $k");
				return FALSE;
			}
		}
	
		return TRUE;
	}
	
	public function getScraper($id) {
		$scraper = $this->getElement("/tvscraper/tvshow/scraper[@id='$id']");
		if ($scraper === FALSE) {
			$scraper = $this->getElement("/tvscraper/tvshow/season/scraper[@id='$id']");
			if ($scraper === FALSE) {
				$this->error("Could not find unique scraper $id");
				return FALSE;
			}
		}
		
		$res = $this->getElementAttributes($scraper);
		$parent = $scraper->parentNode;
		$res[$parent->tagName] = $parent->getAttribute('id');
		
		return $res;
	}

	
/*	public function getScraperData($id) {
		$scraper = $this->getElement("/tvscraper/tvshow//scraper[@id='$id']");
		if ($scraper === FALSE) return FALSE;
		
		$scraperData = $this->getElement("/tvscraper/tvshow//scraper[@id='$id']/scraper-data");
		if ($scraperData === FALSE) {
			$newId = $this->addElement('scraper-data', "/tvscraper/tvshow//scraper[@id='$id']");
			if ($newId == NULL) return FALSE;
			$scraperData = $this->getElement("/tvscraper/tvshow//scraper[@id='$id']/scraper-data");
		}

		return $scraperData;
	}*/

	
	public function getSeasonScrapers($seasonId) {
		$this->log("Searching scrapers for season $seasonId");
		$x = $this->xPath->query("/tvscraper/tvshow/season[@id='$seasonId']/scraper");
		$res = array();
		for ($i = 0; $i < $x->length; $i++) {
			$this->log("Searching scraper " . $x->item($i)->getAttribute('id'));
			$res[] = $this->getScraper($x->item($i)->getAttribute('id'));
		}
		return $res;
	}
	
	public function getActiveScrapers() {
		$x = $this->xPath->query("/tvscraper/tvshow/scraper");
		$res = array();
		for ($i = 0; $i < $x->length; $i++) {
			$res[] = $this->getScraper($x->item($i)->getAttribute('id'));
		}
		$x = $this->xPath->query("/tvscraper/tvshow/season[@status='watched']/scraper");
		for ($i = 0; $i < $x->length; $i++) {
			$res[] = $this->getScraper($x->item($i)->getAttribute('id'));
		}
		return $res;
	}
	
	public function getTVShowScrapers($showId) {
		$this->log("Searching scrapers for TV show $showId");
		$x = $this->xPath->query("/tvscraper/tvshow[@id='$showId']/scraper");
		$res = array();
		for ($i = 0; $i < $x->length; $i++) {
			$this->log("Searching scraper " . $x->item($i)->getAttribute('id'));
			$res[] = $this->getScraper($x->item($i)->getAttribute('id'));
		}
		return $res;
	}
	
	// FILE
	
	public function addFile($showId, $p) {
		$newId = $this->addElement('file', "/tvscraper/tvshow[@id='$showId']/season[@id='".$p['season']."']");
		if ($newId == NULL) {
			$this->error("Can't create new file for TV show $showId");
			return FALSE;
		}
	
		if (! $this->setFile($newId, $p)) {
			$this->removeFile($newId);
			return FALSE;
		}

		return $newId;
	}
	
	public function removeFile($id) {
		if ($this->removeElement("/tvscraper/tvshow/season/file[@id='$id']")) {
			return TRUE;
		} else {
			$this->error("Can't remove file $id");
			return FALSE;
		}
	}
	
	
	public function setFile($id, $p) {
		$file = $this->getElement("/tvscraper/tvshow/season/file[@id='$id']");
		if ($file === FALSE) {
			$this->error("Can't fine unique file $id");
			return FALSE;
		}
			
		foreach ($p as $k => $v) {
			switch ($k) {
			case 'uri':
			case 'season':
			case 'episode':
			case 'scraper':
			case 'pubDate':
			case 'type':
				$this->setElementTextAttribute($file, $k, $v);
				break;
			default:
				$this->error("Unknown file parameter $k");
				return FALSE;
			}
				
		}
	
		return TRUE;
	}
	
	public function getFile($id) {
		$file = $this->getElement("/tvscraper/tvshow/season/file[@id='$id']");
		if ($file === FALSE) {
			$this->error("Can't fine unique file $id");
			return FALSE;
		}
		
		$res = $this->getElementAttributes($file);
		return $res;
	}
	

	public function getFilesForEpisode($id) {
		$x = $this->xPath->query("/tvscraper/tvshow/season/file[@episode='$id']");
		$res = array();
		for ($i = 0; $i < $x->length; $i++) {
			$res[] = $this->getFile($x->item($i)->getAttribute('id'));
		}
		return $res;
	}
	
	public function getFilesForSeason($id) {
		$x = $this->xPath->query("/tvscraper/tvshow/season/file[@season='$id']");
		$res = array();
		for ($i = 0; $i < $x->length; $i++) {
			$res[] = $x->item($i)->getAttribute('id');
		}
		return $res;
	}
	
	public function getFilesForScraper($id) {
		$x = $this->xPath->query("/tvscraper/tvshow/season/file[@scraper='$id']");
		$res = array();
		for ($i = 0; $i < $x->length; $i++) {
			$res[] = $x->item($i)->getAttribute('id');
		}
		return $res;
	}
	
	public function getBestFileForEpisode($id) {
		$this->log("Checking best file for episode $id");

		$episode = $this->getEpisode($id);
		if ($episode === FALSE) return FALSE;

		$scrapers = $this->getSeasonScrapers($episode['season']);
		if ($scrapers === FALSE) return FALSE;

		/*$scrapersData = array();
		foreach ($scrapers as $scraperId) {
			$s = $this->getScraper($scraperId);
			if ($s === FALSE) return FALSE;
			$scrapersData[] = $s;
		}*/

		//usort($scrapersData, function ($a, $b) {
		usort($scrapers, function ($a, $b) {
			if (!isset($b['preference']) && !isset($a['preference'])) return 0;
			else if (!isset($a['preference'])) return -1;
			else if (!isset($b['preference'])) return 1;
			else return $a['preference'] - $b['preference'];
		});

		$best = NULL;
		$lastPref = NULL;

		//foreach ($scrapersData as $s) {
		foreach ($scrapers as $s) {
			$this->log("Checking files for scraper " . $s['id']);

			if (isset($s['preference'])) {
				if ($lastPref != NULL && $lastPref < $s['preference'] && $best != NULL) {
					$this->log("File already found and scraper preference lower. End.");
					break;
				}
				else $lastPref = $s['preference'];
			}

			$q = "/tvscraper/tvshow/season/file[@episode='$id' and @scraper='". $s['id']. "'";
		    if (isset($s['delay'])) $q .= " and @pubDate <= '" . (time() - $s['delay']) . "'";
			$q .= "]";
			
			$x = $this->xPath->query($q);

			for ($i = 0; $i < $x->length; $i++) {
				
				// TODO how do we handle subtitiles??
				
				$linkData = parseED2KURI($x->item($i)->getAttribute('uri'));
				if ($linkData === FALSE) {
					$this->log("Invalid file " . $x->item($i)->getAttribute('id'));
				} else {
					if (preg_match('/\.srt$/', $linkData['fileName'])) {
						$this->log("File " . $x->item($i)->getAttribute('id') . " is a subtitle, skipping...");
					} else {
						if ($best === NULL || ($x->item($i)->getAttribute('pubDate') < $best->getAttribute('pubDate'))) {
							$best = $x->item($i);
							$this->log("Found elder file " . $x->item($i)->getAttribute('id'));
						} else {
							$this->log("Found more recent file " . $best->getAttribute('id'));
						}
					}
				}
			}
		}
		
		// TODO: Check orphan files (files with no scraper or with removed scraper) ?
		
		return $best === NULL ? NULL : $this->getFile($best->getAttribute('id'));
		
	}	
	
	public function getBestFilesForSeason($id) {
		$this->log("Checking best file for season $id");

		$season = $this->getSeason($id);
		if ($season === FALSE) return FALSE;

		$scrapers = $this->getSeasonScrapers($id);
		if ($scrapers === FALSE) return FALSE;

		/*$scrapersData = array();
		foreach ($scrapers as $scraperId) {
			$s = $this->getScraper($scraperId);
			if ($s === FALSE) return FALSE;
			$scrapersData[] = $s;
		}*/

		usort($scrapers, function ($a, $b) {
			if (!isset($b['preference']) && !isset($a['preference'])) return 0;
			else if (!isset($a['preference'])) return -1;
			else if (!isset($b['preference'])) return 1;
			else return $a['preference'] - $b['preference'];
		});

		$best = array();
		$lastPref = array();

		foreach ($scrapers as $s) {
			$this->log("Checking files for scraper " . $s['id']);

			$q = "/tvscraper/tvshow/season/file[@season='$id' and @scraper='". $s['id']. "'";
			if (isset($s['delay'])) $q .= " and @pubDate <= '" . (time() - $s['delay']) . "'";
			$q .= "]";

			$this->log("Query for candidate files: $q");

			$x = $this->xPath->query($q);


			for ($i = 0; $i < $x->length; $i++) {
				// TODO how do we handle subtitiles??
			
				$file = $x->item($i);		
				if (strlen($file->getAttribute('type')) == 0 || $file->getAttribute('type') == 'ed2k') {
					$linkData = parseED2KURI($file->getAttribute('uri'));
					if ($linkData === FALSE) {
						$this->log("Invalid file " . $file->getAttribute('id'));
						continue;
					} else if (preg_match('/\.srt$/', $linkData['fileName'])) {
						$this->log("File " . $file->getAttribute('id') . " is a subtitle, skipping...");
						continue;
					}
				}
				$episodeId = $file->getAttribute('episode');

				if (isset($s['preference'])) {
					if (isset($lastPref[$episodeId]) && $lastPref[$episodeId] < $s['preference'] && isset($best[$episodeId])) {
						$this->log("File already found for this episode and scraper preference lower. Next.");
						continue;
					} else {
						$lastPref[$episodeId] = $s['preference'];
					}
				}
						
				if (! isset($best[$episodeId]) || $file->getAttribute('pubDate') < $best[$episodeId]->getAttribute('pubDate')) {
					$best[$episodeId] = $file;
					$this->log("Found elder file " . $file->getAttribute('id') . " for episode " . $file->getAttribute('episode'));
				} else {
					$this->log("Found more recent file " . $file->getAttribute('id') . " for episode " . $file->getAttribute('episode'));
				}
			}
		}


		// TODO: Check orphan files (files with no scraper or with removed scraper) ?
		
		$res = array();
		foreach ($best as $b) {
			// Checking for files from the same scraper published later (proper-repack)
			
			$fileId = $b->getAttribute('id');
			$episodeId = $b->getAttribute('episode');
			$scraperId = $b->getAttribute('scraper');
			$pubDate = $b->getAttribute('pubDate');
			$files = $this->getFilesForEpisode($episodeId);

			foreach ($files as $f) {
				if ($f['scraper'] == $scraperId && $f['pubDate'] > $pubDate) {
					$this->log("Found newer file " . $f['id'] . " from the same scraper as $fileId. Swapping files...");
					$pubDate = $f['pubDate'];
					$fileId = $f['id'];
				}
			}

			$res[] = $this->getFile($fileId);
		}
		return $res;
	}

	
	// SCRAPED SEASON
	
	public function addScrapedSeason($scraperId, $p) {
	
		$newId = $this->addElement('scrapedSeason', "/tvscraper/tvshow/scraper[@id='$scraperId']");
		if ($newId == NULL) {
			$this->error("Could create scraper season for scraper $scraperId");
			return FALSE;
		}
	
		if (! $this->setScrapedSeason($newId, $p)) {
			$this->removeScrapedSeason($newId);
			return FALSE;
		}
		return $newId;
	}
	
	public function removeScrapedSeason($id) {
		$this->log("Removing scraped season $id...");
		if ($this->removeElement("/tvscraper/tvshow/scraper/scrapedSeason[@id='$id']")) {
			return TRUE;
		} else {
			$this->error("Can't remove scraped season $id");
			return FALSE;
		}
	}
	
	public function setScrapedSeason($id, $p) {
		$this->log("Searching for scraped season $id");
		$scrapedSeason = $this->getElement("/tvscraper/tvshow/scraper/scrapedSeason[@id='$id']");
		if ($scrapedSeason === FALSE) {
			$this->error("Could not find unique scraped season $id");
			return FALSE;
		}
			
		foreach ($p as $k => $v) {
			switch ($k) {
				case 'uri':
				case 'n':
				case 'hide':
				case 'tbn':
					$this->setElementTextAttribute($scrapedSeason, $k, $v);
					break;
				default:
					$this->error("Unknown scraped season parameter $k");
					return FALSE;
			}
		}
	
		return TRUE;
	}
	
	public function getScrapedSeason($id) {
		$scrapedSeason = $this->getElement("/tvscraper/tvshow/scraper/scrapedSeason[@id='$id']");
		if ($scrapedSeason === FALSE) {
			$this->error("Could not find unique scraped season $id");
			return FALSE;
		}
	
		$res = $this->getElementAttributes($scrapedSeason);
		$res['scraper'] = $scrapedSeason->parentNode->getAttribute('id');
		$res['source'] = $scrapedSeason->parentNode->getAttribute('source');
	
		return $res;
	}

	public function getScrapedSeasons($showId) {
		$scrapedSeasons = $this->xPath->query("/tvscraper/tvshow[@id='$showId']/scraper/scrapedSeason");
		$res = array();

		for ($i = 0; $i < $scrapedSeasons->length; $i++) {
			$res[] = $this->getScrapedSeason($scrapedSeasons->item($i)->getAttribute('id'));
		}

		return $res;
	}
	
	public function getScrapedSeasonsTBN() {
		$scrapedSeasons = $this->xPath->query("/tvscraper/tvshow/scraper/scrapedSeason[@tbn='1']");
		$res = array();
	
		for ($i = 0; $i < $scrapedSeasons->length; $i++) {
			$res[] = $this->getScrapedSeason($scrapedSeasons->item($i)->getAttribute('id'));
		}
	
		return $res;
	}
	
	
	public function getScrapedSeasonFromUri($scraperId, $uri) {
		$scrapedSeason = $this->getElement("/tvscraper/tvshow/scraper[@id='$scraperId']/scrapedSeason[@uri='$uri']");
		if ($scrapedSeason === FALSE) {
			// $this->error("Could not find unique scraped season with URI $uri for scraper $id");
			return NULL;
		}
	
		return $this->getScrapedSeason($scrapedSeason->getAttribute('id'));
	}
	
	public function createSeasonScraperFromScraped($id) {
		$scrapedSeason = $this->getScrapedSeason($id);
		if ($scrapedSeason === FALSE) {
			$this->error("Could not find unique scraped season $id");
			return FALSE;
		}
		
		$scraper = $this->getScraper($scrapedSeason['scraper']);
		if ($scraper === FALSE) {
			$this->error("Could not find unique scraper " . $scrapedSeason['scraper']);
			return FALSE;
		}
		
		$season = $this->getSeasonFromN($scraper['tvshow'], $scrapedSeason['n']);
		
		if ($season == NULL) {
			$this->log("Season $n does not exist yet. Creating...");
			$seasonId = $this->addSeason($scraper['tvshow'], array('n' => $scrapedSeason['n'], 'status' => 'watched'));
			if ($seasonId === FALSE) return FALSE;
			
			$season = $this->getSeason($seasonId);
			if ($season === FALSE) return FALSE;
		}
		
		$this->log("Adding new scraper to season " . $season['id']);
		$scraperId = $this->addScraper($season['id'], array(
				'uri' => $scrapedSeason['uri'],
				'source' => $scraper['source']
		));

		if ($scraperId != FALSE) {
			$this->setScrapedSeason($id, array('hide' => '1'));
		}
		
		return $scraperId;
		
	}
	
	
	
}

?>