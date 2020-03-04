<?php

/**
 * @brief Process CardDAV card
 *
 * @param array $f	fields
 * @param obj	$vcard	SabreDAV object
 * @param bool	$edit	update card
 *
 */

function process_cdav_card($f, &$vcard, $edit = false) {

	if($f['org'])
		$vcard->ORG = $f['org'];
	else
		if($edit)
			unset($vcard->ORG);


	if($f['title'])
		$vcard->TITLE = $f['title'];
	else
		if($edit)
			unset($vcard->TITLE);

	if($edit)
		unset($vcard->TEL);
	if($f['tel']) {
		$i = 0;
		foreach($f['tel'] as $item) {
			if($item) {
				$vcard->add('TEL', $item, ['type' => $f['tel_type'][$i]]);
			}
			$i++;
		}
	}

	if($edit)
		unset($vcard->EMAIL);
	if($f['email']) {
		$i = 0;
		foreach($f['email'] as $item) {
			if($item) {
				$vcard->add('EMAIL', $item, ['type' => $f['email_type'][$i]]);
			}
			$i++;
		}
	}

	if($edit)
		unset($vcard->IMPP);
	if($f['impp']) {
		$i = 0;
		foreach($f['impp'] as $item) {
			if($item) {
				$vcard->add('IMPP', $item, ['type' => $f['impp_type'][$i]]);
			}
			$i++;
		}
	}

	if($edit)
		unset($vcard->URL);
	if($f['url']) {
		$i = 0;
		foreach($f['url'] as $item) {
			if($item) {
				$vcard->add('URL', $item, ['type' => $f['url_type'][$i]]);
			}
			$i++;
		}
	}

	if($edit)
		unset($vcard->ADR);
	if($f['adr']) {
		$i = 0;
		foreach($f['adr'] as $item) {
			if($item) {
				$vcard->add('ADR', $item, ['type' => $f['adr_type'][$i]]);
			}
			$i++;
		}
	}

	if($f['note']) {
		$vcard->NOTE = $f['note'];
	}
	else
		if($edit)
			unset($vcard->NOTE);
}


/**
 * @brief Import CardDAV or CalDAV card
 *
 * @param mixed	$id     card id
 * @param str	$ext	card extension
 * @param str	$table	name
 * @param str	$column name
 * @param obj	$objects
 * @param str	$profile
 * @param obj	$backend
 * @param array	$ids
 * @param bool	$notice
 *
 */

function import_cdav_card($id, $ext, $table, $column, $objects, $profile, $backend, &$ids, $notice = false) {

	$i = 0;
	$newid = (count($ids) ? false : true); 

	while ($object = $objects->getNext()) {

		if($_REQUEST['a_upload'])
			$object = $object->convert(\Sabre\VObject\Document::VCARD40);

		$ret = $object->validate($profile & \Sabre\VObject\Node::REPAIR);

		//level 3 Means that the document is invalid,
		//level 2 means a warning. A warning means it's valid but it could cause interopability issues,
		//level 1 means that there was a problem earlier, but the problem was automatically repaired.

		if($ret[0]['level'] < 3) {

			if($newid) {
				do {
					$duplicate = false;
					$objectUri = random_string(40) . '.' . $ext;

					$r = q("SELECT uri FROM $table WHERE $column = %d AND uri = '%s' LIMIT 1",
						dbesc($id),
						dbesc($objectUri)
					);
					if (count($r))
						$duplicate = true;
				} while ($duplicate == true);
				$ids[$i] = $objectUri;
			}
			else
				$objectUri = $ids[$i];

			$i++;

			if($ext == 'ics')
				$backend->createCalendarObject($id, $objectUri, $object->serialize());

			if($ext == 'vcf')
				$backend->createCard($id, $objectUri, $object->serialize());
		}
		else {
			if($notice && $ext == 'ics') {
				notice(
					'<strong>' . t('INVALID EVENT DISMISSED!') . '</strong>' . EOL .
					'<strong>' . t('Summary: ') . '</strong>' . (($object->VEVENT->SUMMARY) ? $object->VEVENT->SUMMARY : t('Unknown')) . EOL .
					'<strong>' . t('Date: ') . '</strong>' . (($object->VEVENT->DTSTART) ? $object->VEVENT->DTSTART : t('Unknown')) . EOL .
					'<strong>' . t('Reason: ') . '</strong>' . $ret[0]['message'] . EOL
				);
			}

			if($notice && $exp == 'vcf') {
				notice(
					'<strong>' . t('INVALID CARD DISMISSED!') . '</strong>' . EOL .
					'<strong>' . t('Name: ') . '</strong>' . (($object->FN) ? $object->FN : t('Unknown')) . EOL .
					'<strong>' . t('Reason: ') . '</strong>' . $ret[0]['message'] . EOL
				);
			}
		}
	}
}


function get_cdav_id($principaluri, $uri, $table) {

        $r = q("SELECT * FROM $table WHERE principaluri = '%s' AND uri = '%s' LIMIT 1",
                dbesc($principaluri),
                dbesc($uri)
        );
        if(! $r)
                return false;

        return $r[0];
}
