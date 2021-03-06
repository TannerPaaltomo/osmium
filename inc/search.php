<?php
/* Osmium
 * Copyright (C) 2012, 2013 Romain "Artefact2" Dalmaso <artefact2@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace Osmium\Search;

const SPHINXQL_PORT = 24492;

function get_link() {
	static $link = null;
	if($link === null) {
		$link = mysqli_connect('127.0.0.1', 'root', '', '', SPHINXQL_PORT);
		if(!$link) {
			\Osmium\fatal(500, 'Could not connect to Sphinx.');
		}
	}

	return $link;
}

function query_select_searchdata($cond, array $params = array()) {
	return \Osmium\Db\query_params('SELECT loadoutid, restrictedtoaccountid, restrictedtocorporationid,
	restrictedtoallianceid, tags, modules, author, name, description,
	shipid, upvotes, downvotes, score, ship, groups, creationdate,
	updatedate, evebuildnumber, comments FROM
	osmium.loadoutssearchdata '.$cond, $params);
}

function query($q) {
	return mysqli_query(get_link(), $q);
}

function escape($string) {
	/* Taken from the GPL PHP API of Sphinx */
	$from = array ('\\', '(',')','|','-','!','@','~',"'",'&', '/', '^', '$', '=');
	$to   = array ('\\\\', '\(','\)','\|','\-','\!','\@','\~','\\\'', '\&', '\/', '\^', '\$', '\=');
	return str_replace ($from, $to, $string);
}

function unindex($loadoutid) {
	query('DELETE FROM osmium_loadouts WHERE id = '.$loadoutid);
}

function index($loadout) {
	unindex($loadout['loadoutid']);
	
	return query(
		'INSERT INTO osmium_loadouts (id, restrictedtoaccountid,
		restrictedtocorporationid, restrictedtoallianceid, shipid,
		upvotes, downvotes, score, creationdate, updatedate, build,
		comments, ship, groups, author, name, description, tags,
		modules) VALUES ('
		.$loadout['loadoutid'].','
		.$loadout['restrictedtoaccountid'].','
		.$loadout['restrictedtocorporationid'].','
		.$loadout['restrictedtoallianceid'].','
		.$loadout['shipid'].','
		.$loadout['upvotes'].','
		.$loadout['downvotes'].','
		.$loadout['score'].','
		.$loadout['creationdate'].','
		.$loadout['updatedate'].','
		.$loadout['evebuildnumber'].','
		.$loadout['comments'].','
		.'\''.escape($loadout['ship']).'\','
		.'\''.escape($loadout['groups']).'\','
		.'\''.escape($loadout['author']).'\','
		.'\''.escape($loadout['name']).'\','
		.'\''.escape($loadout['description']).'\','
		.'\''.escape($loadout['tags']).'\','
		.'\''.escape($loadout['modules']).'\''
		.')'
	);
}

function get_search_query($search_query) {
	$accountids = array(0);
	$corporationids = array(0);
	$allianceids = array(0);

	if(\Osmium\State\is_logged_in()) {
		$a = \Osmium\State\get_state('a');
		$accountids[] = intval($a['accountid']);

		if($a['apiverified'] === 't') {
			$corporationids[] = intval($a['corporationid']);
			if($a['allianceid'] > 0) $allianceids[] = intval($a['allianceid']);
		}
	}

	return 'SELECT id FROM osmium_loadouts WHERE MATCH(\''.escape($search_query).'\') AND restrictedtoaccountid IN ('.implode(',', $accountids).') AND restrictedtocorporationid IN ('.implode(',', $corporationids).') AND restrictedtoallianceid IN ('.implode(',', $allianceids).')';
}

function get_search_ids($search_query, $more_cond = '', $offset = 0, $limit = 1000) {
	$q = query(get_search_query($search_query).' '.$more_cond.' LIMIT '.$offset.','.$limit);
	if($q === false) return false; /* Invalid query */

	$ids = array();
	while($row = fetch_row($q)) {
		$ids[] = $row[0];
	}

	return $ids;
}

function get_total_matches($search_query, $more_cond = '') {
	$q = query(get_search_query($search_query).' '.$more_cond);
	if($q === false) return 0;

	$meta = get_meta();
	return $meta['total_found'];
}

function fetch_assoc($result) {
	return mysqli_fetch_assoc($result);
}

function fetch_row($result) {
	return mysqli_fetch_row($result);
}

function get_meta() {
	$q = query('SHOW META;');
	$meta = array();

	while($r = fetch_row($q)) {
		$meta[$r[0]] = $r[1];
	}

	return $meta;
}

function print_pretty_results($relative, $query, $more = '', $paginate = false, $perpage = 20, $pagename = 'p', $message = 'No loadouts matched your query.') {
	$total = get_total_matches($query, $more);
	if($paginate) {
		$offset = \Osmium\Chrome\paginate($pagename, $perpage, $total, $pageresult, $pageinfo);
	} else $offset = 0;

	$ids = \Osmium\Search\get_search_ids($query, $more, $offset, $perpage);
	if($ids === false) {
		echo "<p class='placeholder'>The supplied query is invalid.</p>\n";
		return;
	}

	if($paginate) {
		echo $pageinfo;
		echo $pageresult;
		print_loadout_list($ids, $relative, $offset, $message);
		echo $pageresult;
	} else {
		print_loadout_list($ids, $relative, $offset, $message);
	}
}

function print_loadout_list(array $ids, $relative, $offset = 0, $nothing_message = 'No loadouts.') {
	if($ids === array()) {
		echo "<p class='placeholder'>".$nothing_message."</p>\n";
		return;		
	}

	$orderby = implode(',', array_map(function($id) { return 'loadouts.loadoutid='.$id.' DESC'; }, $ids));
	$in = implode(',', $ids);
	$first = true;
    
	$lquery = \Osmium\Db\query('SELECT loadouts.loadoutid, privatetoken, latestrevision, viewpermission, visibility, hullid, typename, fittings.creationdate, updatedate, name, fittings.evebuildnumber, accounts.accountid, nickname, apiverified, charactername, characterid, corporationname, corporationid, alliancename, allianceid, loadouts.accountid, taglist, reputation, votes, upvotes, downvotes, COALESCE(lcc.count, 0) AS comments
FROM osmium.loadouts 
JOIN osmium.loadoutslatestrevision ON loadouts.loadoutid = loadoutslatestrevision.loadoutid 
JOIN osmium.loadouthistory ON (loadoutslatestrevision.latestrevision = loadouthistory.revision AND loadouthistory.loadoutid = loadouts.loadoutid) 
JOIN osmium.fittings ON fittings.fittinghash = loadouthistory.fittinghash 
JOIN osmium.accounts ON accounts.accountid = loadouts.accountid 
JOIN eve.invtypes ON hullid = invtypes.typeid
JOIN osmium.loadoutupdownvotes ON loadoutupdownvotes.loadoutid = loadouts.loadoutid
LEFT JOIN osmium.fittingaggtags ON fittingaggtags.fittinghash = loadouthistory.fittinghash
LEFT JOIN osmium.loadoutcommentcount lcc ON lcc.loadoutid = loadouts.loadoutid
WHERE loadouts.loadoutid IN ('.$in.') ORDER BY '.$orderby);

	while($loadout = \Osmium\Db\fetch_assoc($lquery)) {
		if($first === true) {
			$first = false;
			/* Only write the <ol> tag if there is at least one loadout */
			echo "<ol start='".($offset + 1)."' class='loadout_sr'>\n";
		}

		$uri = \Osmium\Fit\get_fit_uri($loadout['loadoutid'], $loadout['visibility'], $loadout['privatetoken']);

		echo "<li>\n<a href='$relative/".$uri."'><img src='http://image.eveonline.com/Render/".$loadout['hullid']."_256.png' alt='".$loadout['typename']."' /></a>\n";

		$votes = (abs($loadout['votes']) == 1) ? 'vote' : 'votes';
		echo "<div class='lscore'><span title='".$loadout['upvotes']
			." upvote(s), ".$loadout['downvotes']." downvote(s)'><strong>"
			.$loadout['votes']."</strong><br /><small>".$votes."</small></span></div>\n";

		$uri = \Osmium\Fit\get_fit_uri($loadout['loadoutid'], $loadout['visibility'], $loadout['privatetoken']);

		$comments = ($loadout['comments'] == 1) ? 'comment' : 'comments';
		echo "<div class='ccount'><a href='$relative/".$uri."#comments'><span><strong>".$loadout['comments']."</strong><br /><small>".$comments."</small></span></a></div>\n";

		echo "<a href='$relative/".$uri."'>";
		\Osmium\Chrome\print_loadout_title($loadout['name'], $loadout['viewpermission'], $loadout['visibility'], $loadout, $relative);
		echo "</a>\n<br />\n<small><a href='$relative/search?q=".urlencode('@ship "'.$loadout['typename'].'"')."'>".$loadout['typename']."</a> loadout";
		echo " — ".\Osmium\Chrome\format_character_name($loadout, $relative);
		echo " (".\Osmium\Chrome\format_reputation($loadout['reputation']).")";
		echo " — revision #".$loadout['latestrevision'];
		echo " — ".date('Y-m-d', $loadout['updatedate'])." ("
			.\Osmium\Fit\get_closest_version_by_build($loadout['evebuildnumber'])['name']
			.")</small><br />\n";
      
		$tags = array_filter(explode(' ', $loadout['taglist']), function($tag) { return trim($tag) != ''; });
		if(count($tags) == 0) {
			echo "<em>(no tags)</em>";
		} else {
			echo "<ul class='tags'>\n".implode('', array_map(function($tag) use($relative) {
						$tag = trim($tag);
						return "<li><a href='$relative/search?q=".urlencode('@tags "'.$tag.'"')."'>$tag</a></li>\n";
					}, $tags))."</ul>\n";
		}
		echo "</li>\n";
	}

	if($first === false) {
		echo "</ol>\n";
	} else {
		echo "<p class='placeholder'>".$nothing_message."</p>\n";
	}
}

function get_search_cond_from_advanced() {
	if(!isset($_GET['build']) && !isset($_GET['op'])) {
		/* Use sane defaults, ie hide absurdly outdated loadouts by
		 * default */

		$versions = \Osmium\Fit\get_eve_db_versions();
		$cutoff = min(count($versions) - 1, 2);

		$_GET['op'] = 'gt';
		$_GET['build'] = array_values($versions)[$cutoff]['build'];
	}

	static $operators = array(
		'eq' => '=',
		'lt' => '<=',
		'gt' => '>=',
	);

	static $orderby = array(
		//"relevance" => "relevance", /* Does not match to an ORDER BY statement as this is the default */
		"creationdate" => "creation date",
		"score" => "score",
		"comments" => "comments",
	);

	$cond = '';
	if(isset($_GET['op']) && isset($_GET['build']) && isset($operators[$_GET['op']])) {
		$cond .= " AND build ".$operators[$_GET['op']]." ".((int)$_GET['build']);
	}

	if(isset($_GET['sort']) && isset($orderby[$_GET['sort']])) {
		$cond .= ' ORDER BY '.$_GET['sort'].' DESC';
	}

	return $cond;
}

/**
 * Print a basic seach form. Pre-fills the search form from $_GET data
 * if present.
 */
function print_search_form($uri = null, $relative = '.', $label = 'Search loadouts', $icon = null, $advanced = 'Advanced search', $placeholder = 'Search by name, description, ship, modules or tags…') {
	static $operands = array(
		"gt" => "or newer",
		"eq" => "exactly",
		"lt" => "or older",
	);

	static $orderby = array(
		"relevance" => "relevance",
		"creationdate" => "creation date",
		"score" => "score",
		"comments" => "comments",
	);

	if($icon === null) $icon = [ 2, 12, 64, 64 ];

	$val = '';
	if(isset($_GET['q']) && strlen($_GET['q']) > 0) {
		$val = "value='".htmlspecialchars($_GET['q'], ENT_QUOTES)."' ";
	}

	if($uri === null) {
		$uri = htmlspecialchars(explode('?', $_SERVER['REQUEST_URI'], 2)[0], ENT_QUOTES);
	}

	echo "<form method='get' action='{$uri}'>\n";
	echo "<h1><label for='search'>"
		.\Osmium\Chrome\sprite($relative, '', $icon[0], $icon[1], $icon[2], $icon[3], 64)
		.$label."</label></h1>\n";

	echo "<p>\n<input id='search' type='search' autofocus='autofocus' placeholder='{$placeholder}' name='q' $val/> <input type='submit' value='Go!' /><br />\n";

	if(isset($_GET['ad']) && $_GET['ad']) {
		echo "for \n";

		echo "<select name='build' id='build'>\n";
		foreach(\Osmium\Fit\get_eve_db_versions() as $v) {
			echo "<option value='".$v['build']."'";

			if(isset($_GET['build']) && (int)$_GET['build'] === $v['build']) {
				echo " selected='selected'";
			}

			echo ">".htmlspecialchars($v['name'])."</option>\n";
		}
		echo "</select>\n";

		echo "<select name='op' id='op'>\n";
		foreach($operands as $op => $label) {
			echo "<option value='$op'";

			if(isset($_GET['op']) && $_GET['op'] === $op) {
				echo " selected='selected'";
			}

			echo ">$label</option>\n";
		}
		echo "</select><br />\nsort by \n<select name='sort' id='sort'>\n";
		foreach($orderby as $sort => $label) {
			echo "<option value='{$sort}'";
			if(isset($_GET['sort']) && $_GET['sort'] === $sort) {
				echo " selected='selected'";
			}
			echo ">{$label}</option>\n";
		}
		echo "</select>\n<input type='hidden' name='ad' value='1' />\n";
	} else {
		$get = 'ad=1';
		foreach($_GET as $k => $v) {
			$get .= "&amp;".htmlspecialchars($k, ENT_QUOTES)."=".htmlspecialchars($v, ENT_QUOTES);
		}
		echo "<a href='{$uri}?{$get}'><small>{$advanced}</small></a>";
	}

	echo"</p>\n";
	echo "</form>\n";
}
