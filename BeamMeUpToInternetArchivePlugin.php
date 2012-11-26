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
 */

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
        'config_form',
        'config',
        'after_save_item',
        'admin_theme_header',
        'admin_items_show_sidebar',
        'admin_items_form_files',
    );

    /**
     * @var array This plugin's filters.
     */
    protected $_filters = array(
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
    );

    /**
     * Installs the plugin.
     */
    public function hookInstall()
    {
        // Set default bucket prefix: omeka_SERVERNAME.
        $bucketPrefix = get_plugin_ini('BeamMeUpToInternetArchive', 'beamia_bucket_prefix');
        if (empty($bucketPrefix)) {
            $bucketPrefix = str_replace('.', '_', preg_replace('/www/', '', $_SERVER['SERVER_NAME'], 1));
            $bucketPrefix = 'omeka' . ((strpos($bucketPrefix, '_') == 0) ? '' : '_') . $bucketPrefix;
        }
        $this->_options['beamia_bucket_prefix'] = $bucketPrefix;

        $this->_installOptions();

        $this->_installTable();
    }

    /**
     * Uninstalls the plugin.
     */
    public function hookUninstall()
    {
        $this->_uninstallOptions();

        $sql = "DROP TABLE IF EXISTS `{$this->_db->Beammeup_Internetarchive}`;";
        $this->_db->query($sql);
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
    public function hookConfig()
    {
        set_option('beamia_post_to_internet_archive', $_POST['BeamiaPostToInternetArchive']);
        set_option('beamia_index_at_internet_archive', $_POST['BeamiaIndexAtInternetArchive']);
        set_option('beamia_S3_access_key', $_POST['BeamiaS3AccessKey']);
        set_option('beamia_S3_secret_key', $_POST['BeamiaS3SecretKey']);
        set_option('beamia_collection_name', $_POST['BeamiaCollectionName']);
        set_option('beamia_media_type', $_POST['BeamiaMediaType']);
    }

    /**
     * Post Files and metadata of an Omeka Item to the Internet Archive.
     *
     * The process occurs only when all files are saved, at the end of the
     * creation of item.
     *
     * @return void
     */
    public function hookAfterSaveItem($args)
    {
        if ($_POST['BeamiaPostToInternetArchive'] == '1') {
            $item = $args['record'];

            // Beam up files only if there are files. 
            if ($item->fileCount() > 0) {
                // Keep only primitive data types of files to be uploaded.
                $files = $item->getFiles();
                foreach ($files as $key => $file) {
                    $files[$key] = $file->id;
                }

                // Prepare and run job for this item.
                $jobDispatcher = Zend_Registry::get('bootstrap')->getResource('jobs');
                // TODO Currently, long running jobs don't allow curl.
                // $jobDispatcher->setQueueNameLongRunning('beamia_uploads');
                // $jobDispatcher->sendLongRunning('Job_BeamUploadInternetArchive', array(
                $jobDispatcher->setQueueName('beamia_uploads');
                $jobDispatcher->send('Job_BeamUploadInternetArchive', array(
                    'itemId' => $item->id,
                    'files' => $files,
                ));
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
        echo '<div class="info panel">';
        echo '<h4>Beam me up to Internet Archive</h4>';
        echo $this->_listInternetArchiveLinks();
        echo '</div>';
    }

    /**
     * Gives user the option to post to the Internet Archive.
     *
     * @return void
     */
    public function hookAdminItemsFormFiles($args) {
        echo '<div class="field">';
        echo   '<div id="BeamiaPostToInternetArchive_label" class="one columns alpha">';
        echo     get_view()->formLabel('BeamiaPostToInternetArchive', __('Upload to Internet Archive'));
        echo   '</div>';
        echo   '<div class="inputs">';
        echo     get_view()->formCheckbox('BeamiaPostToInternetArchive', true, array('checked' => (boolean) get_option('beamia_post_to_internet_archive')));
        echo     '<p class="explanation">';
        echo       __('Note that if this box is checked, saving the item may take a while.');
        echo     '</p>';
        echo   '</div>';
        echo '</div>';

        echo '<div class="field">';
        echo   '<div id="BeamiaIndexAtInternetArchive_label" class="one columns alpha">';
        echo     get_view()->formLabel('BeamiaIndexAtInternetArchive', __('Index at Internet Archive'));
        echo   '</div>';
        echo   '<div class="inputs">';
        echo     get_view()->formCheckbox('BeamiaIndexAtInternetArchive', true, array('checked' => (boolean) get_option('beamia_index_at_internet_archive')));
        echo     '<p class="explanation">';
        echo       __("If you index your items, they will appear on the results of search engines such as Google's.");
        echo     '</p>';
        echo   '</div>';
        echo '</div>';
    }

    /**
     * Add BeamMeUp tab to the edit item page.
     *
     * @return array
     */
    public function filterAdminItemsFormTabs($tabs)
    {
        $item = get_current_record('item');
        $tabs['Beam me up to Internet Archive'] = $this->_beamia_form($item);
        return $tabs;
    }

    /**
     * Each time we save an item, post to the Internet Archive.
     *
     * @return void
     */
    private function _beamia_form($item)
    {
        $ht = '';
        ob_start();

        echo '<div>' . __("If the box at the bottom of the files tab is checked, the files in this item, along with their metadata, will upload to the Internet Archive upon save.") . '</div>';
        echo "<br />\n";
        echo '<div>' . __("Note that BeamMeUp may make saving an item take a while, and that it may take additional time for the Internet Archive to post the files after you save.") . '</div>';
        echo "<br />\n";
        echo '<div>' . __("To change the upload default or to alter the upload's configuration, visit the plugin's configuration settings on this site.") . '</div>';
        echo "<br />\n";

        if (metadata($item, 'id') == '') {
            echo '<div>' . __('Please revisit this tab after you save the item to view its Internet Archive links.') . '</div>';
        }
        else {
            echo $this->_listInternetArchiveLinks();
        }

        $ht .= ob_get_contents();
        ob_end_clean();

        return $ht;
    }

    /**
     * @return string containing IA links
     */
    private function _listInternetArchiveLinks()
    {
        return '<div>' . __('If you uploaded the files and the Internet Archive has fully processed them, you can view them <strong><a href="http://archive.org/details/' . beamiaGetBucketName() . '" target="_blank">here</a></strong>') . '</div>'
            . '<br />'
            . '<div>' . __("You can view the upload's Internet Archive history and progress <strong><a href=\"http://archive.org/catalog.php?history=1&identifier=" . beamiaGetBucketName() . '" target="_blank">here</a></strong>.') . '</div>'
            . '<br />';
    }

    /**
     * @return void
     */
    private function _installTable()
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS `{$this->_db->Beammeup_Internetarchive}` (
              `id` int unsigned NOT NULL auto_increment,
              `record_id` int unsigned NOT NULL,
              `record_type` varchar(50) NOT NULL,
              `beammeup` tinyint NOT NULL,
              `is_public` tinyint NOT NULL,
              `settings` text collate utf8_unicode_ci NOT NULL,
              `identifier` varchar(256),
              `status` varchar(255) collate utf8_unicode_ci,
              `infos` text collate utf8_unicode_ci NOT NULL,
              `checked` timestamp NOT NULL default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP,
              PRIMARY KEY  (`id`),
              KEY `record_type_record_id` (`record_type`, `record_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
        ";
        $this->_db->query($sql);
    }
}

/**
 * @return bucket name for Omeka Item
 */
function beamiaGetBucketName($identifier = '')
{
    if (empty($identifier)) {
        $item = get_current_record('item');
        $identifier = $item->id;
    }
    return get_option('beamia_bucket_prefix') . '_' . $identifier;
}
