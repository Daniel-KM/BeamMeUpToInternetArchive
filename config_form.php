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

<h4>Note that if you are uploading to the Internet Archive, saving an item may take a while.</h4>

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
            <?php echo __('Ask the Internet Archive what do put here, for example "texts". They will tell you what to enter here when you get your collection.');
            echo ' ' . __('Again, they would love to hear from you so please contact them.');?>
        </p>
    </div>
</div>
<div>
    <h2><?php echo __('Bucket prefix') . ': "<em>' . get_option('beamia_bucket_prefix') . '</em>"' ;?></h2>
    <p>
    <?php echo __('Bucket prefix is used to build the url of each item you beam up to Internet Archive. The url will be this prefix followed by "_" and the item id.');?>
    <br />
    <?php echo __(' You may have changed it in plugin.ini before installation of the plugin.');?>
    </p>
    <p>
    <strong><?php echo __('WARNING:');?></strong>
    <br />
    <?php echo __('With the current version of BeamMeUp, you cannot change it once it has been defined and you started to upload items on Internet Archive.');?>
    </p>
</div>
