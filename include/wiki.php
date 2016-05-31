<?php
/**
 * @file include/wiki.php
 * @brief Wiki related functions.
 */

use \Zotlabs\Storage\GitRepo as GitRepo;
define ( 'WIKI_ITEM_RESOURCE_TYPE', 'wiki' );

function wiki_list($channel, $observer_hash) {
	$sql_extra = item_permissions_sql($channel['channel_id'], $observer_hash);
	$wikis = q("SELECT * FROM item WHERE resource_type = '%s' AND mid = parent_mid AND item_deleted = 0 $sql_extra", 
			dbesc(WIKI_ITEM_RESOURCE_TYPE)
	);
	// TODO: query db for wikis the observer can access. Return with two lists, for read and write access
	return array('wikis' => $wikis);
}

function wiki_page_list($resource_id) {
	// TODO: Create item table records for pages so that metadata like title can be applied
	$w = wiki_get_wiki($resource_id);
	if (!$w['path']) {
		return array('pages' => null);
	}
	$pages = array();
	if (is_dir($w['path']) === true) {
		$files = array_diff(scandir($w['path']), array('.', '..', '.git'));
		// TODO: Check that the files are all text files
		$pages = $files;
	}

	return array('pages' => $pages);
}

function wiki_init_wiki($channel, $name) {
	// Store the path as a relative path, but pass absolute path to mkdir
	$path = 'store/[data]/git/'.$channel['channel_address'].'/wiki/'.$name;
	if (!os_mkdir(__DIR__ . '/../' . $path, 0770, true)) {
		logger('Error creating wiki path: ' . $name);
		return null;
	}
	// Create GitRepo object 	
	$git = new GitRepo($channel['channel_address'], null, false, $name, __DIR__ . '/../' . $path);	
	if(!$git->initRepo()) {
		logger('Error creating new git repo in ' . $git->path);
		return null;
	}
	
	return array('path' => $path);
}

function wiki_create_wiki($channel, $observer_hash, $name, $acl) {
	$wikiinit = wiki_init_wiki($channel, $name);	
	if (!$wikiinit['path']) {
		notice('Error creating wiki');
		return array('item' => null, 'success' => false);
	}
	$path = $wikiinit['path'];
	// Generate unique resource_id using the same method as item_message_id()
	do {
		$dups = false;
		$resource_id = random_string();
		$r = q("SELECT mid FROM item WHERE resource_id = '%s' AND resource_type = '%s' AND uid = %d LIMIT 1", 
			dbesc($resource_id), 
			dbesc(WIKI_ITEM_RESOURCE_TYPE), 
			intval($channel['channel_id'])
		);
		if (count($r))
			$dups = true;
	} while ($dups == true);
	$ac = $acl->get();
	$mid = item_message_id();
	$arr = array();	// Initialize the array of parameters for the post
	$item_hidden = 0; // TODO: Allow form creator to send post to ACL about new game automatically
	$wiki_url = z_root() . '/wiki/' . $channel['channel_address'] . '/' . $name;
	$arr['aid'] = $channel['channel_account_id'];
	$arr['uid'] = $channel['channel_id'];
	$arr['mid'] = $mid;
	$arr['parent_mid'] = $mid;
	$arr['item_hidden'] = $item_hidden;
	$arr['resource_type'] = WIKI_ITEM_RESOURCE_TYPE;
	$arr['resource_id'] = $resource_id;
	$arr['owner_xchan'] = $channel['channel_hash'];
	$arr['author_xchan'] = $observer_hash;
	$arr['plink'] = z_root() . '/channel/' . $channel['channel_address'] . '/?f=&mid=' . $arr['mid'];
	$arr['llink'] = $arr['plink'];
	$arr['title'] = $name;		 // name of new wiki;
	$arr['allow_cid'] = $ac['allow_cid'];
	$arr['allow_gid'] = $ac['allow_gid'];
	$arr['deny_cid'] = $ac['deny_cid'];
	$arr['deny_gid'] = $ac['deny_gid'];
	$arr['item_wall'] = 1;
	$arr['item_origin'] = 1;
	$arr['item_thread_top'] = 1;
	$arr['item_private'] = intval($acl->is_private());
	$arr['verb'] = ACTIVITY_CREATE;
	$arr['obj_type'] = ACTIVITY_OBJ_WIKI;
	$arr['body'] = '[table][tr][td][h1]New Wiki[/h1][/td][/tr][tr][td][zrl=' . $wiki_url . ']' . $name . '[/zrl][/td][/tr][/table]';
	// Save the path using iconfig. The file path should not be shared with other hubs
	if (!set_iconfig($arr, 'wiki', 'path', $path, false)) {
		return array('item' => null, 'success' => false);
	}
	$post = item_store($arr);
	$item_id = $post['item_id'];

	if ($item_id) {
		proc_run('php', "include/notifier.php", "activity", $item_id);
		return array('item' => $arr, 'success' => true);
	} else {
		return array('item' => null, 'success' => false);
	}
}

function wiki_delete_wiki($resource_id) {

	$w = wiki_get_wiki($resource_id);
	$item = $w['wiki'];
	if (!$item || !$w['path']) {
		return array('item' => null, 'success' => false);
	} else {
		$drop = drop_item($item['id'], false, DROPITEM_NORMAL, true);
		$pathdel = rrmdir($w['path']);
		if ($pathdel) {
			info('Wiki files deleted successfully');
		}
		return array('item' => $item, 'success' => (($drop === 1 && $pathdel) ? true : false));
	}
}

function wiki_get_wiki($resource_id) {
	$item = q("SELECT * FROM item WHERE resource_type = '%s' AND resource_id = '%s' AND item_deleted = 0 limit 1", 
						dbesc(WIKI_ITEM_RESOURCE_TYPE), 
						dbesc($resource_id)
	);
	if (!$item) {
		return array('wiki' => null, 'path' => null);
	} else {
		$w = $item[0];
		//$object = json_decode($w['object'], true);
		$path = get_iconfig($w, 'wiki', 'path');
		if (!realpath(__DIR__ . '/../' . $path)) {
			return array('wiki' => null, 'path' => null);
		}
		// Path to wiki exists
		$abs_path = realpath(__DIR__ . '/../' . $path);
		return array('wiki' => $w, 'path' => $abs_path);
	}
}

function wiki_exists_by_name($uid, $name) {
	$item = q("SELECT id,resource_id FROM item WHERE resource_type = '%s' AND title = '%s' AND uid = '%s' AND item_deleted = 0 limit 1", 
						dbesc(WIKI_ITEM_RESOURCE_TYPE), 
						dbesc($name), 
						dbesc($uid)
					);
	if (!$item) {
		return array('id' => null, 'resource_id' => null);
	} else {
		return array('id' => $item[0]['id'], 'resource_id' => $item[0]['resource_id']);
	}
}

function wiki_get_permissions($resource_id, $owner_id, $observer_hash) {
	// TODO: For now, only the owner can edit
	$sql_extra = item_permissions_sql($owner_id, $observer_hash);
	$r = q("SELECT * FROM item WHERE resource_type = '%s' AND resource_id = '%s' $sql_extra LIMIT 1",
				dbesc(WIKI_ITEM_RESOURCE_TYPE), 
        dbesc($resource_id)
    );
	if(!$r) {
		return array('read' => false, 'write' => false, 'success' => true);
	} else {
		return array('read' => true, 'write' => false, 'success' => true);
	}
}

function wiki_create_page($name, $resource_id) {
	$w = wiki_get_wiki($resource_id);
	if (!$w['path']) {
		return array('page' => null, 'message' => 'Wiki not found.', 'success' => false);
	}
	$page_path = $w['path'] . '/' . $name;
	if (is_file($page_path)) {
		return array('page' => null, 'message' => 'Page already exists.', 'success' => false);
	}
	// Create file called $name in the path
	if(!touch($page_path)) {
		return array('page' => null, 'message' => 'Page file cannot be created.', 'success' => false);
	} else {
		return array('wiki' => $wikiname, 'message' => '', 'success' => true);
	}
	
}

function wiki_get_page_content($arr) {
	$page = ((array_key_exists('page',$arr)) ? $arr['page'] : '');
	// TODO: look for page resource_id and retrieve that way alternatively
	$wiki_resource_id = ((array_key_exists('wiki_resource_id',$arr)) ? $arr['wiki_resource_id'] : '');
	$w = wiki_get_wiki($wiki_resource_id);
	if (!$w['path']) {
		return array('content' => null, 'message' => 'Error reading wiki', 'success' => false);
	}
	$page_path = $w['path'].'/'.escape_tags(urlencode($page));
	if (is_readable($page_path) === true) {
		if(filesize($page_path) === 0) {
			$content = '';
		} else {
			$content = file_get_contents($page_path);
			if(!$content) {
				return array('content' => null, 'message' => 'Error reading page content', 'success' => false);
			}
		}		
		// TODO: Check that the files are all text files
		return array('content' => json_encode($content), 'message' => '', 'success' => true);
	}
}

function wiki_page_history($arr) {
	$pagename = ((array_key_exists('page',$arr)) ? $arr['page'] : '');
	$resource_id = ((array_key_exists('resource_id',$arr)) ? $arr['resource_id'] : '');
	$w = wiki_get_wiki($resource_id);
	if (!$w['path']) {
		return array('history' => null, 'message' => 'Error reading wiki', 'success' => false);
	}
	$page_path = $w['path'].'/'.$pagename;
	if (!is_readable($page_path) === true) {
		return array('history' => null, 'message' => 'Cannot read wiki page: ' . $page_path, 'success' => false);
	}
	$reponame = ((array_key_exists('title', $w['wiki'])) ? $w['wiki']['title'] : 'repo');
	if($reponame === '') {
		$reponame = 'repo';
	}
	$git = new GitRepo('sys', null, false, $w['wiki']['title'], $w['path']);
	try {
		$gitlog = $git->git->log('', $page_path , array('limit' => 50));
		logger('gitlog: ' . json_encode($gitlog));
		return array('history' => $gitlog, 'message' => '', 'success' => true);
	} catch (\PHPGit\Exception\GitException $e) {
		 return array('history' => null, 'message' => 'GitRepo error thrown', 'success' => false);
	}	
}

function wiki_save_page($arr) {
	$pagename = ((array_key_exists('name',$arr)) ? $arr['name'] : '');
	$content = ((array_key_exists('content',$arr)) ? $arr['content'] : '');
	$resource_id = ((array_key_exists('resource_id',$arr)) ? $arr['resource_id'] : '');
	$w = wiki_get_wiki($resource_id);
	if (!$w['path']) {
		return array('message' => 'Error reading wiki', 'success' => false);
	}
	$page_path = $w['path'].'/'.$pagename;
	if (is_writable($page_path) === true) {
		if(!file_put_contents($page_path, $content)) {
			return array('message' => 'Error writing to page file', 'success' => false);
		}
		return array('message' => '', 'success' => true);
	} else {
		return array('message' => 'Page file not writable', 'success' => false);
	}	
}

function wiki_git_commit($arr) {
	$files = ((array_key_exists('files', $arr)) ? $arr['files'] : null);
	$commit_msg = ((array_key_exists('commit_msg', $arr)) ? $arr['commit_msg'] : 'Repo updated');
	$resource_id = ((array_key_exists('resource_id', $arr)) ? $arr['resource_id'] : json_return_and_die(array('message' => 'Wiki resource_id required for git commit', 'success' => false)));
	$observer = ((array_key_exists('observer', $arr)) ? $arr['observer'] : json_return_and_die(array('message' => 'Observer required for git commit', 'success' => false)));
	$w = wiki_get_wiki($resource_id);
	if (!$w['path']) {
		return array('message' => 'Error reading wiki', 'success' => false);
	}
	$reponame = ((array_key_exists('title', $w['wiki'])) ? $w['wiki']['title'] : 'repo');
	if($reponame === '') {
		$reponame = 'repo';
	}
	$git = new GitRepo('sys', null, false, $w['wiki']['title'], $w['path']);
	try {
		$git->setIdentity($observer['xchan_name'], $observer['xchan_addr']);
		if ($files === null) {
			$options = array('all' => true); // git commit option to include all changes
		} else {
			$options = array(); // git commit options
			foreach ($files as $file) {
				if (!$git->git->add($file)) {	// add specified files to the git repo stage
					if (!$git->git->reset->hard()) {
						json_return_and_die(array('message' => 'Error adding file to git stage: ' . $file . '. Error resetting git repo.', 'success' => false));
					}
					json_return_and_die(array('message' => 'Error adding file to git stage: ' . $file, 'success' => false));
				}
			}
		}
		if ($git->commit($commit_msg, $options)) {
			json_return_and_die(array('message' => 'Wiki repo commit succeeded', 'success' => true));
		} else {
			json_return_and_die(array('message' => 'Wiki repo commit failed', 'success' => false));
		}
	} catch (\PHPGit\Exception\GitException $e) {
		json_return_and_die(array('message' => 'GitRepo error thrown', 'success' => false));
	}
}
