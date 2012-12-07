<?php

/**
 * The index controller class.
 *
 * @package BeamMeUpToInternetArchive
 */
class BeamMeUpToInternetArchive_IndexController extends Omeka_Controller_AbstractActionController
{
    public function init()
    {
        // Set the model class so this controller can perform some functions,
        // such as $this->findById()
        $this->_helper->db->setDefaultModelName('BeamInternetArchiveBeam');
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
        $request = Zend_Controller_Front::getInstance()->getRequest();
        $id = parse_url($request->getRequestUri(), PHP_URL_PATH);
        $id = pathinfo($id, PATHINFO_FILENAME);
        $beam = get_record_by_id('BeamInternetArchiveBeam', $id);
        if (!$beam) {
            throw new Omeka_Controller_Exception_404;
        }
        $this->view->assign(array('BeamInternetArchiveBeam' => $beam));

        $errorMessage = '';

        switch ($beam->status) {
            // Reset record with error or set new record to beam up.
            case BeamInternetArchiveBeam::STATUS_NOT_TO_BEAM_UP:
            case BeamInternetArchiveBeam::STATUS_FAILED_TO_BEAM_UP:
            case BeamInternetArchiveBeam::STATUS_ERROR:
                // For a file, check parent record and change status if needed.
                if ($beam->isBeamForFile()) {
                    $beamItem = get_record_by_id('BeamInternetArchiveBeam', $beam->required_beam_id);
                    switch ($beamItem->status) {
                        // Reset record with error or set new record to beam up.
                        case BeamInternetArchiveBeam::STATUS_TO_DELETE:
                        case BeamInternetArchiveBeam::STATUS_DELETING:
                        case BeamInternetArchiveBeam::STATUS_DELETED:
                            $message = __('This file cannot be beamed up, because status of parent item is "%s".', $beamItem->status);
                            $this->_helper->flashMessenger($message, 'error');
                            break 2;
                    }
                }

                // Parent is ready, so prepare this record to be beamed up.
                $beam->saveWithStatus(BeamInternetArchiveBeam::STATUS_TO_BEAM_UP);
                $message = __('Before to beam up, status of this record was: "%s".', $beam->status);
                $this->_helper->flashMessenger($message, 'info');
                // Continue to switch cases, because we update status.
            case BeamInternetArchiveBeam::STATUS_TO_BEAM_UP:
            case BeamInternetArchiveBeam::STATUS_TO_BEAM_UP_WAITING_BUCKET:
                // Finish to prepare the beam.
                try {
                    $beam->setFullBeam();
                } catch (Exception_BeamInternetArchiveBeam $e) {
                    $errorMessage = $e->getMessage();
                } catch (Exception_BeamInternetArchiveConnect $e) {
                    $errorMessage = $e->getMessage();
                }
                $beam->save();
                // In case of error, messages are logged and the job is not launched.
                if (!empty($errorMessage)) {
                    $message = __('This file cannot be beamed up.' . " \n" . $errorMessage);
                    $this->_helper->flashMessenger($message, 'error');
                    break;
                }

                $result = $this->_beamMeUp();
                // Reload record to update it.
                $beam = get_record_by_id('BeamInternetArchiveBeam', $beam->id);
                if ($result == false && !$beam->isRemoteStatusCheckable()) {
                    $message = __('Error when beaming up this record:')
                        . ' ' . __('Current local status is: "%s".', $beam->status)
                        . ' ' . __('Current remote status is: "%s".', $beam->remote_status)
                        . ' ' . __('Check your connection or see logs for details.');
                    $this->_helper->flashMessenger($message, 'error');
                }
                else {
                    $message = __('Succeed to beam up this record:')
                        . ' ' . __('Current local status is "%s".', $beam->status)
                        . ' ' . __('Current remote status is "%s".', $beam->remote_status);
                    $this->_helper->flashMessenger($message, 'success');
                }
                break;
            case BeamInternetArchiveBeam::STATUS_COMPLETED:
            case BeamInternetArchiveBeam::STATUS_COMPLETED_WAITING_REMOTE:
                $beam->checkRemoteStatus();
                $message = __('This record is already beamed up.')
                    . ' ' . __('Current local status is "%s".', $beam->status)
                    . ' ' . __('Current remote status is "%s".', $beam->remote_status);
                $this->_helper->flashMessenger($message);
                break;
            case BeamInternetArchiveBeam::STATUS_IN_PROGRESS:
            case BeamInternetArchiveBeam::STATUS_UPDATING:
            case BeamInternetArchiveBeam::STATUS_DELETING:
                $beam->checkRemoteStatus();
                $message = __('This record is currently beaming up.')
                    . ' ' . __('Current local status is "%s".', $beam->status)
                    . ' ' . __('Current remote status is "%s".', $beam->remote_status);
                $this->_helper->flashMessenger($message);
                break;

            // TODO In a future release.
            case BeamInternetArchiveBeam::STATUS_TO_UPDATE:
                $message = __('Updating a record is not managed currently.');
                $this->_helper->flashMessenger($message, 'alert');
                break;
            // Note: To delete a bucket is not allowed on Internet Archive.
            case BeamInternetArchiveBeam::STATUS_TO_DELETE:
                $message = __('Deleting a record is not managed currently.');
                $this->_helper->flashMessenger($message, 'alert');
                break;
            case BeamInternetArchiveBeam::STATUS_DELETED:
                $message = __('Deleting a record is not managed currently.');
                $this->_helper->flashMessenger($message, 'alert');
                break;
            default:
        }

        $pluralName = $this->view->pluralize(strtolower($beam->record_type));
        $this->_helper->redirector->gotoUrl($pluralName . '/show/' . $beam->record_id);
    }

    private function _beamMeUp()
    {
        $beam = $this->view->BeamInternetArchiveBeam;

        $options = array();
        $errorMessage = '';

        if ($beam->isBeamForItem()) {
            $options['beams']['item'] = $beam->id;
        }
        // For a file, check parent record and change status if needed.
        else {
            $beamItem = get_record_by_id('BeamInternetArchiveBeam', $beam->required_beam_id);
            switch ($beamItem->status) {
                // Reset record with error or set new record to beam up.
                case BeamInternetArchiveBeam::STATUS_NOT_TO_BEAM_UP:
                case BeamInternetArchiveBeam::STATUS_FAILED_TO_BEAM_UP:
                case BeamInternetArchiveBeam::STATUS_ERROR:
                    $beamItem->saveWithStatus(BeamInternetArchiveBeam::STATUS_TO_BEAM_UP);
                    $message = __('Before to beam up this file, status of the parent item was: "%s".', $beamItem->status);
                    $this->_helper->flashMessenger($message, 'info');
                    // Check will be done by job.
                    $options['beams']['item'] = $beamItem->id;
                    break;
                case BeamInternetArchiveBeam::STATUS_TO_BEAM_UP:
                case BeamInternetArchiveBeam::STATUS_TO_BEAM_UP_WAITING_BUCKET:
                    // Finish to prepare the beam.
                    try {
                        $beamItem->setFullBeam();
                    } catch (Exception_BeamInternetArchiveBeam $e) {
                        $errorMessage = $e->getMessage();
                    } catch (Exception_BeamInternetArchiveConnect $e) {
                        $errorMessage = $e->getMessage();
                    }
                    $beamItem->save();
                    // Check will be done by job.
                    $options['beams']['item'] = $beamItem->id;

                    // Beam is updated because parent beam has been updated too.
                    try {
                        $beam->setFullBeam();
                    } catch (Exception_BeamInternetArchiveBeam $e) {
                        $errorMessage = $e->getMessage();
                    } catch (Exception_BeamInternetArchiveConnect $e) {
                        $errorMessage = $e->getMessage();
                    }
                    $beam->save();
                    break;
                case BeamInternetArchiveBeam::STATUS_IN_PROGRESS:
                case BeamInternetArchiveBeam::STATUS_COMPLETED_WAITING_REMOTE:
                case BeamInternetArchiveBeam::STATUS_COMPLETED:
                case BeamInternetArchiveBeam::STATUS_UPDATING:
                case BeamInternetArchiveBeam::STATUS_TO_UPDATE:
                    // Check will be done by job.
                    $options['beams']['item'] = $beamItem->id;
                    break;
                case BeamInternetArchiveBeam::STATUS_TO_DELETE:
                case BeamInternetArchiveBeam::STATUS_DELETING:
                case BeamInternetArchiveBeam::STATUS_DELETED:
                    $message = __('Status of parent item is deleting. This file cannot be beamed up.');
                    $this->_helper->flashMessenger($message, 'error');
                    return false;
            }
            $options['beams'][] = $beam->id;
        }

        $message = __('A background job is launched for this record.')
            . ' ' . __('See its result in the Internet Archive menu or directly in the record view.');
        $this->_helper->flashMessenger($message);

        // In case of error, messages are logged and the job is not launched.
        if (!empty($errorMessage)) {
            $message = __('This record cannot be beamed up.' . " \n" . $errorMessage);
            $this->_helper->flashMessenger($message, 'error');
            return;
        }

        // Prepare a job for the beam.
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
}
