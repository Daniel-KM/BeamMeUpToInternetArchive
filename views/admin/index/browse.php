<?php
queue_js_file('beams-browse');
queue_css_file('beams-browse');
$pageTitle = __('Beam me up to Internet Archive') . ' (' . __('%s found/%s total', $total_results, total_records('BeamInternetArchiveBeams')) . ')';
echo head(array(
    'title' => $pageTitle,
    'bodyclass' => 'beams items browse',
));
echo flash();
echo pagination_links();
?>

<form action="<?php echo html_escape(url('beam-me-up-to-internet-archive/index/batch-edit')); ?>" method="post" accept-charset="utf-8">
    <?php if ($total_results): ?>
    <div class="table-actions batch-edit-option">
        <!-- <input type="submit" class="beams edit-items small blue batch-action button" name="submit-batch-beam-items" value="<?php echo __('Beam items up'); ?>" /> -->
    </div>
    <?php endif; ?>

    <?php echo common('quick-filters', array(), 'index'); ?>

    <?php if ($total_results): ?>
    <table id="items" cellspacing="0" cellpadding="0">
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
            $browseHeadings[__('Local Status')] = 'status';
            $browseHeadings[__('Remote Status')] = 'remote';
            $browseHeadings[__('Date Checked')] = 'remote_checked';
            echo browse_sort_links($browseHeadings, array('link_tag' => 'th scope="col"', 'list_tag' => ''));
            ?>
        </tr>
    </thead>
    <tbody>
        <?php foreach (loop('BeamInternetArchiveBeam') as $beam):
            // Update status.
            $beam->checkRemoteStatus();

            $record = get_record_by_id($beam->record_type, $beam->record_id);
            $id = $beam->id;
            if ($beam->isBeamForItem()):
                $item = $record; ?>
        <tr class="beam odd">
            <td class="batch-beam-check" scope="row"><input type="checkbox" name="beams[]" value="<?php echo $id; ?>" /></td>
            <td><?php echo $item->id; ?></td>
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
                    <li><?php echo link_to_beamia(__('Beam me up')); ?></li>
                </ul>
            </td>
            <td><?php echo strip_formatting(metadata($item, array('Dublin Core', 'Creator'))); ?></td>
            <td>
                <?php echo ($typeName = metadata($item, 'Item Type Name')) ?
                        $typeName :
                        metadata($item, array('Dublin Core', 'Type'), array('snippet' => 35)); ?>
            </td>
            <?php elseif ($beam->isBeamForFile()):
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
                    <li><?php echo link_to_beamia(__('Beam me up')); ?></li>
                </ul>
            </td>
            <td><?php echo strip_formatting(metadata($file, array('Dublin Core', 'Creator'))); ?></td>
            <td>
                <?php echo $file->mime_type; ?>
            </td>
            <?php endif; ?>

            <td><?php echo ($beam->isPublic() ? __('Public') : __('Private')); ?></td>
            <td><?php echo link_to_beamia_remote_if_any(__($beam->status), array(), $beam); ?></td>
            <td><?php echo link_to_beamia_tasks_if_any(__($beam->remote_status), array(), $beam); ?></td>
            <td><?php echo ($beam->isRemoteChecked() ? __('N/A') : format_date($beam->remote_checked)); ?></td>
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
    <?php if (total_records('BeamInternetArchiveBeams') === 0): ?>
    <h4><?php echo __('No item have been created or uploaded.'); ?></h4>
    <p><?php echo __('Get started by adding your first item.'); ?></p>
    <a href="<?php echo html_escape(url('items/add')); ?>" class="add big green button"><?php echo __('Add an Item'); ?></a>
    <?php else: ?>
    <h4><?php echo __('No record found.'); ?></h4>
    <p><?php echo __('The query searched %s items and returned no results.', total_records('BeamInternetArchiveBeams')); ?></p>
    <p><?php echo __('Choose another filter.'); ?></p>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php echo foot(); ?>
