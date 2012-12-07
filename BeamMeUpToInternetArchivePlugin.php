<?php
/**
 * Posts items to the Internet Archive as they are saved.
 *
 * @see README.md
 *
 * @copyright Daniel Berthereau for Pop Up Archive, 2012
 * @copyright Daniel Vizzini and Dave Lester for Pop Up Archive, 2012
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2-en.txt
 * @license http://www.gnu.org/licenses/gpl-3.0.txt
 * @package BeamMeUpToInternetArchive
 *
 * This is a development version.
 *
 * * Things to do to finish the plugin:
 * done Replace most of throw exceptions by logs.
 * done Full check of upload progress.
 * done Update sent status of files (every time an item or a file is displayed).
 * done Relaunch processes if failed or not sent (not automatically in order
 *   to allow checks).
 * @todo Check if pbcore metadata are used.
 * done Change echo by return.
 *
 * * Things to do to improve plugin:
 * done Check files with the same original name (flat directory on IA).
 * done Check item name for bucket (should be unique, else it's updated).
 * @todo Manage update of item and files.
 * @todo Manage deletion of item and files.
 * @todo Make job to use queued beams in table if there are no direct job.
 * done Status page of all files and items.
 * done Improve after save hook in order to not use post.
 * @todo Finish to clean indexController and javascripts, copy of
 *   ItemsController and associated scripts.
 *
 * * Less important, just for better MVC or ergonomy.
 * @todo Individual select for files of an item when editing item.
 * @todo Individual beam me up when editing file.
 * done Beam controller.
 * @todo Beam builder (from Beam model).
 * @todo Curl object builder (or Zend http).
 * @todo Beam as a mixin of item and file.
 * @todo Follow percent of progress when uploading records (via curl).
 * nottodo Save direct url to file in database [no: use /download/ path].
 * @todo Merge settings and remote_metadata columns in one metadata column?
 * nottodo Merge local status and remote_status in table [no: too different].
 * @todo Add beams search filters for a better status view.
 * @todo Add a check for all files and items that haven't a matching beam
 *   (currently, this is automatically done when displaying an item or a file).
 * @todo Optimize remote checking.
 */

require_once dirname(__FILE__) . '/helpers/BeamMeUpToInternetArchiveFunctions.php';

/**
 * Contains code used to integrate the plugin into Omeka.
 *
 * @package BeamMeUpToInternetArchive
 */

class BeamMeUpToInternetArchivePlugin extends Omeka_Plugin_AbstractPlugin
{
    /**
     * @var array This plugin's hooks.
     */
    protected $_hooks = array(
        'install',
        'uninstall',
        'admin_append_to_plugin_uninstall_message',
        'define_acl',
        'config_form',
        'config',
        'after_save_item',
        'admin_theme_header',
        'admin_items_show_sidebar',
        'admin_items_form_files',
        'admin_files_show_sidebar',
    );

    /**
     * @var array This plugin's filters.
     */
    protected $_filters = array(
        'admin_navigation_main',
        'admin_items_form_tabs',
    );

    /**
     * @var array This plugin's options.
     */
    protected $_options = array(
        'beamia_post_to_internet_archive' => true,
        'beamia_index_at_internet_archive' => true,
        'beamia_S3_access_key' => 'Enter S3 access key here',
        'beamia_S3_secret_key' => 'Enter S3 secret key here',
        'beamia_collection_name' => 'Please contact the Internet Archive',
        'beamia_media_type' =>  'Please contact the Internet Archive',
        'beamia_bucket_prefix' => 'omeka',
        'beamia_job_type' => 'normal',
        // Max time to check success for the bucket creation.
        'beamia_max_time_item' => 300,
    );

    /**
     * Installs the plugin.
     */
    public function hookInstall()
    {
        // Set default bucket prefix: omeka_SERVERNAME.
        $bucketPrefix = str_replace('.', '_', preg_replace('/www/', '', $_SERVER['SERVER_NAME'], 1));
        $this->_options['beamia_bucket_prefix'] = 'omeka' . ((strpos($bucketPrefix, '_') == 0) ? '' : '_') . $bucketPrefix;

        $this->_installOptions();

        $this->_installTable();

        // Fill the table with all existing items and files in order to get
        // a record to beam by default, with status set to "not to beam up".
        $this->_fillTable();
    }

    /**
     * Uninstalls the plugin.
     */
    public function hookUninstall()
    {
        $sql = "DROP TABLE IF EXISTS `{$this->_db->BeamInternetArchiveBeam}`;";
        $this->_db->query($sql);

        $this->_uninstallOptions();
    }

    /**
     * Warns before the uninstallation of the plugin.
     */
    public static function hookAdminAppendToPluginUninstallMessage()
    {
        return '<p><strong>' . __('Warning') . '</strong>:<br />'
        . __('You will lost all links between your Omeka items and items beamed up to Internet Archive.') . '<br />'
        . __('Uploaded files will continue to be available as currently on Internet Archive.') . '</p>';
    }

    /**
     * Helper for install and upgrade.
     *
     * @return void
     */
    private function _installTable()
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS `{$this->_db->BeamInternetArchiveBeam}` (
              `id` int unsigned NOT NULL auto_increment COMMENT 'Identifier (id of an item should be lower than ids of its attached files)',
              `record_type` varchar(50) NOT NULL COMMENT 'Omeka record type of the record to upload',
              `record_id` int unsigned NOT NULL COMMENT 'Identifier of the record to upload',
              `required_beam_id` int unsigned DEFAULT 0 COMMENT 'Identifier of the beam, used when the record depends on another one',
              `status` varchar(255) collate utf8_unicode_ci DEFAULT 'not to beam up' COMMENT 'Uploading status of the record',
              `public` tinyint DEFAULT 0 COMMENT 'Make public or not',
              `settings` text collate utf8_unicode_ci COMMENT 'Serialized list of parameters used to upload the record',
              `remote_id` varchar(256) DEFAULT '' COMMENT 'Identifier of the record on the remote site',
              `remote_status` varchar(255) collate utf8_unicode_ci DEFAULT 'not applicable' COMMENT 'Last remote status of the uploaded record',
              `remote_metadata` text collate utf8_unicode_ci COMMENT 'Json object of last informations get from remote site',
              `remote_checked` timestamp NOT NULL  DEFAULT '0000-00-00 00:00:00' COMMENT 'Last check of the record on the remote site',
              `modified` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP COMMENT 'Last modification of the record',
              PRIMARY KEY  (`id`),
              KEY `record_type_record_id` (`record_type`, `record_id`),
              KEY `record_type` (`record_type`),
              KEY `required_beam_id` (`required_beam_id`),
              KEY `status` (`status`),
              KEY `remote_status` (`remote_status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
        ";
        $this->_db->query($sql);
    }

    /**
     * Helper for install and upgrade to fill the beam table with all existing
     * items and files, in order to get a default record for all of them, with
     * status set to "not to beam up".
     *
     * @return void
     */
    private function _fillTable()
    {
        // Direct queries are used to avoid overload. No need to check for
        // unique record ids, because this is an installation.

        // Query for item. All id for items should be lower than the attached
        // those for the attached files.
        $sql = "
            INSERT INTO `{$this->_db->BeamInternetArchiveBeam}` (record_type, record_id)
            SELECT 'Item', id
            FROM `{$this->_db->Item}`
        ";
        $this->_db->query($sql);

        // Query for files. This is not a problem if attached files are not
        // inserted just after the parent item, because this is managed in view.
        $sql = "
            INSERT INTO `{$this->_db->BeamInternetArchiveBeam}` (record_type, record_id, required_beam_id)
            SELECT 'File', files.id, beams.id
            FROM `{$this->_db->File}` files
                JOIN `{$this->_db->BeamInternetArchiveBeam}` beams
                    ON beams.record_type = 'Item'
                        AND beams.record_id = files.item_id
        ";
        $this->_db->query($sql);
    }

    /**
     * Define the ACL.
     *
     * @param Omeka_Acl
     */
    public function hookDefineAcl($args)
    {
        $acl = $args['acl'];

        $indexResource = new Zend_Acl_Resource('BeamMeUpToInternetArchive_Index');
        $acl->add($indexResource);

        $acl->allow(array('super', 'admin'), array('BeamMeUpToInternetArchive_Index'));
    }

    /**
     * Displays configuration form.
     *
     * @return void
     */
    public function hookConfigForm()
    {
        include('config_form.php');
    }

    /**
     * Saves plugin configuration.
     *
     * @return void
     */
    public function hookConfig($args)
    {
        $post = $args['post'];

        set_option('beamia_post_to_internet_archive', $post['BeamiaPostToInternetArchive']);
        set_option('beamia_index_at_internet_archive', $post['BeamiaIndexAtInternetArchive']);
        set_option('beamia_S3_access_key', trim($post['BeamiaS3AccessKey']));
        set_option('beamia_S3_secret_key', trim($post['BeamiaS3SecretKey']));
        set_option('beamia_collection_name', trim($post['BeamiaCollectionName']));
        set_option('beamia_media_type', trim($post['BeamiaMediaType']));
        set_option('beamia_bucket_prefix', trim($post['BeamiaBucketPrefix']));
        set_option('beamia_job_type', ($post['BeamiaJobType']) ? 'long running' : 'normal');
        set_option('beamia_max_time_item', (int) $post['BeamiaMaxTimeItem']);
    }

    /**
     * Prepare records to beam up to Internet Archive.
     *
     * The process occurs only when all files are saved, at the end of the
     * creation of item. All items and files have a matching record in beam
     * table.
     *
     * @return void
     */
    public function hookAfterSaveItem($args)
    {
        // Currently, update of a record is not managed.
        if (!$args['insert']) {
            return;
        }

        // Create beam records even if the item is not uploaded. 
        $post = $args['post'];
        $item = $args['record'];
        $status = ($post['BeamiaPostToInternetArchive'] == 1) ?
            BeamInternetArchiveBeam::STATUS_TO_BEAM_UP :
            BeamInternetArchiveBeam::STATUS_NOT_TO_BEAM_UP;
        $options = array();
        $errorMessage = '';

        $beamItem = $this->_setBeamForItem($item);
        try {
            $beamItem->setFullBeam();
        } catch (Exception_BeamInternetArchiveBeam $e) {
            $errorMessage = $e->getMessage();
        } catch (Exception_BeamInternetArchiveConnect $e) {
            $errorMessage = $e->getMessage();
        }
        $beamItem->saveWithStatus($status);
        $options['beams']['item'] = $beamItem->id;

        $files = $item->getFiles();
        foreach ($files as $key => $file) {
            $beam = $this->_setBeamForFile($file, $beamItem);
            try {
                $beam->setFullBeam();
            } catch (Exception_BeamInternetArchiveBeam $e) {
                $errorMessage = $e->getMessage();
            } catch (Exception_BeamInternetArchiveConnect $e) {
                $errorMessage = $e->getMessage();
            }
            $beam->saveWithStatus($status);
            $options['beams'][] = $beam->id;
        }

        // In case of error, messages are logged and the job is not launched.
        if (!empty($errorMessage)) {
            return;
        }

        if ($post['BeamiaPostToInternetArchive'] == '1') {
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

    /**
     * Adds theme header.
     */
    public function hookAdminThemeHeader($request)
    {
    }

    /**
     * Displays Internet Archive links in admin/show section.
     *
     * @return void
     */
    public function hookAdminItemsShowSidebar($args) {
        $item = $args['item'];

        $output = '';
        $output .= '<div class="info panel">';
        $output .= '<h4>Beam me up to Internet Archive</h4>';
        $output .= '<h5>Status of item</h5>';
        $output .= $this->_listInternetArchiveLinks();
        $output .= '<h5>Status of files</h5>';
        $output .= $this->_listInternetArchiveLinksForFiles();
        $output .= '</div>';

        echo $output;
        return $output;
    }

    /**
     * Displays Internet Archive links in admin/show section.
     *
     * @return void
     */
    public function hookAdminFilesShowSidebar($args) {
        $file = $args['file'];

        $output = '';
        $output .= '<div class="info panel">';
        $output .= '<h4>Beam me up to Internet Archive</h4>';
        $output .= $this->_listInternetArchiveLinksForFile();
        $output .= '</div>';

        echo $output;
        return $output;
    }

    /**
     * Gives user the option to post to the Internet Archive.
     *
     * @return void
     */
    public function hookAdminItemsFormFiles($args) {
        $output = '';
        $output .= '<div class="field">';
        $output .=   '<div id="BeamiaPostToInternetArchive_label" class="one columns alpha">';
        $output .=     get_view()->formLabel('BeamiaPostToInternetArchive', __('Upload to Internet Archive'));
        $output .=   '</div>';
        $output .=   '<div class="inputs">';
        $output .=     get_view()->formCheckbox('BeamiaPostToInternetArchive', true, array('checked' => (boolean) get_option('beamia_post_to_internet_archive')));
        $output .=     '<p class="explanation">';
        $output .=       __('Note that if this box is checked, saving the item may take a while.');
        $output .=     '</p>';
        $output .=   '</div>';
        $output .= '</div>';

        $output .= '<div class="field">';
        $output .=   '<div id="BeamiaIndexAtInternetArchive_label" class="one columns alpha">';
        $output .=     get_view()->formLabel('BeamiaIndexAtInternetArchive', __('Index at Internet Archive'));
        $output .=   '</div>';
        $output .=   '<div class="inputs">';
        $output .=     get_view()->formCheckbox('BeamiaIndexAtInternetArchive', true, array('checked' => (boolean) get_option('beamia_index_at_internet_archive')));
        $output .=     '<p class="explanation">';
        $output .=       __("If you index your items, they will appear on the results of search engines such as Google's.");
        $output .=     '</p>';
        $output .=   '</div>';
        $output .= '</div>';

        echo $output;
        return $output;
    }

    /**
     * Add the plugin main link to the admin main navigation.
     *
     * @param array Navigation array.
     * @return array Filtered navigation array.
     */
    public function filterAdminNavigationMain($nav)
    {
        $nav[] = array(
            'label' => __('Internet Archive'),
            // TODO Currently, need to set the full url in order to get the good
            // link_to_beamia() in browse view (else, the controller name is not
            // added to the url).
            'uri' => url('beam-me-up-to-internet-archive/index/browse'),
            'resource' => 'BeamMeUpToInternetArchive_Index',
            'privilege' => 'browse'
        );
        return $nav;
    }

    /**
     * Add BeamMeUp tab to the edit item page.
     *
     * @return array
     */
    public function filterAdminItemsFormTabs($tabs)
    {
        $tabs['Beam me up to Internet Archive'] = $this->_beamia_form();
        return $tabs;
    }

    /**
     * Each time we save an item, post it to the Internet Archive.
     *
     * @return void
     */
    private function _beamia_form()
    {
        $item = get_current_record('item');

        $output = '';

        $output .= '<div>' . __("If the box at the bottom of the files tab is checked, the files in this item, along with their metadata, will upload to the Internet Archive upon save.") . '</div>' . "<br />\n";
        $output .= '<div>' . __("Note that BeamMeUp may make saving an item take a while, and that it may take additional time for the Internet Archive to post the files after you save.") . '</div>' . "<br />\n";
        $output .= '<div>' . __("To change the upload default or to alter the upload's configuration, visit the plugin's configuration settings on this site.") . '</div>' . "<br />\n";

        if (metadata($item, 'id') == '') {
            $output .= '<div>' . __('Please revisit this tab after you save the item to view its Internet Archive links.') . '</div>' . "<br />\n";
        }
        else {
            $output .= $this->_listInternetArchiveLinks();
            $output .= $this->_listInternetArchiveLinksForFiles();
        }

        return $output;
    }

    /**
     * @return string containing IA links for an item.
     */
    private function _listInternetArchiveLinks()
    {
        $item = get_current_record('item');
        $beam = $this->_getBeam($item);

        $output = '';
        // Update status.
        $beam->checkRemoteStatus();

        $output .= '<div id="beam-list">';
        $output .= '<ul>';
        $output .= '<li>' . __('Status') . ': ' . link_to_beamia_remote_if_any(__($beam->status), array(), $beam) . '</a>' . '</li>';
        if ($beam->isNotToBeamUp()) {
            $output .= '<li>' . __('Remote status') . ': ' . __($beam->remote_status) . '</li>';
        }
        else {
            $output .= '<li>' . __('Remote status') . ': <a href="' . $beam->getUrlForTasks() . '" target="_blank">' . __($beam->remote_status) . '</a>';
            if (isset($beam->remote_metadata->pending_tasks)) {
                $output .= ' (' . __('%d pending tasks', count($beam->remote_metadata->pending_tasks)) . ')';
            }
        }

        $output .= '</li>';
        $output .= '</ul>';
        $output .= '</div>';

        return $output;
    }

    /**
     * @return string containing IA links for files attached to an item.
     */
    private function _listInternetArchiveLinksForFiles()
    {
        $item = get_current_record('item');
        $beamItem = $this->_getBeam($item);

        $output = '';
        if ($beamItem->isNotToBeamUp()) {
            $output .= '<div id="beam-list">';
            $output .= '<ul>';
            $output .= '<li>' . __($beamItem->status) . '</li>';
            $output .= '</ul>';
            $output .= '</div>';
            return $output;
        }

        if (!metadata($item, 'has files')) {
            $output .= '<div id="beam-list">';
            $output .= '<ul>';
            $output .= '<li>' . __('No files') . '</li>';
            $output .= '</ul>';
            $output .= '</div>';
            return $output;
        }

        // Update status of files.
        $beamItem->checkRemoteStatusForFilesOfItem();
        $files = $item->getFiles();

        $output .= '<div id="beam-files-list">';
        $output .= '<ul>';
        foreach ($files as $file) {
            $beam = $this->_getBeam($file);
            $output .= '<li>';
            $output .= link_to_file_show(array('class'=>'show', 'title'=>__('View File Metadata')), null, $file) . ': ';
            $output .= '<div>' . link_to_beamia_remote_if_any(__($beam->remote_status), array(), $beam) . '</div>';
            $output .= '</li>';
        }
        $output .= '</ul>';
        $output .= '</div>';

        return $output;
    }

    /**
     * @return string containing IA links for one file.
     */
    private function _listInternetArchiveLinksForFile()
    {
        $file = get_current_record('file');
        $beam = $this->_getBeam($file);

        // Update status of files.
        $beam->checkRemoteStatus();

        $output = '';
        $output .= '<div>' . link_to_beamia_remote_if_any(__($beam->status), array(), $beam) . '</div>';

        return $output;
    }

    /**
     * Build a beam for an item or a file.
     *
     * @param Item|File|integer|null $record. If integer, the record id in the
     *   record type table. If null, get the current record of the recordType.
     * @param string|null $recordType. If string and record is null, get the
     *   current record of this type.
     *
     * @return beam object.
     */
    private function _setBeam($record = null, $recordType = null)
    {
        return $this->_getBeam($record, $recordType);
    }

    /**
     * Build a beam for an item.
     *
     * @param Item|integer|null $item If integer, the item id; if null, the
     *   current item.
     *
     * @return beam object.
     */
    private function _setBeamForItem($record = null)
    {
        return $this->_getBeamItem($record);
    }

    /**
     * Build a beam for a file.
     *
     * @param File|integer|null $file If integer, the file id; if null, the
     *   current file.
     *
     * @return beam object.
     */
    private function _setBeamForFile($record = null)
    {
        return $this->_getBeamFile($record);
    }

    /**
     * Get a beam for a item or a file. If no beam exists, create a default one.
     *
     * @param Item|File|integer|null $record. If integer, the record id in the
     *   record type table. If null, get the current record of the recordType.
     * @param string|null $recordType. If string and record is null, get the
     *   current record of this type.
     *
     * @return beam object.
     */
    private function _getBeam($record = null, $recordType = null)
    {
        if ($record instanceof Item) {
            return $this->_getBeamItem($record);
        }

        if ($record instanceof File) {
            return $this->_getBeamFile($record);
        }

        if (is_integer($record)) {
            if (strtolower($recordType) == 'item') {
                return $this->_getBeamItem($record);
            }
            if (strtolower($recordType) == 'file') {
                return $this->_getBeamFile($record);
            }
        }

        elseif (is_null($record) && !is_null($recordType)) {
            $record = get_current_record($recordType);
            return $this->_getBeam($record);
        }

        throw new Exception_BeamInternetArchiveBeam(__('Beam me up to Internet Archive: Cannot get or create a beam record for anything else than item or file'));
    }

    /**
     * Get a beam record for an item. If no beam exists, create a default one.
     *
     * @param Item|integer|null $item If integer, the item id; if null, the
     *   current item.
     *
     * @return beam object of the item.
     */
    private function _getBeamItem($item = null)
    {
        if ($item === null) {
            $item = get_current_record('item');
        }
        elseif (is_integer($item)) {
            $item = get_record_by_id('item', $item);
        }

        // Check if a beam exists for this item.
        $beam = $this->_db->getTable('BeamInternetArchiveBeam')->findByItemId($item->id);

        // If no beam is found, set default record for item.
        if (empty($beam)) {
            $beam = new BeamInternetArchiveBeam;
            $beam->setBeamForItem($item->id);
            $beam->save();
        }
        return $beam;
    }

    /**
     * Get a beam record for a file. If no beam exists, create a default one
     * for it and for its parent item if needed.
     *
     * @param File|integer|null $file If integer, the file id; if null, the
     *   current file.
     *
     * @return beam object of the file.
     */
    private function _getBeamFile($file = null)
    {
        if ($file === null) {
            $file = get_current_record('file');
        }
        elseif (is_integer($file)) {
            $file = get_record_by_id('file', $file);
        }

        // Check if a beam exists for this file.
        $beam = $this->_db->getTable('BeamInternetArchiveBeam')->findByFileId($file->id);

        // If no beam is found, set default record for file.
        if (empty($beam)) {
            // Check if a beam record exists for the parent item.
            $beamItem = $this->_getBeamItem($file->item_id);

            $beam = new BeamInternetArchiveBeam;
            $beam->setBeamForFileWithRequiredBeamId($file->id, $beamItem->id);
            $beam->save();
        }
        return $beam;
    }
}
