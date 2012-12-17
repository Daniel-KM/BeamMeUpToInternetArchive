<?php

/**
 * The index controller class.
 *
 * @package BeamMeUpToInternetArchive
 */
class BeamMeUpToInternetArchive_IndexController extends Omeka_Controller_AbstractActionController
{
    // List of beam records to process.
    private $_beams = array();

    public function init()
    {
        // Set the model class so this controller can perform some functions,
        // such as $this->findById()
        $this->_helper->db->setDefaultModelName('BeamInternetArchiveRecord');
    }

    /**
     * Browse the items.  Encompasses search, pagination, and filtering of
     * request parameters.  Should perhaps be split into a separate
     * mechanism.
     *
     * @return void
     */
    public function browseAction()
    {
        if (!$this->_getParam('sort_field')) {
            $this->_setParam('sort_field', 'id');
            $this->_setParam('sort_dir', 'd');
        }

        //Must be logged in to view items specific to certain users
        if ($this->_getParam('user') && !$this->_helper->acl->isAllowed('browse', 'Users')) {
            $this->_helper->flashMessenger('May not browse by specific users.');
            $this->_setParam('user', null);
        }

        parent::browseAction();
    }

    /**
     * Retrieve the number of items to display on any given browse page.
     * This can be modified as a query parameter provided that a user is
     * actually logged in.
     *
     * @return integer
     */
    public function _getBrowseRecordsPerPage()
    {
        //Retrieve the number from the options table
        $options = $this->getFrontController()->getParam('bootstrap')
                          ->getResource('Options');

        if (is_admin_theme()) {
            $perPage = (int) $options['per_page_admin'];
        } else {
            $perPage = (int) $options['per_page_public'];
        }

        // If users are allowed to modify the # of items displayed per page,
        // then they can pass the 'per_page' query parameter to change that.
        if ($this->_helper->acl->isAllowed('modifyPerPage', 'Items') && ($queryPerPage = $this->getRequest()->get('per_page'))) {
            $perPage = $queryPerPage;
        }

        if ($perPage < 1) {
            $perPage = null;
        }

        return $perPage;
    }

    public function beamMeUpAction()
    {
        $this->_prepareRecord(BeamInternetArchiveRecord::STATUS_TO_BEAM_UP);
    }

    public function updateAction()
    {
        $this->_prepareRecord(BeamInternetArchiveRecord::STATUS_TO_UPDATE);
    }

    public function removeAction()
    {
        $this->_prepareRecord(BeamInternetArchiveRecord::STATUS_TO_REMOVE);
    }

    public function _prepareRecord($status = BeamInternetArchiveRecord::STATUS_TO_BEAM_UP)
    {
        $beam = $this->_getRequestedRecord();
        if (!$beam) {
            throw new Omeka_Controller_Exception_404;
        }

        if (!$beam->hasRecord() && $status != BeamInternetArchiveRecord::STATUS_TO_REMOVE) {
            $message = __('%s #%d is deleted from the base. You can only remove it.', $beam->record_type, $beam->record_id);
            $message = __('Beam me up to Internet Archive: %s', $message);
            $this->_helper->flashMessenger($message);
            $this->_helper->redirector->goto('browse');
            return;
        }

        // Required beam records are automatically added if needed.
        $this->_queueBeam($beam, $status);

        // Do this check after that status are set.
        if ($beam->hasPendingTasks() && $beam->isToUpdateOrToRemove()) {
            $message = __('Process is postponed for %s #%d: tasks are pending.', $beam->record_type, $beam->record_id);
            $message = __('Beam me up to Internet Archive: %s', $message);
            $this->_helper->flashMessenger($message);
            $this->_helper->redirector->goto('browse');
            return;
        }

        $this->_prepareJob();

        // Redirect to the page of the beamed up record.
        $pluralName = $this->view->pluralize(strtolower($beam->record_type));
        $this->_helper->redirector->gotoUrl($pluralName . '/show/' . $beam->record_id);
    }

    /**
     * Batch processing of records.
     *
     * @return void.
     */
    public function batchEditAction()
    {
        $beamIds = $this->_getParam('beams');
        if (empty($beamIds)) {
            $this->_helper->flashMessenger(__('You must choose some records to batch process them.'), 'error');
            $this->_helper->redirector->goto('browse');
            return;
        }
        $this->view->assign(compact('beamIds'));

        if ($this->_getParam('submit-batch-beam-up')) {
            $status = BeamInternetArchiveRecord::STATUS_TO_BEAM_UP;
            $message = __('Selected records are queued to be beamed up or updated.');
        }
        elseif ($this->_getParam('submit-batch-remove')) {
            $status = BeamInternetArchiveRecord::STATUS_TO_REMOVE;
            $message = __('Selected records are queued to be removed.');
        }
        else {
            $this->_helper->flashMessenger(__('You must choose the process to batch.'), 'error');
            $this->_helper->redirector->goto('browse');
            return;
        }

        foreach ($beamIds as $key => $beamId) {
            $beam = get_record_by_id('BeamInternetArchiveRecord', $beamId);
            if ($beam) {
                // Queue only a record that has a record or is to be removed.
                if ($beam->hasRecord() || $status == BeamInternetArchiveRecord::STATUS_TO_REMOVE) {
                    // Required beam records are automatically added if needed.
                    $this->_queueBeam($beam, $status);
                }
            }
        }

        $message = __('Beam me up to Internet Archive: %s', $message);
        $this->_helper->flashMessenger($message);

        $this->_prepareJob();

        $this->_helper->redirector->goto('browse');
    }

    /**
     * Batch processing of queued records (automatically selected).
     *
     * @return void.
     */
    public function batchQueueAction()
    {
        // Process queued records.
        if ($this->_getParam('submit-batch-queue')) {
            // TODO Complete params with sort.
            $beams = get_records('BeamInternetArchiveRecord', array('process' => array(
                BeamInternetArchiveRecord::PROCESS_QUEUED_WAITING_BUCKET,
                BeamInternetArchiveRecord::PROCESS_QUEUED,
            )), get_option('beamia_max_simultaneous_process'));

            foreach ($beams as $key => $beam) {
                $this->_beams[$beam->id] = $beam->id;
            }
        }

        // Process failed records.
        else {
            // TODO Complete params with sort.
            $beams = get_records('BeamInternetArchiveRecord', array('process' => array(
                BeamInternetArchiveRecord::PROCESS_FAILED_CONNECTION,
                BeamInternetArchiveRecord::PROCESS_FAILED_RECORD,
            )), get_option('beamia_max_simultaneous_process'));

            foreach ($beams as $key => $beam) {
                $beam->saveWithProcess(BeamInternetArchiveRecord::PROCESS_QUEUED);
                $this->_beams[$beam->id] = $beam->id;
            }
        }

        $this->_prepareJob();

        $this->_helper->redirector->goto('browse');
    }

    /**
     * Goes to results page based off value in text input.
     */
    public function paginationAction()
    {
        $pageNumber = (int)$_POST['page'];
        $baseUrl = $this->getRequest()->getBaseUrl().'/index/browse/';
        $request = Zend_Controller_Front::getInstance()->getRequest();
        $requestArray = $request->getParams();
        if($currentPage = $this->current) {
            $paginationUrl = $baseUrl.$currentPage;
        } else {
            $paginationUrl = $baseUrl;
        }
    }

    /**
     * Return a list of the current search beams filters in use.
     *
     * @uses Omeka_View_Helper_SearchFilters::searchFilters()
     * @param array $params Params to replace the ones read from the request.
     * @return string
     */
    public function search_filters(array $params = null)
    {
        return get_view()->itemSearchFilters($params);
    }

    /**
     * Get the requested beam and assign it if any.
     *
     * @return BeamInternetArchiveRecord object.
     */
    private function _getRequestedRecord()
    {
        $request = Zend_Controller_Front::getInstance()->getRequest();
        $id = parse_url($request->getRequestUri(), PHP_URL_PATH);
        $id = pathinfo($id, PATHINFO_FILENAME);
        $beam = get_record_by_id('BeamInternetArchiveRecord', $id);
        if ($beam) {
            $this->view->assign(array('BeamInternetArchiveRecord' => $beam));
        }
        return $beam;
    }

    /**
     * Get a beam for an item or a file. If none exists, create a default one.
     *
     * @param Item|File $record Record to get beam record for.
     *
     * @return BeamInternetArchiveRecord object.
     */
    private function _getBeamForRecord($record)
    {
        // Check if a beam exists for this record.
        $beam = $this->_db->getTable('BeamInternetArchiveRecord')->findByRecordTypeAndRecordId(get_class($record), $record->id);

        // If no beam is found, set default record for item.
        if (empty($beam)) {
            $beam = new BeamInternetArchiveRecord;
            $beam->setBeam(get_class($record), $record->id);
            $beam->save();
        }
        return $beam;
    }

    /**
     * Prepare a beam record.
     *
     * @param BeamInternetArchiveRecord $beam Record to prepare.
     * @param string $status Status to set.
     *
     * @return void.
     */
    private function _queueBeam($beam, $status = BeamInternetArchiveRecord::STATUS_TO_BEAM_UP)
    {
        // The status will be automatically set to 'to update' if the record is
        // already beamed up. The process will be automatically set to queue.
        $beam->saveWithStatus($status);
        $this->_beams[$beam->id] = $beam->id;

        switch ($status) {
            case BeamInternetArchiveRecord::STATUS_TO_BEAM_UP:
            case BeamInternetArchiveRecord::STATUS_TO_UPDATE:
                // Set the parent record if needed (no update).
                if (!empty($beam->required_beam_id)) {
                    $beamItem = get_record_by_id('BeamInternetArchiveRecord', $beam->required_beam_id);
                    if (!$beamItem->isBeamedUp()) {
                        $beamItem->saveWithStatus(BeamInternetArchiveRecord::STATUS_TO_BEAM_UP);
                        $this->_beams[$beamItem->id] = $beamItem->id;
                    }
                }
                break;

            case BeamInternetArchiveRecord::STATUS_TO_REMOVE:
                // Remove all files attached to item if needed.
                if ($beam->isBeamForItem()) {
                    // Warning: don't use item->getFiles(), because item may be deleted.
                    $beamFiles = $this->_db->getTable('BeamInternetArchiveRecord')->findBeamsOfFilesByItemId($beam->record_id);
                    foreach ($beamFiles as $key => $beamFile) {
                        $beamFile->saveWithStatus(BeamInternetArchiveRecord::STATUS_TO_REMOVE);
                        $this->_beams[$beamFile->id] = $beamFile->id;
                    }
                }
                break;
        }
    }

    /**
     * Prepare and send job if possible.
     */
    private function _prepareJob() {
        if (count($this->_beams) == 0) {
            $message = __('No beam record to process.');
            $message = __('Beam me up to Internet Archive: %s', $message);
            $this->_helper->flashMessenger($message);
            return;
        }

        $message = __('A background job is launched.')
            . ' ' . __('See its result in the Internet Archive menu or directly in the record view.');
        $message = __('Beam me up to Internet Archive: %s', $message);
        $this->_helper->flashMessenger($message);

        $options = array();
        $options['beams'] = $this->_beams;

        // Prepare a job for all these beams.
        $jobDispatcher = Zend_Registry::get('bootstrap')->getResource('jobs');
        // Use long running job if server supports it (php-cli with curl).
        if (get_option('beamia_job_type') == 'long running') {
            $jobDispatcher->setQueueNameLongRunning('beamia_uploads');
            $jobDispatcher->sendLongRunning('Job_BeamUploadInternetArchive', $options);
        }
        // Standard jobs.
        else {
            $jobDispatcher->setQueueName('beamia_uploads');
            $jobDispatcher->send('Job_BeamUploadInternetArchive', $options);
        }
    }
}
