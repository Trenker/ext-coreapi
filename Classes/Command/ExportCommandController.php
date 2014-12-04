<?php
/***************************************************************
 *  Copyright notice
 *
 *  (c) 2014 Georg Ringer <georg.ringer@cyberhouse.at>
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Export/Backup Command Controller
 *
 * @package TYPO3
 * @subpackage tx_coreapi
 */
class Tx_Coreapi_Command_ExportCommandController extends \TYPO3\CMS\Extbase\Mvc\Controller\CommandController {

	const PATH = 'typo3temp/tx_coreapi/';

	/**
	 * Truncate cache tables
	 *
	 * @param string $sure Say YES
	 * @throws UnexpectedValueException
	 * @return void
	 */
	public function truncateExtraTablesCommand($sure = '') {
		$tables = $this->getNotNeededTables();

		$this->outputLine('The following tables are marked as truncatable: ' . implode(', ', $tables));

		if ($sure !== 'YES') {
			$this->outputLine(LF . LF . 'Sorry, you need to explicitly say YES as an argument.');
		} else {
			$this->truncateNotNeededTables();
			$this->outputLine('Tables have been TRUNCATED!');
		}
	}

	/**
	 * Export files + database
	 *
	 * @param string $prefix
	 * @return void
	 */
	public function allCommand($prefix = '') {
		$this->createOutputDirectory();

		$this->exportDB($prefix);
		$this->packageFiles($prefix);
	}

	/**
	 * Export database
	 *
	 * @param string $prefix
	 * @return void
	 */
	public function dbCommand($prefix = '') {
		$this->createOutputDirectory();

		$this->exportDB($prefix);
	}

	/**
	 * Export files
	 *
	 * @param string $prefix
	 * @return void
	 */
	public function filesCommand($prefix = '') {
		$this->createOutputDirectory();

		$this->packageFiles($prefix);
	}

	/**
	 * Package files which are used but not part of the git repo
	 *
	 * @param string $prefix
	 * @return void
	 */
	protected function packageFiles($prefix) {
		$path = PATH_site;
		$target = $this->getPath($prefix) . 'files.tar.gz';

		$commandParts = array(
			'cd ' . $path . '&&',
			'tar zcvhf',
			$target,
			'-C ' . $path,
			'fileadmin/',
			'uploads/',
			'typo3conf/l10n/',
		);

		$command = implode(' ', $commandParts);
		shell_exec($command);
		shell_exec('chmod 777 ' . $target);

		$this->outputLine('The dump has been saved to "%s" and got a size of "%s".', array($target, GeneralUtility::formatSize(filesize($target))));
	}

	/**
	 * Export the complete DB using mysqldump
	 *
	 * @param string $prefix
	 * @return void
	 */
	protected function exportDB($prefix) {
		$dbData = $GLOBALS['TYPO3_CONF_VARS']['DB'];

		$path = GeneralUtility::getFileAbsFileName($this->getPath($prefix) . 'db.sql');

		$commandParts = array(
			'mysqldump --host=' . $dbData['host'],
			'--user=' . $dbData['username'],
			'--password=' . $dbData['password'],
			$dbData['database'] . ' > ' . $path
		);

		$command = implode(' ', $commandParts);
		shell_exec($command);
		shell_exec('chmod 777 ' . $path);

		$this->outputLine('The dump has been saved to "%s" and got a size of "%s".', array($path, GeneralUtility::formatSize(filesize($path))));
	}

	/**
	 * Truncate not needed tables to save space
	 *
	 * @return void
	 */
	protected function truncateNotNeededTables() {
		$tables = $this->getNotNeededTables();
		foreach ($tables as $tableName) {
			$GLOBALS['TYPO3_DB']->exec_TRUNCATEquery($tableName);
		}
	}

	/**
	 * Get all tables which might be truncated
	 *
	 * @return array
	 */
	protected function getNotNeededTables() {
		$tables = array();
		$truncatedPrefixes = array('cf_', 'cache_', 'index_', 'tx_extensionmanager_domain_model_extension', 'sys_lockedrecords');

		$tableList = array_keys($GLOBALS['TYPO3_DB']->admin_get_tables());
		foreach ($tableList as $tableName) {
			$found = FALSE;
			foreach ($truncatedPrefixes as $prefix) {
				if ($found || GeneralUtility::isFirstPartOfStr($tableName, $prefix)) {
					$tables[$tableName] = $tableName;
					$found = TRUE;
				}
			}
		}

		return $tables;
	}

	/**
	 * Create directory
	 *
	 * @return void
	 */
	protected function createOutputDirectory() {
		$path = PATH_site . self::PATH;

		if (!is_dir($path)) {
			GeneralUtility::mkdir($path);
		}
	}

	/**
	 * Return the path, including timestamp + a random value
	 *
	 * @param string $prefix
	 * @return string
	 */
	protected function getPath($prefix) {
		$path = PATH_site . self::PATH;

		if (!empty($prefix) && preg_match('/^[a-z0-9_\\-]{2,}$/i', $prefix)) {
			$path .= $prefix;
		} else {
			$path .= date('Y-m-d_h-i') . '-' . GeneralUtility::getRandomHexString(16) . '-';
		}

		return $path;
	}

}
