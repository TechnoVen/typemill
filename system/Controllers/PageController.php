<?php

namespace Typemill\Controllers;

use Typemill\Models\Folder;
use Typemill\Models\WriteCache;
use Typemill\Models\WriteSitemap;
use Typemill\Models\WriteYaml;
use \Symfony\Component\Yaml\Yaml;
use Typemill\Models\VersionCheck;
use Typemill\Models\Helpers;
use Typemill\Models\Markdown;
use Typemill\Events\OnPagetreeLoaded;
use Typemill\Events\OnBreadcrumbLoaded;
use Typemill\Events\OnItemLoaded;
use Typemill\Events\OnOriginalLoaded;
use Typemill\Events\OnMetaLoaded;
use Typemill\Events\OnMarkdownLoaded;
use Typemill\Events\OnContentArrayLoaded;
use Typemill\Events\OnHtmlLoaded;
use Typemill\Extensions\ParsedownExtension;

class PageController extends Controller
{
	public function index($request, $response, $args)
	{
		/* Initiate Variables */
		$structure		= false;
		$contentHTML	= false;
		$item			= false;
		$home			= false;
		$breadcrumb 	= false;
		$description	= '';
		$settings		= $this->c->get('settings');
		$pathToContent	= $settings['rootPath'] . $settings['contentFolder'];
		$cache 			= new WriteCache();
		$uri 			= $request->getUri();
		$base_url		= $uri->getBaseUrl();

		try
		{
			/* if the cached structure is still valid, use it */
			if($cache->validate('cache', 'lastCache.txt', 600))
			{
				$structure	= $this->getCachedStructure($cache);
			}
			if(!isset($structure) OR !$structure) 
			{
				/* if not, get a fresh structure of the content folder */
				$structure 	= $this->getFreshStructure($pathToContent, $cache, $uri);

				/* if there is no structure at all, the content folder is probably empty */
				if(!$structure)
				{
					$content = '<h1>No Content</h1><p>Your content folder is empty.</p>'; 

					return $this->render($response, '/index.twig', array( 'content' => $content ));
				}
				elseif(!$cache->validate('cache', 'lastSitemap.txt', 86400))
				{
					/* update sitemap */
					$sitemap = new WriteSitemap();
					$sitemap->updateSitemap('cache', 'sitemap.xml', 'lastSitemap.txt', $structure, $uri->getBaseUrl());

					/* check and update the typemill-version in the user settings */
					# this version check is not needed 
					# $this->updateVersion($uri->getBaseUrl());
				}
			}
			
			/* dispatch event and let others manipulate the structure */
			$structure = $this->c->dispatcher->dispatch('onPagetreeLoaded', new OnPagetreeLoaded($structure))->getData();
		}
		catch (Exception $e)
		{
			echo $e->getMessage();
			exit(1);
		}

		# get the cached navigation here (structure without hidden files )
		$navigation = $cache->getCache('cache', 'navigation.txt');
		if(!$navigation)
		{
			# use the structure as navigation if there is no difference
			$navigation = $structure;
		}

		# if the user is on startpage
		if(empty($args))
		{
			$home = true;
			$item = Folder::getItemForUrl($structure, $uri->getBasePath(), $uri->getBasePath());
		}
		else
		{
			/* get the request url */
			$urlRel = $uri->getBasePath() . '/' . $args['params'];
			
			/* find the url in the content-item-tree and return the item-object for the file */
			$item = Folder::getItemForUrl($structure, $urlRel, $uri->getBasePath());

			/* if there is still no item, return a 404-page */
			if(!$item)
			{
				return $this->render404($response, array( 'navigation' => $navigation, 'settings' => $settings,  'base_url' => $base_url )); 
			}

			/* get breadcrumb for page */
			$breadcrumb = Folder::getBreadcrumb($structure, $item->keyPathArray);
			$breadcrumb = $this->c->dispatcher->dispatch('onBreadcrumbLoaded', new OnBreadcrumbLoaded($breadcrumb))->getData();
			
			/* add the paging to the item */
			$item = Folder::getPagingForItem($structure, $item);
		}

		# dispatch the item
		$item 			= $this->c->dispatcher->dispatch('onItemLoaded', new OnItemLoaded($item))->getData();	

		# set the filepath
		$filePath 	= $pathToContent . $item->path;
		
		# check if url is a folder and add index.md 
		if($item->elementType == 'folder')
		{
			$filePath 	= $filePath . DIRECTORY_SEPARATOR . 'index.md';
		}

		# read the content of the file
		$contentMD 		= file_exists($filePath) ? file_get_contents($filePath) : false;

		# dispatch the original content without plugin-manipulations for case anyone wants to use it
		$this->c->dispatcher->dispatch('onOriginalLoaded', new OnOriginalLoaded($contentMD));
		
		# get meta-Information
		$writeYaml 		= new WriteYaml();
		$metatabs 		= $writeYaml->getPageMeta($settings, $item);

		if(!$metatabs)
		{
			$metatabs 	= $writeYaml->getPageMetaDefaults($contentMD, $settings, $item);
		}

		# dispatch meta 
		$metatabs 		= $this->c->dispatcher->dispatch('onMetaLoaded', new OnMetaLoaded($metatabs))->getData();

		# dispatch content
		$contentMD 		= $this->c->dispatcher->dispatch('onMarkdownLoaded', new OnMarkdownLoaded($contentMD))->getData();

		/* initialize parsedown */
		$parsedown 		= new ParsedownExtension();
		
		/* set safe mode to escape javascript and html in markdown */
		$parsedown->setSafeMode(true);

		/* parse markdown-file to content-array */
		$contentArray 	= $parsedown->text($contentMD);
		$contentArray 	= $this->c->dispatcher->dispatch('onContentArrayLoaded', new OnContentArrayLoaded($contentArray))->getData();
		
		/* get the first image from content array */
		$firstImage		= $this->getFirstImage($contentArray);

		$itemUrl 		= isset($item->urlRel) ? $item->urlRel : false;
		
		/* parse markdown-content-array to content-string */
		$contentHTML	= $parsedown->markup($contentArray, $itemUrl);
		$contentHTML 	= $this->c->dispatcher->dispatch('onHtmlLoaded', new OnHtmlLoaded($contentHTML))->getData();
		
		/* extract the h1 headline*/
		$contentParts	= explode("</h1>", $contentHTML);
		$title			= isset($contentParts[0]) ? strip_tags($contentParts[0]) : $settings['title'];
		
		$contentHTML	=  isset($contentParts[1]) ? $contentParts[1] : $contentHTML;

		# if there is not meta description 
		if(!isset($metatabs['meta']['description']) or !$metatabs['meta']['description'])
		{
			# create excerpt from html
			$excerpt		= substr($contentHTML,0,500);
			
			# create description from excerpt
			$description	= isset($excerpt) ? strip_tags($excerpt) : false;
			if($description)
			{
				$description 	= trim(preg_replace('/\s+/', ' ', $description));
				$description	= substr($description, 0, 300);		
				$lastSpace 		= strrpos($description, ' ');

				$metatabs['meta']['description'] 	= substr($description, 0, $lastSpace);
			}
		}

		/* get url and alt-tag for first image, if exists */
		if($firstImage)
		{
			preg_match('#\((.*?)\)#', $firstImage, $img_url);
			if($img_url[1])
			{
				preg_match('#\[(.*?)\]#', $firstImage, $img_alt);
				
				$firstImage = array('img_url' => $base_url . '/' . $img_url[1], 'img_alt' => $img_alt[1]);
			}
		}
		
		$theme = $settings['theme'];
		$route = empty($args) && isset($settings['themes'][$theme]['cover']) ? '/cover.twig' : '/index.twig';

		# check if there is a custom theme css
		$customcss = $writeYaml->checkFile('cache', $theme . '-custom.css');
		if($customcss)
		{
			$this->c->assets->addCSS($base_url . '/cache/' . $theme . '-custom.css');
		}

		return $this->render($response, $route, [
			'home'			=> $home,
			'navigation' 	=> $navigation, 
			'title' 		=> $title,
			'content' 		=> $contentHTML, 
			'item' 			=> $item,
			'breadcrumb' 	=> $breadcrumb, 
			'settings' 		=> $settings,
			'metatabs'		=> $metatabs,
			'base_url' 		=> $base_url, 
			'image' 		=> $firstImage ]);
	}

	protected function getCachedStructure($cache)
	{
		return $cache->getCache('cache', 'structure.txt');
	}
	
	protected function getFreshStructure($pathToContent, $cache, $uri)
	{
		/* scan the content of the folder */
		$structure = Folder::scanFolder($pathToContent);

		/* if there is no content, render an empty page */
		if(count($structure) == 0)
		{
			return false;
		}

		# get the extended structure files with changes like navigation title or hidden pages
		$yaml = new writeYaml();
		$extended = $yaml->getYaml('cache', 'structure-extended.yaml');

		/* create an array of object with the whole content of the folder */
		$structure = Folder::getFolderContentDetails($structure, $extended, $uri->getBaseUrl(), $uri->getBasePath());

		/* cache structure */
		$cache->updateCache('cache', 'structure.txt', 'lastCache.txt', $structure);

		if($extended && $this->containsHiddenPages($extended))
		{
			# generate the navigation (delete empty pages)
			$navigation = $this->createNavigationFromStructure($structure);

			# cache navigation
			$cache->updateCache('cache', 'navigation.txt', false, $navigation);
		}
		else
		{
			# make sure no separate navigation file is set
			$cache->deleteFileWithPath('cache' . DIRECTORY_SEPARATOR . 'navigation.txt');
		}
		
		return $structure;
	}
	
	protected function containsHiddenPages($extended)
	{
		foreach($extended as $element)
		{
			if(isset($element['hide']) && $element['hide'] === true)
			{
				return true;
			}
		}
		return false;
	}

	protected function createNavigationFromStructure($navigation)
	{
		foreach ($navigation as $key => $element)
		{
			if($element->hide === true)
			{
				unset($navigation[$key]);
			}
			elseif(isset($element->folderContent))
			{
				$navigation[$key]->folderContent = $this->createNavigationFromStructure($element->folderContent);
			}
		}
		
		return $navigation;
	}

	# not in use, stored the latest version in user settings, but that does not make sense because checkd on the fly with api in admin
	protected function updateVersion($baseUrl)
	{
		/* check the latest public typemill version */
		$version 		= new VersionCheck();
		$latestVersion 	= $version->checkVersion($baseUrl);

		if($latestVersion)
		{
			/* store latest version */
			\Typemill\Settings::updateSettings(array('latestVersion' => $latestVersion));			
		}
	}
	
	protected function getFirstImage(array $contentBlocks)
	{
		foreach($contentBlocks as $block)
		{
			/* is it a paragraph? */
			if(isset($block['name']) && $block['name'] == 'p')
			{
				if(isset($block['handler']['argument']) && substr($block['handler']['argument'], 0, 2) == '![' )
				{
					return $block['handler']['argument'];	
				}
			}
		}
		
		return false;
	}
}