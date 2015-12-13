<?php
queue_js_file('beams-browse');
queue_css_file('beams-browse');
$pageTitle = __('Beam me up to Internet Archive') . ' (' . __('%s found/%s total', $total_results, total_records('BeamInternetArchiveRecords')) . ')';
echo head(array(
    'title' => $pageTitle,
    'bodyclass' => 'beams items browse',
));
echo flash();
echo pagination_links();
?>

<form action="<?php echo html_escape(url('beam-me-up-to-internet-archive/index/batch-queue')); ?>" method="post" accept-charset="utf-8">
    <div class="table-actions">
        <input type="submit" class="beams edit-items small green button" name="submit-batch-queue" value="<?php echo __('Launch %d queued tasks', get_option('beamia_max_simultaneous_process')); ?>" />
        <input type="submit" class="beams edit-items small green button" name="submit-batch-queue-failed" value="<?php echo __('Relaunch %d failed tasks', get_option('beamia_max_simultaneous_process')); ?>" />
    </div>
</form>
<form action="<?php echo html_escape(url('beam-me-up-to-internet-archive/index/batch-edit')); ?>" method="post" accept-charset="utf-8">
    <?php if ($total_results): ?>
    <div class="table-actions batch-edit-option">
        <input type="submit" class="beams edit-items small blue batch-action button" name="submit-batch-beam-up" value="<?php echo __('Beam up/Update'); ?>" />
        <input type="submit" class="beams edit-items small red batch-action button" name="submit-batch-remove" value="<?php echo __('Remove'); ?>" />
    </div>
    <?php endif; ?>

    <?php echo common('quick-filters', array(), 'index'); ?>

    <?php if ($total_results): ?>
    <table id="items">
    <thead>
        <tr>
            <th class="batch-edit-heading"><?php echo __('Select'); ?></th>
            <?php
            $browseHeadings[__('N° Item')] = null;
            $browseHeadings[__('Record')] = 'record_type';
            $browseHeadings[__('Title')] = 'Dublin Core,Title';
            $browseHeadings[__('Creator')] = 'Dublin Core,Creator';
            $browseHeadings[__('Type')] = null;
            $browseHeadings[__('Index')] = 'public';
            $browseHeadings[__('Status')] = 'status';
            $browseHeadings[__('Process')] = 'process';
            $browseHeadings[__('Date Checked')] = 'remote_checked';
            echo browse_sort_links($browseHeadings, array('link_tag' => 'th scope="col"', 'list_tag' => ''));
            ?>
        </tr>
    </thead>
    <tbody>
        <?php foreach (loop('BeamInternetArchiveRecord') as $beam):
            // Update status if needed.
            if (!$beam->isRemoteChecked() || (time() - strtotime($beam->remote_checked)) >  get_option('beamia_min_time_before_new_check')) {
                $beam->checkRemoteStatus();
            }
            $id = $beam->id;
            $pendingTasks = $beam->hasPendingTasks();
            $record = get_record_by_id($beam->record_type, $beam->record_id);
            if (!$beam->hasRecord()) { ?>
        <tr class="beam red">
            <td class="batch-beam-check" scope="row"><input type="checkbox" name="beams[]" value="<?php echo $id; ?>" /></td>
            <td><?php echo $beam->isBeamForItem() ? $beam->record_id : beamia_getRequiredRecordIdFromBeam($beam); ?></td>
            <td><?php echo $beam->record_type; ?></td>
            <td class="item-info">
                <?php echo __('%s #%d removed from Omeka.', $beam->record_type, $beam->record_id); ?>
                <ul class="action-links group">
                    <?php echo beamia_listActions($beam); ?>
                </ul>
            </td>
            <td></td>
            <td></td>
            <?php } elseif ($beam->isBeamForItem()) {
                $item = $record; ?>
        <tr class="beam odd">
            <td class="batch-beam-check" scope="row"><input type="checkbox" name="beams[]" value="<?php echo $id; ?>" /></td>
            <td><?php echo $beam->record_id; ?></td>
            <td><?php echo $beam->record_type; ?></td>
                <?php if ($item->featured): ?>
            <td class="item-info featured">
                <?php else: ?>
            <td class="item-info">
                <?php endif; ?>
                <?php if (metadata($item, 'has files')): ?>
                <?php echo link_to_item(item_image('square_thumbnail', array(), 0, $item), array('class' => 'item-thumbnail'), 'show', $item); ?>
                <?php endif; ?>
                <span class="title">
                <?php echo link_to_item(null, array(), 'show', $item); ?>
                <?php if(!$item->public): ?>
                <?php echo '(' . __('Private') . ')'; ?>
                <?php endif; ?>
                </span>
                <ul class="action-links group">
                <?php echo beamia_listActions($beam); ?>
                </ul>
            </td>
            <td><?php echo strip_formatting(metadata($item, array('Dublin Core', 'Creator'))); ?></td>
            <td>
                <?php echo ($typeName = metadata($item, 'Item Type Name')) ?
                        $typeName :
                        metadata($item, array('Dublin Core', 'Type'), array('snippet' => 35)); ?>
            </td>
            <?php } elseif ($beam->isBeamForFile()) {
                $file = $record; ?>
        <tr class="beam even">
            <td class="batch-beam-check" scope="row"><input type="checkbox" name="beams[]" value="<?php echo $id; ?>" /></td>
            <td><?php echo $file->item_id; ?></td>
            <td><?php echo $beam->record_type; ?></td>
            <td class="file-info">
                    <?php echo link_to_file_show(array('class' => 'item-thumbnail'), file_image('square_thumbnail', array(), $file), $file); ?>
                    <span class="title">
                    <?php echo link_to_file_show(array(), null, $file); ?>
                    </span>
                <ul class="action-links group">
                    <?php echo beamia_listActions($beam); ?>
                </ul>
            </td>
            <td><?php echo strip_formatting(metadata($file, array('Dublin Core', 'Creator'))); ?></td>
            <td>
                <?php echo $file->mime_type; ?>
            </td>
                <?php } ?>
            <td><?php echo ($beam->isPublic() ? __('Public') : __('Private')); ?></td>
            <td><?php echo ($beam->hasUrl()) ?
                        beamia_link_to_remote_if_any(__($beam->status), array(), $beam) :
                        __($beam->status);
            ?></td>
            <td><?php echo ($beam->hasUrl()) ?
                        beamia_link_to_tasks_if_any(__($beam->process), array(), $beam) :
                        __($beam->process);
                    $progressInfo = beamia_getProgress($beam);
                    if (!empty($progressInfo) && $progressInfo['total'] > 0) {
                        echo '<div class="progress">' . __('Progress: %d%% of %d bytes.', $progressInfo['progress'], $progressInfo['total']) . '</div>';
                    }
            ?></td>
            <td><?php echo ($beam->isRemoteChecked() ? $beam->remote_checked : __('N/A')); ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
    </table>

    <div class="table-actions batch-edit-option">
        <input type="submit" class="beams edit-items small blue batch-action button" name="submit-batch-beam-items" value="<?php echo __('Beam items up'); ?>" />
    </div>

    <?php echo common('quick-filters', array(), 'index'); ?>
    <?php endif; ?>
</form>

<?php echo pagination_links(); ?>

<?php if ($total_results): ?>
<script type="text/javascript">
Omeka.addReadyCallback(Omeka.BeamsBrowse.setupBatchEdit);
</script>

<?php else: ?>
<br />
<br />
<div>
    <?php if (total_records('BeamInternetArchiveRecords') === 0): ?>
    <h4><?php echo __('No item have been created or uploaded.'); ?></h4>
    <p><?php echo __('Get started by adding your first item.'); ?></p>
    <a href="<?php echo html_escape(url('items/add')); ?>" class="add big green button"><?php echo __('Add an Item'); ?></a>
    <?php else: ?>
    <h4><?php echo __('No record found.'); ?></h4>
    <p><?php echo __('The query searched %s items and returned no results.', total_records('BeamInternetArchiveRecords')); ?></p>
    <p><?php echo __('Choose another filter.'); ?></p>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php echo foot(); ?>
