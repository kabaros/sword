<?php

/**
 * @file classes/sword/OJSSwordDeposit.inc.php
 *
 * Copyright (c) 2014-2018 Simon Fraser University
 * Copyright (c) 2003-2018 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class OJSSwordDeposit
 * @ingroup plugins_generic_sword_classes
 *
 * @brief Class providing a SWORD deposit wrapper for OJS submissions
 */

require_once dirname(__FILE__) . '/../lib/swordappv2/swordappclient.php';
require_once dirname(__FILE__) . '/../lib/swordappv2/swordappentry.php';
require_once dirname(__FILE__) . '/../lib/swordappv2/packager_mets_swap.php';

class OJSSwordDeposit {
	/** @var SWORD deposit METS package */
	protected $_package = null;

	/** @var Complete path and directory name to use for package creation files */
	protected $_outPath = null;

	/** @var Journal */
	protected $_context = null;

	/** @var Section */
	protected $_section = null;

	/** @var Issue */
	protected $_issue = null;

	/** @var Article */
	protected $_article = null;

	/**
	 * Constructor.
	 * Create a SWORD deposit object for an OJS article.
	 * @param $article Article
	 */
	public function __construct($article) {
		// Create a directory for deposit contents
		$this->_outPath = tempnam('/tmp', 'sword');
		unlink($this->_outPath);
		mkdir($this->_outPath);
		mkdir($this->_outPath . '/files');

		// Create a package
		$this->_package = new PackagerMetsSwap(
			$this->_outPath,
			'files',
			$this->_outPath,
			'deposit.zip'
		);

		$journalDao = DAORegistry::getDAO('JournalDAO');
		error_log('$article->getContextId() => '.$article->getContextId());
		$this->_context = $journalDao->getById($article->getContextId());

		$sectionDao = DAORegistry::getDAO('SectionDAO');
		$this->_section = $sectionDao->getById($article->getSectionId());

		$publishedArticleDao = DAORegistry::getDAO('PublishedArticleDAO');
		$publishedArticle = $publishedArticleDao->getByArticleId($article->getId());

		$issueDao = DAORegistry::getDAO('IssueDAO');
		if ($publishedArticle) {
			$this->_issue = $issueDao->getById($publishedArticle->getIssueId());
			$this->_article = $publishedArticle;
		}
	}

	/**
	 * Register the article's metadata with the SWORD deposit.
	 * @param $request PKPRequest
	 */
	public function setMetadata($request) {
		$this->_package->setCustodian($this->_context->getContactName());
		$this->_package->setTitle(html_entity_decode($this->_article->getLocalizedTitle(), ENT_QUOTES, 'UTF-8'));
		$this->_package->setAbstract(html_entity_decode(strip_tags($this->_article->getLocalizedAbstract()), ENT_QUOTES, 'UTF-8'));
		$this->_package->setType($this->_section->getLocalizedIdentifyType());

		// The article can be published or not. Support either.
		if (is_a($this->_article, 'PublishedArticle')) {
			$doi = $this->_article->getStoredPubId('doi');
			if ($doi !== null) $this->_package->setIdentifier($doi);
		}

		foreach ($this->_article->getAuthors() as $author) {
			$creator = $author->getFullName(true);
			$affiliation = $author->getLocalizedAffiliation();
			if (!empty($affiliation)) $creator .= "; $affiliation";
			$this->_package->addCreator($creator);
		}

// 		TODO check with Nate why cistation style plugin is not enabled by default
// 		also which citation style is DSpace expecting?
// 		if (is_a($this->_article, 'PublishedArticle')) {
// 			$plugin = PluginRegistry::getPlugin('generic', 'citationstylelanguageplugin');
// 			if ($plugin) {
// 				$citation = $plugin->getCitation($request, $this->_article, 'bibtex');
// 				$this->_package->setCitation(html_entity_decode(strip_tags($citation), ENT_QUOTES, 'UTF-8'));
// 			}
// 		}
	}

	/**
	 * Add a file to a package. Used internally.
	 */
	public function _addFile($submissionFile) {
		$targetFilename = $this->_outPath . '/files/' . $submissionFile->getLocalizedName();
		copy($submissionFile->getFilePath(), $targetFilename);
		$this->_package->addFile($submissionFile->getLocalizedName(), $submissionFile->getFileType());
	}

	/**
	 * Add all article galleys to the deposit package.
	 */
	public function addGalleys() {
		foreach ($this->_article->getGalleys() as $galley) {
			$this->_addFile($galley);
		}
	}

	/**
	 * Add the single most recent editorial file to the deposit package.
	 * @return boolean true iff a file was successfully added to the package
	 */
	public function addEditorial() {
		$submissionFileDao = DAORegistry::getDAO('SubmissionFileDAO');
		// Move through stages in reverse order and try to use them.
		foreach (array('SUBMISSION_FILE_PRODUCTION_READY', 'SUBMISSION_FILE_COPYEDIT', 'SUBMISSION_FILE_REVIEW_FILE','SUBMISSION_FILE_SUBMISSION') as $subFileStage) {
			$submissionFiles = $submissionFileDao->getLatestRevisions($this->_article->getId(), $subFileStage);
			var_dump($submissionFiles);
			if (!is_null($submissionFiles) && count($submissionFiles)) {
				// TODO : what to do with other files when nultiple files are returned
				$submissionFile = array_shift($submissionFiles);
				var_dump($submissionFile);
				$this->_addFile($submissionFile);
				return true;
			}
		}

		// TODO when the above didn't work, SWORD plugin for OJS2 was trying "the editor version"
		// how does it apply to OJS3? (I found no SectionEditorSubmissionDAO in the code base)
		return false;
	}

	/**
	 * Build the package.
	 */
	public function createPackage() {
		return $this->_package->create();
	}

	/**
	 * Deposit the package.
	 * @param $url string SWORD deposit URL
	 * @param $username string SWORD deposit username (i.e. email address for DSPACE)
	 * @param $password string SWORD deposit password
	 */
	public function deposit($url, $username, $password) {
		$client = new SWORDAPPClient();
		error_log(print_r([$url, $username, $password, $this->_outPath],true));
		$response = $client->deposit(
			$url, $username, $password,
			'',
			$this->_outPath . '/deposit.zip',
			'http://purl.org/net/sword/package/METSDSpaceSIP',
			'application/zip', false, true
		);
		return $response;
	}

	/**
	 * Clean up after a deposit, i.e. removing all created files.
	 */
	public function cleanup() {
		import('lib.pkp.classes.file.FileManager');
		$fileManager = new FileManager();
		$fileManager->rmtree($this->_outPath);
	}
}

?>