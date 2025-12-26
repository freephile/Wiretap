<?php

class Wiretap {

	static $referers = null;
	static $called = false;

	/**
	 * Get a database connection.
	 *
	 * MediaWiki 1.43+ only.
	 *
	 * @param string|int $index 'primary'|'replica' or a DB_* index constant value
	 * @return mixed Database connection (IDatabase)
	 */
	public static function getDB( $index ) {
		$provider = \MediaWiki\MediaWikiServices::getInstance()->getConnectionProvider();
		if ( $index === 'primary' ) {
			return $provider->getPrimaryDatabase();
		}
		if ( $index === 'replica' ) {
			return $provider->getReplicaDatabase();
		}
		return $provider->getConnection( $index );
	}

	/**
	 *
	 *
	 *
	 **/
	public static function updateTable( &$title, &$article, &$output, &$user, $request, $mediaWiki ) {

		$output->enableClientCache( false );
		$output->addMeta( 'http:Pragma', 'no-cache' );

		global $wgRequestTime, $egWiretapCurrentHit;

		$now = time();
		$hit = array(
			'page_id' => $title->getArticleID(),
			'page_name' => $title->getFullText(),
			'user_name' => $user->getName(),
			'hit_timestamp' => wfTimestampNow(),

			'hit_year' => date('Y',$now),
			'hit_month' => date('m',$now),
			'hit_day' => date('d',$now),
			'hit_hour' => date('H',$now),
			'hit_weekday' => date('w',$now), // 0' => sunday, 1=monday, ... , 6=saturday

			'page_action' => $request->getVal( 'action' ),
			'oldid' => $request->getVal( 'oldid' ),
			'diff' => $request->getVal( 'diff' ),

		);

		$hit['referer_url'] = isset($_SERVER["HTTP_REFERER"]) ? $_SERVER["HTTP_REFERER"] : null;
		$hit['referer_title'] = self::getRefererTitleText( $request->getVal('refererpage') );

		// @TODO: this is by no means the ideal way to do this...but it'll do for now...
		$egWiretapCurrentHit = $hit;

		return true;

	}

	public static function recordInDatabase (  ) { // could have param &$output
		global $wgRequestTime, $egWiretapCurrentHit, $wgReadOnly;

		if ( $wgReadOnly || ! isset( $egWiretapCurrentHit ) || ! isset( $egWiretapCurrentHit['page_id'] ) ) {
			return true; // for whatever reason the poorly-named "updateTable" method was not called; abort.
		}

		// calculate response time now, in the last hook (that I know of).
		$egWiretapCurrentHit['response_time'] = round( ( microtime( true ) - $wgRequestTime ) * 1000 );

		$dbw = self::getDB( 'primary' );
		$dbw->insert(
			'wiretap',
			$egWiretapCurrentHit,
			__METHOD__
		);

		global $wgWiretapAddToPeriodCounter, $wgWiretapAddToAlltimeCounter;

		if ( $wgWiretapAddToAlltimeCounter ) {
			self::upsertHit( $egWiretapCurrentHit['page_id'], 'all' );
		}

		if ( $wgWiretapAddToPeriodCounter ) {
			self::upsertHit( $egWiretapCurrentHit['page_id'], 'period' );
		}

		return true;
	}

	public static function upsertHit ( $pageId, $type='all' ) {

		if ( $type === 'period' ) {
			$table = 'wiretap_counter_period';
		}
		else if ( $type === 'all' ) {
			$table = 'wiretap_counter_alltime';
		}
		else return;

		$dbw = self::getDB( 'primary' );
		$dbw->upsert(
			$table,
			array(
				'page_id' => $pageId,
				'count' => 1,
				'count_unique' => 1,
			),
			array( 'page_id' ),
			array(
				'count = count + 1',
				// does not guess this is a new unique hit
				// need to run maint script for that
				// 'count_unique = count_unique + 1',
			),
			__METHOD__
		);

		return;

	}

	public static function updateDatabase( DatabaseUpdater $updater ) {
		global $wgDBprefix;

		$wiretapTable = $wgDBprefix . 'wiretap';
		$wiretapCounterTable = $wgDBprefix . 'wiretap_counter_period';
		$wiretapLegacyTable = $wgDBprefix . 'wiretap_legacy';
		$schemaDir = __DIR__ . '/schema';

		$updater->addExtensionTable(
			$wiretapTable,
			"$schemaDir/Wiretap.sql"
		);
		$updater->addExtensionField(
			$wiretapTable,
			'response_time',
			"$schemaDir/patch-1-response-time.sql"
		);
		$updater->addExtensionTable(
			$wiretapCounterTable,
			"$schemaDir/patch-2-page-counter.sql"
		);
		$updater->addExtensionTable(
			$wiretapLegacyTable,
			"$schemaDir/patch-3-legacy-counter.sql"
		);
		return true;
	}

	/**
	 *	See WebRequest::getPathInfo() for ideas/info
	 *  Make better use of: $wgScript, $wgScriptPath, $wgArticlePath;
	 *
	 *  Other recommendations:
	 *	 wfSuppressWarnings();
	 *	 $a = parse_url( $url );
	 *	 wfRestoreWarnings();
	 **/
	public static function getRefererTitleText ( $refererpage=null ) {

		// global $egWiretapReferers;
		global $wgScriptPath;

		if ( $refererpage )
			return $refererpage;
		else if ( ! isset($_SERVER["HTTP_REFERER"]) )
			return null;

		$wikiBaseUrl = WebRequest::detectProtocol() . '://' . $_SERVER['HTTP_HOST'] . $wgScriptPath;

		// if referer URL starts
		if ( strpos($_SERVER["HTTP_REFERER"], $wikiBaseUrl) === 0 ) {

			$questPos = strpos( $_SERVER['HTTP_REFERER'], '?' );
			$hashPos = strpos( $_SERVER['HTTP_REFERER'], '#' );

			if ($hashPos !== false) {
				$queryStringLength = $hashPos - $questPos;
				$queryString = substr($_SERVER['HTTP_REFERER'], $questPos+1, $queryStringLength);
			} else {
				$queryString = substr($_SERVER['HTTP_REFERER'], $questPos+1);
			}

			$query = array();
			parse_str( $queryString, $query );

			return isset($query['title']) ? $query['title'] : false;

		}
		else
			return false;

	}

	/**
	 * Add a viewcount footer item.
	 *
	 * @param Skin $skin
	 * @param string $key
	 * @param array &$footerlinks
	 * @return bool
	 */
	public static function onSkinAddFooterLinks( Skin $skin, $key, &$footerlinks ) {
		global $wgDisableCounters;

		if ( $wgDisableCounters ) {
			return true;
		}

		// Only add to the "info" footer section.
		if ( $key !== 'info' ) {
			return true;
		}

		/* Without this check multiple lines can be added to the page. */
		if ( self::$called ) {
			return true;
		}
		self::$called = true;

		$viewcount = Wiretap::getCount( $skin->getTitle() );
		if ( $viewcount ) {
			$footerlinks['viewcount'] = $skin->msg( 'wiretap-viewcount' )->numParams(
				$viewcount->page + $viewcount->redirect,
				$viewcount->redirect
			)->parse();
		}

		return true;
	}

	// eventually add a $period param allowing to specify a
	static public function getCount ( Title $title ) {

		$counts = (object)array( 'page' => 0, 'redirect' => 0 );

		$id = $title->getArticleID();
		if ( ! $id ) {
			return $counts;
		}

		$findIDs = array( $id );
		$redirects = $title->getRedirectsHere();
		foreach( $redirects as $r ) {
			$findIDs[] = $r->getArticleID();
		}

		$dbr = self::getDB( 'replica' );
		$result = $dbr->select(
			array(
				'w' => 'wiretap_counter_alltime',
				'leg' => 'wiretap_legacy'
			),
			array(
				'id' => 'w.page_id',
				'legacy_counter' => 'legacy_counter',
				'wiretap_counter' => 'w.count'
			),
			array( 'w.page_id' => $findIDs ),
			__METHOD__,
			null,
			array(
				'leg' => array( 'LEFT JOIN', 'leg.legacy_id = w.page_id' )
			)
		);

		// $pageHits = 0;
		// $redirectHits = 0;
		while( $page = $result->fetchObject() ) {
			$total = intval( $page->legacy_counter ) + intval( $page->wiretap_counter );
			if ( $page->id == $id ) {
				$counts->page = $total;
			}
			else {
				$counts->redirect += $total;
			}
		}

		return $counts;
	}

}
