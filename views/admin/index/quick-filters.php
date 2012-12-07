<ul class="quick-filter-wrapper">
    <li><a href="#" tabindex="0"><?php
        echo __('Quick Filter'); ?></a>
        <ul class="dropdown">
            <li><span class="quick-filter-heading"><?php
                echo __('Quick Filter') ?></span></li>
            <li><a href="<?php echo url('beam-me-up-to-internet-archive/index/browse'); ?>"><?php
                echo __('View All'); ?></a></li>
            <li><a href="<?php echo url('beam-me-up-to-internet-archive/index/browse', array(
                    'record_type' => 'Item',
                )); ?>"><?php
                echo __('Only items'); ?></a></li>
            <li><a href="<?php echo url('beam-me-up-to-internet-archive/index/browse', array(
                    'record_type' => 'File',
                )); ?>"><?php
                echo __('Only files'); ?></a></li>
            <li><a href="<?php echo url('beam-me-up-to-internet-archive/index/browse', array(
                    'items_featured' => 1,
                )); ?>"><?php
                echo __('Featured items'); ?></a></li>
            <li><a href="<?php echo url('beam-me-up-to-internet-archive/index/browse', array(
                    'items_public' => 1,
                )); ?>"><?php
                echo __('L: %s', __('Public')); ?></a></li>
            <li><a href="<?php echo url('beam-me-up-to-internet-archive/index/browse', array(
                    'items_public' => 0,
                )); ?>"><?php
                echo __('L: %s', __('Private')); ?></a></li>
            <li><a href="<?php echo url('beam-me-up-to-internet-archive/index/browse', array(
                    'status' => BeamInternetArchiveBeam::STATUS_NOT_TO_BEAM_UP,
                )); ?>"><?php
                echo __('L: %s', __('Not to beam up')); ?></a></li>
            <li><a href="<?php echo url('beam-me-up-to-internet-archive/index/browse', array(
                    'status' => BeamInternetArchiveBeam::STATUS_TO_BEAM_UP,
                )); ?>"><?php
                echo __('L: %s', __('To beam up')); ?></a></li>
            <li><a href="<?php echo url('beam-me-up-to-internet-archive/index/browse', array(
                    'status' => BeamInternetArchiveBeam::STATUS_COMPLETED,
                )); ?>"><?php
                echo __('L: %s', __('Completed')); ?></a></li>
            <li><a href="<?php echo url('beam-me-up-to-internet-archive/index/browse', array(
                    'status' => BeamInternetArchiveBeam::STATUS_COMPLETED_WAITING_REMOTE,
                )); ?>"><?php
                echo __('L: %s', __('Waiting IA')); ?></a></li>
            <li><a href="<?php echo url('beam-me-up-to-internet-archive/index/browse', array(
                    'status' => array(
                        BeamInternetArchiveBeam::STATUS_IN_PROGRESS,
                        BeamInternetArchiveBeam::STATUS_UPDATING,
                        BeamInternetArchiveBeam::STATUS_DELETING,
                    ),
                )); ?>"><?php
                echo __('L: %s', __('In progress')); ?></a></li>
            <li><a href="<?php echo url('beam-me-up-to-internet-archive/index/browse', array(
                    'status' => array(
                        BeamInternetArchiveBeam::STATUS_FAILED_TO_BEAM_UP,
                        BeamInternetArchiveBeam::STATUS_ERROR,
                    ),
                )); ?>"><?php
                echo __('L: %s', __('Failed')); ?></a></li>
            <li><a href="<?php echo url('beam-me-up-to-internet-archive/index/browse', array(
                    'status' => BeamInternetArchiveBeam::STATUS_DELETED,
                )); ?>"><?php
                echo __('L: %s', __('Deleted')); ?></a></li>
            <li><a href="<?php echo url('beam-me-up-to-internet-archive/index/browse', array(
                    'public' => BeamInternetArchiveBeam::IS_PUBLIC,
                )); ?>"><?php
                echo __('R: %s', __('Public')); ?></a></li>
            <li><a href="<?php echo url('beam-me-up-to-internet-archive/index/browse', array(
                    'public' => BeamInternetArchiveBeam::IS_PRIVATE,
                )); ?>"><?php
                echo __('R: %s', __('Private')); ?></a></li>
            <li><a href="<?php echo url('beam-me-up-to-internet-archive/index/browse', array(
                    'remote_status' => BeamInternetArchiveBeam::REMOTE_NOT_APPLICABLE,
                )); ?>"><?php
                echo __('R: %s', __('Not applicable')); ?></a></li>
            <li><a href="<?php echo url('beam-me-up-to-internet-archive/index/browse', array(
                    'remote_status' => BeamInternetArchiveBeam::REMOTE_IN_PROGRESS,
                )); ?>"><?php
                echo __('R: %s', __('In progress')); ?></a></li>
            <li><a href="<?php echo url('beam-me-up-to-internet-archive/index/browse', array(
                    'remote_status' => BeamInternetArchiveBeam::REMOTE_READY,
                )); ?>"><?php
                echo __('R: %s', __('Ready')); ?></a></li>
            <li><a href="<?php echo url('beam-me-up-to-internet-archive/index/browse', array(
                    'remote_status' => BeamInternetArchiveBeam::REMOTE_CHECK_FAILED,
                )); ?>"><?php
                echo __('R: %s', __('Check failed')); ?></a></li>
            <li><a href="<?php echo url('beam-me-up-to-internet-archive/index/browse', array(
                    'remote_status' => BeamInternetArchiveBeam::REMOTE_NO_BUCKET,
                )); ?>"><?php
                echo __('R: %s', __('Failed')); ?></a></li>
        </ul>
    </li>
</ul>
