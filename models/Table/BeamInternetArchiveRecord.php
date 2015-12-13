<?php

/**
 * The table for Beam me up to Internet Archive records.
 */
class Table_BeamInternetArchiveRecord extends Omeka_Db_Table
{
    /**
     * Can specify a range of valid beamed record ids or an individual id.
     *
     * @param Omeka_Db_Select $select
     * @param string $range Example: 1-4, 75, 89
     * @return void
     */
    public function filterByRange($select, $range)
    {
        // Comma-separated expressions should be treated individually.
        $exprs = explode(',', $range);

        // Construct a SQL clause where every entry in this array is linked by
        // 'OR'.
        $wheres = array();

        foreach ($exprs as $expr) {
            // If it has a '-' in it, it is a range of item IDs.  Otherwise it is
            // a single id.
            if (strpos($expr, '-') !== false) {
                list($start, $finish) = explode('-', $expr);

                // Naughty naughty koolaid, no SQL injection for you.
                $start  = (int) trim($start);
                $finish = (int) trim($finish);

                $wheres[] = "(beam_internet_archive_records.id BETWEEN $start AND $finish)";

                // It is a single id.
            } else {
                $id = (int) trim($expr);
                $wheres[] = "(beam_internet_archive_records.id = $id)";
            }
        }

        $where = join(' OR ', $wheres);

        $select->where('('.$where.')');
    }

    /**
     * Filter records to beam up based on their status.
     *
     * @param Zend_Db_Select
     * @param string $recordType Record type to filter by.
     * @return void
     */
    public function filterByRecordType($select, $recordType)
    {
        $select->where('beam_internet_archive_records.record_type = ?', $recordType);
    }

    /**
     * Filter records to beam up based on their status.
     *
     * @param Zend_Db_Select
     * @param array|string $status Status to filter by.
     * @return void
     */
    public function filterByStatus($select, $status)
    {
        if (is_array($status)) {
            $select->where('beam_internet_archive_records.status in (?)', $status);
        }
        else {
            $select->where('beam_internet_archive_records.status = ?', $status);
        }
    }

    /**
     * Filter records to beam up based on their remote status.
     *
     * @param Zend_Db_Select
     * @param array|string $remoteStatus Remote status to filter by.
     * @return void
     */
    public function filterByProcess($select, $process)
    {
        if (is_array($process)) {
            $select->where('beam_internet_archive_records.process in (?)', $process);
        }
        else {
            $select->where('beam_internet_archive_records.process = ?', $process);
        }
    }

    /**
     * Filter records to beam up based on whether or not they should be indexed.
     *
     * @param Zend_Db_Select
     * @param boolean Whether or not to retrieve only public beams.
     * @return void
     */
    public function filterByPublic($select, $isPublic)
    {
        if ($isPublic) {
            $select->where('beam_internet_archive_records.public = ' . BeamInternetArchiveRecord::IS_PUBLIC);
        } else {
            $select->where('beam_internet_archive_records.public = ' . BeamInternetArchiveRecord::IS_PRIVATE);
        }
    }

    /**
     * Join table of items to improve filters.
     *
     * @param Zend_Db_Select
     * @return void
     */
    private function _joinTableItems($select)
    {
        $select->joinInner(
            array('items' => $this->getDb()->Items),
            "beam_internet_archive_records.record_type = 'Item' AND beam_internet_archive_records.record_id = items.id",
            array());
    }

    /**
     * Join table of files to improve filters.
     *
     * @param Zend_Db_Select
     * @return void
     */
    private function _joinTableFiles($select)
    {
        $select->joinInner(
            array('files' => $this->getDb()->Items),
            "beam_internet_archive_records.record_type = 'File' AND beam_internet_archive_records.record_id = files.id",
            array());
    }

    /**
     * Filter records to beam up based on whether or not items are public.
     *
     * @param Zend_Db_Select
     * @param boolean Whether or not to retrieve only public items.
     * @return void
     */
    public function filterByItemsPublic($select, $isPublic)
    {
        $this->_joinTableItems($select);
        if ($isPublic) {
            $select->where('items.public = 1');
        } else {
            $select->where('items.public = 0');
        }
    }

    /**
     * Filter records to beam up based on whether or not items are featured.
     *
     * @param Zend_Db_Select
     * @param boolean Whether or not to retrieve only featured beams.
     * @return void
     */
    public function filterByItemsFeatured($select, $isFeatured)
    {
        $this->_joinTableItems($select);
        if ($isFeatured) {
            $select->where('items.featured = 1');
        } else {
            $select->where('items.featured = 0');
        }
    }

    /**
     * Filter records to beam up based on items collection.
     *
     * @param Zend_Db_Select
     * @param Collection|integer|string Either a Collection object, the
     *   collection ID, or the name of the collection.
     * @return void
     */
    public function filterByItemsCollection($select, $collection)
    {
        $this->_joinTableItems($select);
        $select->joinInner(
            array('collections' => $this->getDb()->Collection),
            'items.collection_id = collections.id',
            array());

        if ($collection instanceof Collection) {
            $select->where('collections.id = ?', $collection->id);
        } else if (is_numeric($collection)) {
            $select->where('collections.id = ?', $collection);
        } else {
            $select->where('collections.name = ?', $collection);
        }
    }

    /**
     * Filter records to beam up based on item types.
     *
     * @param Zend_Db_Select
     * @param Type|integer|string Type object, Type ID or Type name
     * @return void
     */
    public function filterByItemsItemType($select, $type)
    {
        $this->_joinTableItems($select);
        $select->joinInner(
            array('item_types' => $this->getDb()->ItemType),
            'items.item_type_id = item_types.id',
            array());

        if ($type instanceof ItemType) {
            $select->where('item_types.id = ?', $type->id);
        } else if (is_numeric($type)) {
            $select->where('item_types.id = ?', $type);
        } else {
            $select->where('item_types.name = ?', $type);
        }
    }

    /**
     * Filter records to beam up based on the user who own the item.
     *
     * @param Zend_Db_Select
     * @param integer $userId  ID of the User to filter by
     * @return void
     */
    public function filterByItemsUser($select, $userId, $isUser = true)
    {
        $this->_joinTableItems($select);
        $select->where('items.owner_id = ?', $userId);
    }

    /**
     * Possible options: public, publicItem, user, featured, collection,
     * type, tag, excludeTags, search, range, advanced, hasImage,
     *
     * @param Omeka_Db_Select
     * @param array
     * @return void
     */
    public function applySearchFilters($select, $params)
    {
        foreach ($params as $paramName => $paramValue) {
            if ($paramValue === null || (is_string($paramValue) && trim($paramValue) == '')) {
                continue;
            }

            $boolean = new Omeka_Filter_Boolean;

            switch ($paramName) {
                case 'range':
                    $this->filterByRange($select, $paramValue);
                    break;

                case 'record_type':
                    $alnum = new Zend_Filter_Alnum(true);
                    $this->filterByRecordType($select, $alnum->filter($paramValue));
                    break;

                case 'status':
                    $alnum = new Zend_Filter_Alnum(true);
                    if (is_array($paramValue)) {
                        foreach ($paramValue as $key => $value) {
                            $paramValue[$key] = $alnum->filter($value);
                        }
                        $this->filterByStatus($select, $paramValue);
                    }
                    else {
                        $this->filterByStatus($select, $alnum->filter($paramValue));
                    }
                    break;

                case 'process':
                    $alnum = new Zend_Filter_Alnum(true);
                    if (is_array($paramValue)) {
                        foreach ($paramValue as $key => $value) {
                            $paramValue[$key] = $alnum->filter($value);
                        }
                        $this->filterByProcess($select, $paramValue);
                    }
                    else {
                        $this->filterByProcess($select, $alnum->filter($paramValue));
                    }
                    break;

                case 'public':
                    $this->filterByPublic($select, $boolean->filter($paramValue));
                    break;

                case 'items_public':
                    $this->filterByItemsPublic($select, $boolean->filter($paramValue));
                    break;

                case 'items_featured':
                    $this->filterByItemsFeatured($select, $boolean->filter($paramValue));
                    break;

                case 'items_collection':
                    $this->filterByItemsCollection($select, $paramValue);
                    break;

                case 'items_itemtype':
                    $this->filterByItemsItemType($select, $paramValue);
                    break;

                case 'items_user':
                    $this->filterByItemsUser($select, $paramValue);
                    break;
            }
        }

        // TODO
        // $this->filterBySearch($select, $params);

        // If we are returning the data itself, we need to group by the beam ID.
        $select->group('beam_internet_archive_records.id');
    }

    /**
     * Enables sorting based on ElementSet,Element field strings.
     *
     * @param Omeka_Db_Select $select
     * @param string $sortField Field to sort on
     * @param string $sortDir Sorting direction (ASC or DESC)
     */
    public function applySorting($select, $sortField, $sortDir)
    {
        parent::applySorting($select, $sortField, $sortDir);

        $db = $this->getDb();
        $fieldData = explode(',', $sortField);
        if (count($fieldData) == 2) {
            $this->_joinTableItems($select);
            $element = $db->getTable('Element')->findByElementSetNameAndElementName($fieldData[0], $fieldData[1]);
            if ($element) {
                $select->joinLeft(array('et_sort' => $db->ElementText),
                                  "et_sort.record_id = items.id AND et_sort.record_type = 'Item' AND et_sort.element_id = {$element->id}",
                                  array())
                       ->group('items.id')
                       ->order(array("IF(ISNULL(et_sort.text), 1, 0) $sortDir",
                                     "et_sort.text $sortDir"));
            }
        } else {
            if ($sortField == 'random') {
                $select->order('RAND()');
            }
        }
    }

    /**
     * Retrieve a single record given an ID.
     *
     * @param integer $id
     * @return BeamInternetArchiveRecord|false
     */
    public function find($id)
    {
        return $this->_unserialize(parent::find($id));
    }

    /**
     * Find rows for multiple ids.
     *
     * @param array $ids
     * @return array of BeamInternetArchiveRecord record.
     */
    public function findMultiple($ids)
    {
        $select = $this->getSelect()->where('id in (' . implode(',', $ids) . ')');
        return $this->_unserializeMultiple($this->fetchObjects($select));
    }

    /**
     * Get a set of objects corresponding to all the rows in the table.
     *
     * WARNING: This will be memory intensive and is thus not recommended for
     * large data sets.
     *
     * @return array Array of records.
     */
    public function findAll()
    {
        return $this->_unserializeMultiple(parent::findAll());
    }

    /**
     * Retrieve a set of model objects based on a given number of parameters
     *
     * @uses Omeka_Db_Table::getSelectForFindBy()
     * @param array $params A set of parameters by which to filter the objects
     * that get returned from the database.
     * @param integer $limit Number of objects to return per "page".
     * @param integer $page Page to retrieve.
     * @return array|null The set of objects that is returned
     */
    public function findBy($params = array(), $limit = null, $page = null)
    {
        return $this->_unserializeMultiple(parent::findBy($params, $limit, $page));
    }

    /**
     * Find a row for an item by item id.
     *
     * @param integer $itemId
     * @return BeamInternetArchiveRecord record|null.
     */
    public function findByItemId($itemId)
    {
        return $this->findByRecordTypeAndRecordId(BeamInternetArchiveRecord::RECORD_TYPE_ITEM, $itemId);
    }

    /**
     * Find a row for a file by file id.
     *
     * @param integer $fileId
     * @return BeamInternetArchiveRecord record|null.
     */
    public function findByFileId($fileId)
    {
        return $this->findByRecordTypeAndRecordId(BeamInternetArchiveRecord::RECORD_TYPE_FILE, $fileId);
    }

    /**
     * Find a row by record type and record id.
     *
     * @param string $recordType
     * @param integer $recordId
     * @return BeamInternetArchiveRecord record|null.
     */
    public function findByRecordTypeAndRecordId($recordType, $recordId)
    {
        $select = $this->getSelect()
            ->where('record_type = ?', (string) $recordType)
            ->where('record_id = ?', (int) $recordId);

        return $this->_unserialize($this->fetchObject($select));
    }

    /**
     * Find a row for an item by a file id.
     *
     * @param integer $fileId
     * @return BeamInternetArchiveRecord record|null.
     * @todo To be improved with one request.
     */
    public function findBeamOfItemByFileId($fileId)
    {
        $file = get_record_by_id('File', $fileId);
        if (!$file) {
            return null;
        }
        return $this->findByItemId($file->item_id);
    }

    /**
     * Find all rows for files attached to an item.
     *
     * @param integer $itemId
     * @return array of records.
     * @todo To be improved with one request.
     */
    public function findBeamsOfFilesByItemId($itemId)
    {
        $beam = $this->findByItemId($itemId);
        if (!$beam) {
            return array();
        }

        $select = $this->getSelect()
            ->where('required_beam_id = ?', $beam->id);

        return $this->_unserializeMultiple($this->fetchObjects($select));
    }

    /**
     * Find all rows for files attached to an item.
     *
     * @param integer $id record identifier.
     * @return array of records.
     */
    public function findBeamsOfAttachedFiles($id)
    {
        $select = $this->getSelect()
            ->where('required_beam_id = ?', $id);

        return $this->_unserializeMultiple($this->fetchObjects($select));
    }

    /**
     * Normalize a record get from table.
     *
     * @param object $record
     * @return $record
     */
    private function _unserialize($record)
    {
        if ($record) {
            $record->remote_metadata = json_decode($record->remote_metadata);
        }
        return $record;
    }

    /**
     * Normalize a set of records get from table.
     *
     * @param array $records Array of records
     * @return $records
     */
    private function _unserializeMultiple($records)
    {
        if (is_array($records)) {
            foreach ($records as $key => $record) {
                $record->remote_metadata = json_decode($record->remote_metadata);
                $records[$key] = $record;
            }
        }
        return $records;
    }
}
