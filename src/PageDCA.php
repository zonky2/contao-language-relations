<?php

namespace Hofff\Contao\LanguageRelations;

/**
 * @author Oliver Hoff <oliver@hofff.com>
 */
class PageDCA {

	/**
	 * @var array
	 */
	private $relations;

	/**
	 */
	public function __construct() {
		$this->relations = [];
	}

	/**
	 * @param string $table
	 * @return void
	 */
	public function hookLoadDataContainer($table) {
		if($table != 'tl_page') {
			return;
		}

		$palettes = &$GLOBALS['TL_DCA']['tl_page']['palettes'];
		foreach($palettes as $key => &$palette) {
			if($key != '__selector__' && $key != 'root') {
				$palette .= ';{cca_lr_legend}';
				$_GET['do'] == 'cca_lr_group' && $palette .= ',cca_lr_pageInfo';
				$palette .= ',cca_lr_relations';
			}
		}
		unset($palette, $palettes);
	}

	/**
	 * @param \DataContainer $dc
	 * @param string $xlabel
	 * @return string
	 */
	public function inputFieldPageInfo($dc, $xlabel) {
		$tpl = new \BackendTemplate('cca_lr_pageInfo');
		$tpl->setData($dc->activeRecord->row());
		return $tpl->parse();
	}

	/**
	 * @param \DataContainer $dc
	 * @return void
	 */
	public function onsubmitPage($dc) {
		if(!isset($this->relations[$dc->id])) {
			return;
		}

		$relations = $this->relations[$dc->id];
		unset($this->relations[$dc->id]);

		$makePrimary = array_keys(array_filter($relations, function($relation) {
			return (bool) $relation['primary'];
		}));

		LanguageRelations::deleteRelationsFrom($dc->id);
		LanguageRelations::deleteRelationsToRoot($makePrimary, $dc->id);
		if(!LanguageRelations::createRelations($dc->id, array_keys($relations))) {
			return;
		}
		LanguageRelations::createReflectionRelations($dc->id);
		LanguageRelations::createIntermediateRelations($dc->id);
	}

	/**
	 * @param integer $insertID
	 * @param \DataContainer $dc
	 * @return void
	 */
	public function oncopyPage($insertID, $dc) {
		$this->copyRelations($dc->id, $insertID, $insertID);
	}

	/**
	 * @param mixed $value
	 * @param \DataContainer $dc
	 * @return array<integer>
	 */
	public function loadRelations($value, $dc) {
		$sql = 'SELECT pageTo FROM tl_cca_lr_relation WHERE pageFrom = ?';
		$result = \Database::getInstance()->prepare($sql)->executeUncached($dc->id);
		return $result->fetchEach('pageTo');
	}

	/**
	 * @param array<integer> $value
	 * @param \DataContainer $dc
	 * @throws \Exception
	 * @return null
	 */
	public function saveRelations($value, $dc) {
		$value = deserialize($value, true);

		if($value) {
			$wildcards = rtrim(str_repeat('?,', count($value)), ',');

			$sql = <<<SQL
SELECT		hofff_root_page_id
FROM		tl_page
WHERE		id IN ($wildcards)
GROUP BY	hofff_root_page_id
HAVING		COUNT(id) > 1
LIMIT		1
SQL;
			$params = array_keys($value);
			$result = \Database::getInstance()->prepare($sql)->executeUncached($params);
			if($result->numRows) {
				throw new \Exception($GLOBALS['TL_LANG']['tl_page']['cca_lr_errMultipleRelationsPerRoot']);
			}

			$sql = <<<SQL
SELECT		SUM(rootPage.cca_lr_group != curRootPage.cca_lr_group) AS ungroupedRelations,
			SUM(rootPage.id = curRootPage.id) AS ownRootRelations

FROM		tl_page		AS page
JOIN		tl_page		AS rootPage			ON rootPage.id = page.hofff_root_page_id
JOIN		(
	SELECT	curRootPage1.cca_lr_group, curRootPage1.id
	FROM	tl_page		AS curPage1
	JOIN	tl_page		AS curRootPage1		ON curRootPage1.id = curPage1.hofff_root_page_id
	WHERE	curPage1.id = ?
)						AS curRootPage

WHERE		page.id IN ($wildcards)
SQL;
			array_unshift($params, $dc->id);
			$result = \Database::getInstance()->prepare($sql)->executeUncached($params);
			if($result->ungroupedRelations) {
				throw new \Exception($GLOBALS['TL_LANG']['tl_page']['cca_lr_errUngroupedRelations']);
			}
			if($result->ownRootRelations) {
				throw new \Exception($GLOBALS['TL_LANG']['tl_page']['cca_lr_errOwnRootRelations']);
			}
		}

		$this->relations[$dc->id] = $value;
		return null;
	}

	/**
	 * @param integer $original
	 * @param integer $copy
	 * @param integer $copyStart
	 * @return void
	 */
	protected function copyRelations($original, $copy, $copyStart) {
		$db = \Database::getInstance();

		$original = $this->getPageInfo($original);
		$copy = $this->getPageInfo($copy);

		if($original->type == 'root') {
			if(!$original->cca_lr_group) {
				$sql = 'SELECT dns, title FROM tl_page WHERE id = ?';
				$result = $db->prepare($sql)->executeUncached($original->id);

				$sql = 'INSERT INTO tl_cca_lr_group(tstamp, title) VALUES(?, ?)';
				$result = $db->prepare($sql)->executeUncached(time(), $result->dns ?: $result->title);
				$original->cca_lr_group = $result->insertId;

				$sql = 'UPDATE tl_page SET cca_lr_group = ? WHERE id = ?';
				$db->prepare($sql)->executeUncached($original->cca_lr_group, $original->id);
			}

			$sql = 'UPDATE tl_page SET cca_lr_group = ? WHERE id = ?';
			$db->prepare($sql)->executeUncached($original->cca_lr_group, $copy->id);

		} elseif($original->hofff_root_page_id != $copy->hofff_root_page_id && $original->cca_lr_group == $copy->cca_lr_group) {
			$relations = LanguageRelations::getRelations($original->id);

			$wildcards = rtrim(str_repeat('(?,?),', count($relations) + 1), ',');
			$sql = 'INSERT INTO tl_cca_lr_relation (pageFrom, pageTo) VALUES ' . $wildcards;

			$params = [];
			$params[] = $copy->id;
			$params[] = $original->id;
			foreach($relations as $id) {
				$params[] = $copy->id;
				$params[] = $id;
			}

			$db->prepare($sql)->executeUncached($params);

			LanguageRelations::createReflectionRelations($copy->id);
		}

		$sql = 'SELECT id FROM tl_page WHERE pid = ? ORDER BY sorting';
		$copyChildren = $db->prepare($sql)->execute($copy->id);
		if(!$copyChildren->numRows) {
			return;
		}

		$sql = 'SELECT id FROM tl_page WHERE pid = ? AND id != ? ORDER BY sorting';
		$originalChildren = $db->prepare($sql)->execute($original->id, $copyStart);
		if($originalChildren->numRows != $copyChildren->numRows) {
			return;
		}

		while($originalChildren->next() && $copyChildren->next()) {
			$this->copyRelations($originalChildren->id, $copyChildren->id, $copyStart);
		}
	}

	/**
	 * @param integer $id
	 * @return array
	 */
	protected function getPageInfo($id) {
		$sql = <<<SQL
SELECT		page.id, page.type, page.hofff_root_page_id,
			COALESCE(rootPage.cca_lr_group, 0) AS cca_lr_group

FROM		tl_page AS page
LEFT JOIN	tl_page AS rootPage	ON rootPage.id = page.hofff_root_page_id

WHERE		page.id = ?
SQL;
		return \Database::getInstance()->prepare($sql)->executeUncached($id);
	}

}
