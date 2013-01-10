<script type="text/javascript">
    jQuery(document).ready(function() {
        $("#BeamiaS3AccessKey").Watermark("Enter S3 access key here.");
        $("#BeamiaS3SecretKey").Watermark("Enter S3 secret key here.");

        jQuery("form").bind("submit", function(event) {
            //cannot use .children() because form has no name or id
            if (jQuery('input[name=BeamiaS3AccessKey]').val().indexOf(' ') != -1 || jQuery('input[name=BeamiaS3AccessKey]').val() == '') {
                alert('Please enter valid secret key.');
            }
            if (jQuery('input[name=BeamiaS3SecretKey]').val().indexOf(' ') != -1 || jQuery('input[name=BeamiaS3SecretKey]').val() == '') {
                alert('Please enter valid access key.');
            }
            if (jQuery('input[name=BeamiaBucketPrefix]').val().indexOf(' ') != -1 || jQuery('input[name=BeamiaBucketPrefix]').val() == '') {
                alert('Please enter valid bucket prefix.');
            }
            else {
                jQuery('input[name=BeamiaBucketPrefix]').val(jQuery('input[name=BeamiaBucketPrefix]').val().toLowerCase()));
            }

            return false;
        });
    });
</script>

<h4>Note that if you are uploading to the Internet Archive, saving an item may take a while. Check item page to see current progress.</h4>

<div class="field">
    <div id="BeamiaPostToInternetArchive_label" class="one columns alpha">
        <?php echo get_view()->formLabel('BeamiaPostToInternetArchive', __('Upload to Internet Archive by default'));?>
    </div>
    <div class="inputs">
        <?php echo get_view()->formCheckbox('BeamiaPostToInternetArchive', true, array('checked' => (boolean) get_option('beamia_post_to_internet_archive')));?>
        <p class="explanation"><?php echo __(
            'You can change this option on a per-item basis.'
        );?></p>
    </div>
</div>
<div class="field">
    <div id="BeamiaIndexAtInternetArchive_label" class="one columns alpha">
        <?php echo get_view()->formLabel('BeamiaIndexAtInternetArchive', __('Index at Internet Archive by default'));?>
    </div>
    <div class="inputs">
        <?php echo get_view()->formCheckbox('BeamiaIndexAtInternetArchive', true, array('checked' => (boolean) get_option('beamia_index_at_internet_archive')));?>
        <p class="explanation">
            <?php echo __("If you index your items, they will appear on the results of search engines such as Google's.");
            echo ' ' . __('You can change this option on a per-item basis.');?>
        </p>
    </div>
</div>
<h4>Please visit <a href="http://www.archive.org/account/s3.php" target="_blank">The Internet Archive's S3 Page</a> to generate the keys below.</h4>
<p>Be sure to log in with the account used for your archives.</p>
<div class="field">
    <?php echo get_view()->formLabel('BeamiaS3AccessKey', __('S3 access key'));?>
    <div class="inputs">
        <?php echo get_view()->formText('BeamiaS3AccessKey', get_option('beamia_S3_access_key'), null);?>
    </div>
</div>
<div class="field">
        <?php echo get_view()->formLabel('BeamiaS3SecretKey', __('S3 secret key'));?>
    <div class="inputs">
        <?php echo get_view()->formText('BeamiaS3SecretKey', get_option('beamia_S3_secret_key'), null);?>
    </div>
</div>
<div class="field">
    <?php echo get_view()->formLabel('BeamiaCollectionName', __('Collection name'));?>
    <div class="inputs">
        <?php echo get_view()->formText('BeamiaCollectionName', get_option('beamia_collection_name'), null);?>
        <p class="explanation">
            <?php echo __('You must contact <a href="mailto:info@archive.org" >info@archive.org</a> and get an Internet Archive Collection to use this plugin. You can use "test_collection".');
            echo ' ' . __('Do not fear. It is free and the Internet Archive is staffed exclusively with friendly and responsive people.');?>
        </p>
    </div>
</div>
<div class="field">
    <?php echo get_view()->formLabel('BeamiaMediaType', __('Media type'));?>
    <div class="inputs">
        <?php echo get_view()->formText('BeamiaMediaType', get_option('beamia_media_type'), null);?>
        <p class="explanation">
            <?php echo __('Ask the Internet Archive what do put here. They will tell you what to enter here when you get your collection.');
            echo ' ' . __('Again, they would love to hear from you so please contact them.') . '<br />' . PHP_EOL;
            echo __('Main types are: "texts", "movies", "audio" and "education".');?>
        </p>
    </div>
</div>
<div class="field">
    <?php echo get_view()->formLabel('BeamiaBucketPrefix', __('Bucket prefix'));?>
    <div class="inputs">
        <?php echo get_view()->formText('BeamiaBucketPrefix', get_option('beamia_bucket_prefix'), null);?>
        <p class="explanation">
            <?php echo __('Bucket prefix is used to build the unique url of each item you beam up to Internet Archive. The url will be this prefix followed by "_" and the item id.');?>
        </p>
    </div>
</div>
<div class="field">
    <div id="BeamiaJobType_label" class="one columns alpha">
        <?php echo get_view()->formLabel('BeamiaJobType', __('Long running job'));?>
    </div>
    <div class="inputs">
        <?php echo get_view()->formCheckbox('BeamiaJobType', true, array('checked' => (get_option('beamia_job_type') == 'long running')));?>
        <p class="explanation"><?php echo __(
            'Check this button if the php-cli of your server supports curl (recommended for large files in particular). '
        );?></p>
    </div>
</div>
<div class="field">
    <?php echo get_view()->formLabel('BeamiaMaxTimeToCheckBucket', __('Maximum time to create a bucket'));?>
    <div class="inputs">
        <?php echo get_view()->formText('BeamiaMaxTimeToCheckBucket', get_option('beamia_max_time_to_check_bucket'), null);?>
        <p class="explanation">
            <?php echo __('Maximum time in seconds to wait for the creation of a bucket (default: 300).');?>
        </p>
    </div>
</div>
<div class="field">
    <?php echo get_view()->formLabel('BeamiaMinTimeBeforeNewCheck', __('Minimum time before a new check'));?>
    <div class="inputs">
        <?php echo get_view()->formText('BeamiaMinTimeBeforeNewCheck', get_option('beamia_min_time_before_new_check'), null);?>
        <p class="explanation">
            <?php echo __('Minimum time in seconds to wait before a new check of an uploaded record (default: 60).');?>
        </p>
    </div>
</div>
<div class="field">
    <?php echo get_view()->formLabel('beamiaMaxSimultaneousProcess', __('Maximum simultaneous records to beam up'));?>
    <div class="inputs">
        <?php echo get_view()->formText('beamiaMaxSimultaneousProcess', get_option('beamia_max_simultaneous_process'), null);?>
        <p class="explanation">
            <?php echo __('Maximum records to process simultaneously (default: 5).');?>
        </p>
    </div>
</div>

<h4>Remarks</h4>
<ul>
    <li>Generally, creation of a bucket takes some seconds, but it can be some minutes and even some hours in case of maintenance.</li>
    <li>Files can't be uploaded as long as bucket creation is not finished. Anyway, they are added in a queue for a background job process.</li>
    <li>If two files has same original name, the second will overide the first in the bucket.</li>
    <li>Status is automatically updated when the item view is displayed and after an item is saved or updated.</li>
</ul>
