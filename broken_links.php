<?php

/**
 * Plugin broken_links.
 *
 * Allow users to easily remove broken links.
 */

use \Shaarli\Security\SessionManager;
use \Shaarli\Security\LoginManager;
use \Shaarli\Router;
use Shaarli\Config\ConfigManager;
use Shaarli\Plugin\PluginManager;
use \Shaarli\Bookmark\LinkDB;
use \Shaarli\History;

/**
 * Display an error if the plugin is active a no action is configured.
 *
 * @param $conf ConfigManager instance
 *
 * @return array|null The errors array or null of there is none.
 */
function broken_links_init($conf)
{
	
    $action = $conf->get('plugins.DEFAULT_ACTION');
    if (empty($action)) {
        $error = t('Broken links plugin error: ' .
            'Please define default action in the plugin administration page.');
        return array($error);
    }
}

/**
 * When plugin parameters are saved, we check all links.
 *
 * @param array $data $_POST array
 *
 * @return array Updated $_POST array
 */
function hook_broken_links_save_plugin_parameters($data)
{

	$conf = new ConfigManager();
	$sessionManager = new SessionManager($_SESSION, $conf);
	$loginManager = new LoginManager($conf, $sessionManager);
	$clientIpId = client_ip_id($_SERVER);
	$loginManager->generateStaySignedInToken($_SERVER['REMOTE_ADDR']);
	$loginManager->checkLoginState($_COOKIE, $clientIpId);
	$history = new History($conf->get('resource.history'));

	$action = $conf->get('plugins.DEFAULT_ACTION');
    if (!empty($action)) {
		
		$pluginManager = new PluginManager($conf);
		$pluginManager->load($conf->get('general.enabled_plugins'));

		$LINKSDB = new LinkDB(
			$conf->get('resource.datastore'),
			true,
			$conf->get('privacy.hide_public_links')
		);
		
		
		foreach ($LINKSDB as $id=>$link){
		
			$handle = curl_init($link["url"]);
			curl_setopt($handle,  CURLOPT_RETURNTRANSFER, TRUE);
			$response = curl_exec($handle);
			$httpCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);
			if(in_array($httpCode,[404,410])) {
				if ($action == "TAG"){
					if (stripos($link["tags"],"@404")===false){
						$link["tags"] = trim(trim($link["tags"])." @404");
					}
					
					$pluginManager->executeHooks('save_link', $link);
					$LINKSDB[$id] = $link;
					$LINKSDB->save($conf->get('resource.page_cache'));
				}else{
					$pluginManager->executeHooks('delete_link', $link);
					unset($LINKSDB[$id]);
					$LINKSDB->save($conf->get('resource.page_cache')); // save to disk
				}
			}else{
				//Remove 404 Tag
				$link["tags"] = str_replace("@404","",$link["tags"]);
				$pluginManager->executeHooks('save_link', $link);
				$LINKSDB[$id] = $link;
				$LINKSDB->save($conf->get('resource.page_cache'));
			}
			curl_close($handle);
		}
				
		//Disable the plugin
		$pluginMeta = $pluginManager->getPluginsMeta();

		// Split plugins into 2 arrays: ordered enabled plugins and disabled.
		$enabledPlugins = array_filter($pluginMeta, function ($v) {
			return $v['order'] !== false;
		});
		// Load parameters.
		$enabledPlugins = load_plugin_parameter_values($enabledPlugins, $conf->get('plugins', array()));
		
		$activePlugins = [];
		foreach ($enabledPlugins as $key=>$plugin){
			if ($key != "broken_links"){
				$activePlugins[]= $key;	
			}
		}
		
		$conf->set('general.enabled_plugins', $activePlugins);
		$conf->write($loginManager->isLoggedIn());
		$history->updateSettings();
		header('Location: ?do='. Router::$PAGE_PLUGINSADMIN);
		exit();		
	}
	
	return $data;
}

