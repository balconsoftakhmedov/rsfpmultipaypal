<?php
/**
* @version 1.4.0
* @package RSform!Pro 1.4.0
* @copyright (C) 2007-2012 www.rsjoomla.com
* @license GPL, http://www.gnu.org/copyleft/gpl.html
*/

// no direct access
defined( '_JEXEC' ) or die( 'Restricted access' );

class plgSystemRsfpmultipaypalInstallerScript
{
	protected static $minJoomla = '3.7.0';
	protected static $minComponent = '3.0.0';

	public function preflight($type, $parent)
	{
		if ($type == 'uninstall')
		{
			return true;
		}
		
		try
		{		
			$jversion = new JVersion();
			if (!$jversion->isCompatible(static::$minJoomla))
			{
				throw new Exception(sprintf('Please upgrade to at least Joomla! %s before continuing!', static::$minJoomla));
			}

			if (!file_exists(JPATH_ADMINISTRATOR.'/components/com_rsform/helpers/rsform.php'))
			{
				throw new Exception('Please install the RSForm! Pro component before continuing.');
			}

			if (!file_exists(JPATH_ADMINISTRATOR.'/components/com_rsform/helpers/assets.php') || !file_exists(JPATH_ADMINISTRATOR.'/components/com_rsform/helpers/version.php'))
			{
				throw new Exception(sprintf('Please upgrade RSForm! Pro to at least version %s before continuing!', static::$minComponent));
			}

			// Check version matches
			require_once JPATH_ADMINISTRATOR.'/components/com_rsform/helpers/version.php';

			if (!class_exists('RSFormProVersion') || version_compare((string) new RSFormProVersion, static::$minComponent, '<'))
			{
				throw new Exception(sprintf('Please upgrade RSForm! Pro to at least version %s before continuing!', static::$minComponent));
			}

			if (!file_exists(JPATH_PLUGINS . '/system/rsfppayment/rsfppayment.php'))
			{
				throw new Exception('Please install the RSForm! Pro Payment Package before continuing.');
			}
		}
		catch (Exception $e)
		{
			JFactory::getApplication()->enqueueMessage($e->getMessage(), 'error');
			return false;
		}
		
		return true;
	}
	
	public function postflight($type, $parent)
	{
		if ($type == 'update')
		{
			$this->runSQL($parent->getParent()->getPath('source'), 'install');
		}

		$installer = $parent->getParent();
		$src = $installer->getPath('source').'/admin';
		$dest = JPATH_ADMINISTRATOR.'/components/com_rsform';

		JFolder::copy($src, $dest, '', true);
	}
	
	protected function runSQL($source, $file)
	{
		$db 	= JFactory::getDbo();
		$driver = strtolower($db->name);

		if (strpos($driver, 'mysql') !== false)
		{
			$driver = 'mysql';
		}
		
		$sqlfile = $source . '/sql/' . $driver . '/' . $file . '.sql';
		
		if (file_exists($sqlfile))
		{
			$buffer = file_get_contents($sqlfile);
			if ($buffer !== false)
			{
				$queries = $db->splitSql($buffer);
				foreach ($queries as $query)
				{
					$query = trim($query);
					if ($query != '')
					{
						$db->setQuery($query)->execute();
					}
				}
			}
		}
	}
}