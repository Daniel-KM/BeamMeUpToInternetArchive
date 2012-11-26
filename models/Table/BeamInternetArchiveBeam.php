<?php

/**
 * The beam_internetarchive table.
 */
class Table_BeamInternetArchiveBeam extends Omeka_Db_Table {
    /**
     * Retrieve a single record given an ID.
     *
     * @param integer $id
     * @return BeamInternetArchiveBeam|false
     */
    public function find($id)
    {
        return $this->_unserialize(parent::find($id));
    }

    /**
     * Find rows for multiple ids.
     *
     * @param array $ids
     * @return array of BeamInternetArchiveBeam record.
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
     * @return BeamInternetArchiveBeam record|null.
     */
    public function findByItemId($itemId)
    {
        return $this->findByRecordTypeAndRecordId('Item', $itemId);
    }

    /**
     * Find a row for a file by file id.
     *
     * @param integer $fileId
     * @return BeamInternetArchiveBeam record|null.
     */
    public function findByFileId($fileId)
    {
        return $this->findByRecordTypeAndRecordId('File', $fileId);
    }

    /**
     * Find a row by record type and record id.
     *
     * @param string $recordType
     * @param integer $recordId
     * @return BeamInternetArchiveBeam record|null.
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
     * @return BeamInternetArchiveBeam record|null.
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
            $record->settings = unserialize($record->settings);
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
                $record->settings = unserialize($record->settings);
                $record->remote_metadata = json_decode($record->remote_metadata);
                $records[$key] = $record;
            }
        }
        return $records;
    }
}
