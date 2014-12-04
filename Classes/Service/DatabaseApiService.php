<?php

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2012 Georg Ringer <georg.ringer@cyberhouse.at>
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

/**
 * DB API service
 *
 * @package TYPO3
 * @subpackage tx_coreapi
 */
class Tx_Coreapi_Service_DatabaseApiService {
	const ACTION_UPDATE_CLEAR_TABLE = 1;
	const ACTION_UPDATE_ADD = 2;
	const ACTION_UPDATE_CHANGE = 3;
	const ACTION_UPDATE_CREATE_TABLE = 4;
	const ACTION_REMOVE_CHANGE = 5;
	const ACTION_REMOVE_DROP = 6;
	const ACTION_REMOVE_CHANGE_TABLE = 7;
	const ACTION_REMOVE_DROP_TABLE = 8;

	/**
	 * @var t3lib_install_Sql Instance of SQL handler
	 */
	protected $sqlHandler = NULL;

	/**
	 * Constructor function
	 */
	public function __construct() {
		if (class_exists('TYPO3\\CMS\\Install\\Sql\\SchemaMigrator')) {
			$this->sqlHandler = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance('TYPO3\\CMS\\Install\\Sql\\SchemaMigrator');
		} elseif (class_exists('t3lib_install_Sql')) {
			$this->sqlHandler = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance('t3lib_install_Sql');
		} elseif (class_exists('t3lib_install')) {
			$this->sqlHandler = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance('t3lib_install');
		}
	}

	/**
	 * Database compare
	 *
	 * @param string $actions comma separated list of IDs
	 * @param boolean $dry dry run
	 * @return array
	 * @throws InvalidArgumentException
	 */
	public function databaseCompare($actions, $dry = FALSE) {
		$errors = array();

		$availableActions = array_flip(\TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(
			'Tx_Extbase_Reflection_ClassReflection',
			'Tx_Coreapi_Service_DatabaseApiService'
		)->getConstants());

		if (empty($actions)) {
			throw new InvalidArgumentException('No compare modes defined');
		}

		$allowedActions = array();
		$actionSplit = \TYPO3\CMS\Core\Utility\GeneralUtility::trimExplode(',', $actions);
		foreach ($actionSplit as $split) {
			if (!isset($availableActions[$split])) {
				throw new InvalidArgumentException(sprintf('Action "%s" is not available!', $split));
			}
			$allowedActions[$split] = 1;
		}

		$tblFileContent = '';

		if (defined('PATH_t3lib')) {
			$tblFileContent .= \TYPO3\CMS\Core\Utility\GeneralUtility::getUrl(PATH_t3lib . 'stddb/tables.sql');
		}

		foreach ($GLOBALS['TYPO3_LOADED_EXT'] as $loadedExtConf) {
			if (is_array($loadedExtConf) && $loadedExtConf['ext_tables.sql']) {
				$extensionSqlContent = \TYPO3\CMS\Core\Utility\GeneralUtility::getUrl($loadedExtConf['ext_tables.sql']);
				$tblFileContent .= LF . LF . LF . LF . $extensionSqlContent;
			}
		}

		if (is_callable('TYPO3\\CMS\\Core\\Cache\\Cache::getDatabaseTableDefinitions')) {
			$tblFileContent .= \TYPO3\CMS\Core\Cache\Cache::getDatabaseTableDefinitions();
		} elseif (is_callable('t3lib_cache::getDatabaseTableDefinitions')) {
			$tblFileContent .= t3lib_cache::getDatabaseTableDefinitions();
		}

		if (class_exists('TYPO3\\CMS\\Core\\Category\\CategoryRegistry')) {
			$tblFileContent .= \TYPO3\CMS\Core\Category\CategoryRegistry::getInstance()->getDatabaseTableDefinitions();
		}

		if ($tblFileContent) {
			$fileContent = implode(LF, $this->sqlHandler->getStatementArray($tblFileContent, 1, '^CREATE TABLE '));
			$FDfile = $this->sqlHandler->getFieldDefinitions_fileContent($fileContent);

			$FDdb = $this->sqlHandler->getFieldDefinitions_database();

			$diff = $this->sqlHandler->getDatabaseExtra($FDfile, $FDdb);
			$update_statements = $this->sqlHandler->getUpdateSuggestions($diff);

			$diff = $this->sqlHandler->getDatabaseExtra($FDdb, $FDfile);
			$remove_statements = $this->sqlHandler->getUpdateSuggestions($diff, 'remove');

			$allowedRequestKeys = $this->getRequestKeys($update_statements, $remove_statements);
			$results = array();

			if ($allowedActions[self::ACTION_UPDATE_CLEAR_TABLE] == 1) {
				if ($dry) {
					$results['clear_table'] = $update_statements['clear_table'];
				} else {
					$results[] = $this->sqlHandler->performUpdateQueries($update_statements['clear_table'], $allowedRequestKeys);
				}
			}

			if ($allowedActions[self::ACTION_UPDATE_ADD] == 1) {
				if ($dry) {
					$results['add'] = $update_statements['add'];
				} else {
					$results[] = $this->sqlHandler->performUpdateQueries($update_statements['add'], $allowedRequestKeys);
				}
			}

			if ($allowedActions[self::ACTION_UPDATE_CHANGE] == 1) {
				if ($dry) {
					$results['update_change'] = $update_statements['change'];
				} else {
					$results[] = $this->sqlHandler->performUpdateQueries($update_statements['change'], $allowedRequestKeys);
				}
			}

			if ($allowedActions[self::ACTION_REMOVE_CHANGE] == 1) {
				if ($dry) {
					$results['remove_change'] = $remove_statements['change'];
				} else {
					$results[] = $this->sqlHandler->performUpdateQueries($remove_statements['change'], $allowedRequestKeys);
				}
			}

			if ($allowedActions[self::ACTION_REMOVE_DROP] == 1) {
				if ($dry) {
					$results['drop'] = $remove_statements['drop'];
				} else {
					$results[] = $this->sqlHandler->performUpdateQueries($remove_statements['drop'], $allowedRequestKeys);
				}
			}

			if ($allowedActions[self::ACTION_UPDATE_CREATE_TABLE] == 1) {
				if ($dry) {
					$results['create_table'] = $update_statements['create_table'];
				} else {
					$results[] = $this->sqlHandler->performUpdateQueries($update_statements['create_table'], $allowedRequestKeys);
				}
			}

			if ($allowedActions[self::ACTION_REMOVE_CHANGE_TABLE] == 1) {
				if ($dry) {
					$results['change_table'] = $remove_statements['change_table'];
				} else {
					$results[] = $this->sqlHandler->performUpdateQueries($remove_statements['change_table'], $allowedRequestKeys);
				}
			}

			if ($allowedActions[self::ACTION_REMOVE_DROP_TABLE] == 1) {
				if ($dry) {
					$results['drop_table'] = $remove_statements['drop_table'];
				} else {
					$results[] = $this->sqlHandler->performUpdateQueries($remove_statements['drop_table'], $allowedRequestKeys);
				}
			}

			if ($dry) {
				foreach ($results as $key => $resultSet) {
					if (!empty($resultSet)) {
						$errors[$key] = $resultSet;
					}
				}
			} else {
				foreach ($results as $resultSet) {
					if (is_array($resultSet)) {
						foreach ($resultSet as $key => $line) {
							$errors[$key] = $line;
						}
					}
				}
			}
		}

		return $errors;
	}

	/**
	 * Get all available actions
	 *
	 * @return array
	 */
	public function databaseCompareAvailableActions() {
		$availableActions = array_flip(\TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance('Tx_Extbase_Reflection_ClassReflection', 'Tx_Coreapi_Service_DatabaseApiService')->getConstants());

		foreach ($availableActions as $number => $action) {
			if (!\TYPO3\CMS\Core\Utility\GeneralUtility::isFirstPartOfStr($action, 'ACTION_')) {
				unset($availableActions[$number]);
			}
		}
		return $availableActions;
	}

	/**
	 * Get all request keys, even for those requests which are not used
	 *
	 * @param array $update
	 * @param array $remove
	 * @return array
	 */
	protected function getRequestKeys(array $update, array $remove) {
		$tmpKeys = array();

		$updateActions = array('clear_table', 'add', 'change', 'create_table');
		$removeActions = array('change', 'drop', 'change_table', 'drop_table');

		foreach ($updateActions as $updateAction) {
			if (isset($update[$updateAction]) && is_array($update[$updateAction])) {
				$tmpKeys[] = array_keys($update[$updateAction]);
			}
		}

		foreach ($removeActions as $removeAction) {
			if (isset($remove[$removeAction]) && is_array($remove[$removeAction])) {
				$tmpKeys[] = array_keys($remove[$removeAction]);
			}
		}

		$finalKeys = array();
		foreach ($tmpKeys as $keys) {
			foreach ($keys as $key) {
				$finalKeys[$key] = TRUE;
			}
		}
		return $finalKeys;
	}

}
